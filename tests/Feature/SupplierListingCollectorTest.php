<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Enums\AiOutcome;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Ai\SupplierListingCollector;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function supplierAiNode(): array
{
    return ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing', 'listing_type' => 'equipment'];
}

/**
 * @param  array<string, mixed>  $state
 */
function collectorSession(array $state = []): BotSession
{
    return BotSession::factory()->waitingAt('collect')->create([
        'state' => array_merge([
            'phase' => 'collecting',
            'attempts' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'listing_type' => 'equipment',
        ], $state),
    ]);
}

function fakeCollectorMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fullExtraction(array $overrides = []): array
{
    return array_merge([
        'type' => 'equipment',
        'category' => 'Трактор',
        'description' => 'Трактор в аренду с водителем',
        'location' => 'Шымкент',
        'price' => '10000 тг/час',
        'clarifying_question' => '',
        'summary' => 'Трактор, Шымкент, 10000 тг/ч',
    ], $overrides);
}

test('entering the AI block greets the supplier and keeps the turn', function () {
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $to->is($session->contact) && str_contains($text, 'Расскажите'));

    $outcome = app(SupplierListingCollector::class)->start($session, supplierAiNode());

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('collecting');
});

test('a complete description creates a draft and asks for confirmation', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => str_contains($text, 'Трактор, Шымкент, 10000 тг/ч')
            && str_contains($text, 'Всё верно?')
            && array_column($buttons, 'title') === ['Да, отправить', 'Исправить']
            // Лимит WhatsApp: заголовок кнопки длиннее 20 символов Meta отклоняет асинхронно.
            && collect($buttons)->every(fn (array $button): bool => mb_strlen($button['title']) <= 20));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming')
        ->and(Listing::sole())
        ->contact_id->toBe($session->contact_id)
        ->status->toBe(ListingStatus::Draft)
        ->type->toBe(ListingType::Equipment)
        ->category->toBe('Трактор')
        ->location->toBe('Шымкент')
        ->price->toBe('10000 тг/час');
});

test('missing data triggers the clarifying question suggested by the extractor', function () {
    ListingExtractionAgent::fake([
        fullExtraction(['price' => null, 'clarifying_question' => 'Какая цена или тариф за смену?']),
    ]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Какая цена или тариф за смену?');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1)
        ->and(Listing::count())->toBe(0);
});

test('exhausting the clarification limit saves the partial draft and hands off to the web form', function () {
    ListingExtractionAgent::fake([fullExtraction(['price' => null])]);
    $session = collectorSession(['attempts' => 3, 'transcript' => ['Сдаю трактор в Шымкенте']]);

    fakeCollectorMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => str_contains($text, 'заполните объявление вручную')
            && mb_strlen($button) <= 20
            && str_contains($url, '/supplier/listings/')
            && str_contains($url, 'signature='));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'не знаю'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(Listing::sole())
        ->status->toBe(ListingStatus::Draft)
        ->category->toBe('Трактор')
        ->price->toBeNull();
});

test('the submit button sends the confirmed draft to moderation', function () {
    $draft = Listing::factory()->create();
    $session = collectorSession(['phase' => 'confirming', 'draft_id' => $draft->id]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'отправлено на проверку'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and($draft->fresh()->status)->toBe(ListingStatus::PendingModeration);
});

test('the edit button sends the signed web link and finishes the collection', function () {
    $draft = Listing::factory()->create();
    $session = collectorSession(['phase' => 'confirming', 'draft_id' => $draft->id]);

    fakeCollectorMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => mb_strlen($button) <= 20
            && str_contains($url, "/supplier/listings/{$draft->id}/edit")
            && str_contains($url, 'signature='));

    // Текстовый ответ, совпадающий с названием кнопки, приравнен к нажатию.
    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'исправить'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and($draft->fresh()->status)->toBe(ListingStatus::Draft);
});

test('extra details during confirmation are re-extracted and confirmed again', function () {
    ListingExtractionAgent::fake([
        fullExtraction(['price' => '12000 тг/час', 'summary' => 'Трактор, Шымкент, 12000 тг/ч']),
    ]);
    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
    ]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, '12000'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Цена теперь 12000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming')
        ->and($draft->fresh()->price)->toBe('12000 тг/час');
});

test('a voice message is stored, transcribed and used for extraction', function () {
    Storage::fake('public');
    Transcription::fake(['Сдаю трактор в Шымкенте, десять тысяч тенге в час']);
    ListingExtractionAgent::fake([fullExtraction()]);

    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-1')
        ->andReturn(['contents' => 'OGG-BYTES', 'mime_type' => 'audio/ogg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'media-1'));

    $media = ListingMedia::sole();

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($media->type)->toBe(ListingMediaType::Audio)
        ->and($media->transcription)->toBe('Сдаю трактор в Шымкенте, десять тысяч тенге в час')
        ->and($media->listing_id)->toBe(Listing::sole()->id);

    Storage::disk('public')->assertExists($media->path);
});

test('a photo without a caption still runs the extraction with the image attached', function () {
    Storage::fake('public');
    ListingExtractionAgent::fake([fullExtraction()]);

    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-3')
        ->andReturn(['contents' => 'JPEG-BYTES', 'mime_type' => 'image/jpeg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    $outcome = app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-3',
    ));

    expect($outcome)->toBe(AiOutcome::InProgress);
    ListingExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->attachments->count() === 1
            && str_contains((string) $prompt->prompt, 'только фотографии'),
    );
});

test('photos are attached to the extraction alongside the caption text', function () {
    Storage::fake('public');
    ListingExtractionAgent::fake([fullExtraction()]);

    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-4')
        ->andReturn(['contents' => 'JPEG-BYTES', 'mime_type' => 'image/jpeg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        text: 'Сдаю трактор в Шымкенте, 10000 тг/час',
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-4',
    ));

    ListingExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->attachments->count() === 1
            && $prompt->contains('Сдаю трактор'),
    );
});

test('an unreadable follow-up does not spend a clarification attempt', function () {
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $session = collectorSession(['attempts' => 1, 'transcript' => ['Сдаю трактор в Шымкенте']]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage());

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1);
    ListingExtractionAgent::assertNeverPrompted();
});

test('an undetermined type in the auto branch asks about it instead of defaulting to equipment', function () {
    ListingExtractionAgent::fake([
        fullExtraction(['type' => null, 'clarifying_question' => 'Какая цена?']),
    ]);
    $session = collectorSession(['listing_type' => null]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'технику в аренду или услугу'));

    $node = ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'];
    $outcome = app(SupplierListingCollector::class)
        ->resume($session, $node, new InboundMessage(text: 'Сдаю в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1)
        ->and(Listing::count())->toBe(0);
});

test('a photo with a caption is attached to the draft and the caption is extracted', function () {
    Storage::fake('public');
    ListingExtractionAgent::fake([fullExtraction()]);

    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-2')
        ->andReturn(['contents' => 'JPEG-BYTES', 'mime_type' => 'image/jpeg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    $outcome = app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        text: 'Сдаю трактор в Шымкенте, 10000 тг/час',
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-2',
    ));

    $media = ListingMedia::sole();

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($media->type)->toBe(ListingMediaType::Photo)
        ->and($media->transcription)->toBeNull();

    Storage::disk('public')->assertExists($media->path);
});

test('an unusable message asks the supplier to describe the offer without spending an attempt', function () {
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage());

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(0)
        ->and(Listing::count())->toBe(0);
});

test('the scenario assistant clears the working memory once the AI releases the contact', function () {
    $draft = Listing::factory()->create();
    $session = collectorSession(['phase' => 'confirming', 'draft_id' => $draft->id]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once();

    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, supplierAiNode(), new InboundMessage(replyId: SupplierListingCollector::BUTTON_SUBMIT));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and($session->fresh()->state)->toBeNull();
});
