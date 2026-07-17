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
 * Ответ экстрактора; категория «Трактор» и локация «г.Шымкент» заводятся в
 * справочники, потому что коллектор принимает значения только из них.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fullExtraction(array $overrides = []): array
{
    categoryNamed('Трактор');
    locationNamed('г.Шымкент');

    return array_merge([
        'type' => 'equipment',
        'category' => 'Трактор',
        'brand' => null,
        'description' => 'Трактор в аренду с водителем',
        'location' => 'Шымкент',
        'location_detail' => null,
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
        ->category->name->toBe('Трактор')
        ->location->name->toBe('г.Шымкент')
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
        ->category->name->toBe('Трактор')
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

    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, supplierAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'media-1'));

    $media = ListingMedia::sole();

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($media->type)->toBe(ListingMediaType::Audio)
        ->and($media->transcription)->toBe('Сдаю трактор в Шымкенте, десять тысяч тенге в час')
        ->and($media->listing_id)->toBe(Listing::sole()->id);

    Storage::disk('public')->assertExists($media->path);
});

test('an undownloadable voice message asks to rephrase without spending an attempt', function () {
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-403')
        ->andThrow(new RuntimeException('403 Медиа принадлежит другой компании'));

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));

    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, supplierAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'media-403'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(0)
        ->and(Listing::count())->toBe(0);
    ListingExtractionAgent::assertNeverPrompted();
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

test('a category outside the dictionary never reaches the draft and is asked again', function () {
    ListingExtractionAgent::fake([
        fullExtraction(['category' => 'Дирижабль', 'clarifying_question' => 'Что именно за техника?']),
    ]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Что именно за техника?');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю дирижабль в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['fields']['category'])->toBeNull()
        ->and(Listing::count())->toBe(0);
});

test('the extractor category is normalized to the dictionary spelling', function () {
    ListingExtractionAgent::fake([fullExtraction(['category' => 'трактор'])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect(Listing::sole())->category->name->toBe('Трактор');
});

test('the extraction schema and prompt hard-limit the category to the dictionary', function () {
    $agent = new ListingExtractionAgent(null, ['Автокран', 'Сварщик']);

    $categorySchema = $agent->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory)['category']->toArray();

    expect($categorySchema['enum'])->toContain('Автокран')->toContain('Сварщик')
        ->and((string) $agent->instructions())->toContain('- Автокран')->toContain('- Сварщик');
});

test('a named brand from the dictionary lands on the draft and in the summary', function () {
    brandNamed('Hitachi');
    ListingExtractionAgent::fake([fullExtraction(['brand' => 'Hitachi', 'summary' => null])]);
    $session = collectorSession();

    // Пустой summary включает сводку-фолбэк — она должна содержать марку.
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Трактор Hitachi'));

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор Хитачи в Шымкенте, 10000 тг/час'));

    expect(Listing::sole())->brand->name->toBe('Hitachi');
});

test('the extractor brand is normalized to the dictionary spelling', function () {
    brandNamed('Hitachi');
    ListingExtractionAgent::fake([fullExtraction(['brand' => 'hitachi'])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор hitachi в Шымкенте, 10000 тг/час'));

    expect(Listing::sole())->brand->name->toBe('Hitachi');
});

test('a brand outside the dictionary is dropped without a clarifying question', function () {
    ListingExtractionAgent::fake([fullExtraction(['brand' => 'Цеппелин'])]);
    $session = collectorSession();

    // Марка необязательна: подтверждение отправляется сразу, попытки не тратятся.
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор Цеппелин в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(0)
        ->and(Listing::sole()->brand_id)->toBeNull();
});

test('a service listing never carries a brand even when the extractor returned one', function () {
    brandNamed('Hitachi');
    categoryNamed('Сварщик', ListingType::Service);
    ListingExtractionAgent::fake([
        fullExtraction(['type' => null, 'category' => 'Сварщик', 'brand' => 'Hitachi', 'summary' => 'Сварщик, Шымкент']),
    ]);
    $session = collectorSession(['listing_type' => null]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    $node = ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'];
    app(SupplierListingCollector::class)
        ->resume($session, $node, new InboundMessage(text: 'Сварщик с выездом, Шымкент, 10000 тг/час'));

    expect(Listing::sole())
        ->type->toBe(ListingType::Service)
        ->brand_id->toBeNull();
});

test('the extraction schema and prompt hard-limit the brand to the dictionary', function () {
    $agent = new ListingExtractionAgent(null, ['Автокран'], ['Hitachi', 'CAT']);

    $brandSchema = $agent->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory)['brand']->toArray();

    expect($brandSchema['enum'])->toContain('Hitachi')->toContain('CAT')
        ->and((string) $agent->instructions())->toContain('- Hitachi')->toContain('- CAT');
});

test('an empty brand dictionary degrades the schema and tells the model to keep null', function () {
    $agent = new ListingExtractionAgent(null, ['Автокран']);

    $brandSchema = $agent->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory)['brand']->toArray();

    expect($brandSchema)->not->toHaveKey('enum')
        ->and((string) $agent->instructions())->toContain('справочник марок пуст');
});

test('an undetermined type in the auto branch asks about it instead of defaulting to equipment', function () {
    // Без категории тип вывести не из чего: категория определила бы тип сама.
    ListingExtractionAgent::fake([
        fullExtraction(['type' => null, 'category' => null, 'clarifying_question' => 'Какая цена?']),
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

test('in the auto branch a resolved category determines the type without asking', function () {
    categoryNamed('Сварщик', ListingType::Service);
    ListingExtractionAgent::fake([
        fullExtraction(['type' => null, 'category' => 'Сварщик', 'summary' => 'Сварщик, Шымкент, 10000 тг/час']),
    ]);
    $session = collectorSession(['listing_type' => null]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    $node = ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'];
    $outcome = app(SupplierListingCollector::class)
        ->resume($session, $node, new InboundMessage(text: 'Сварщик с выездом, Шымкент, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and(Listing::sole())
        ->type->toBe(ListingType::Service)
        ->category->name->toBe('Сварщик');
});

test('a fixed-type branch does not accept a category of the other type', function () {
    categoryNamed('Сварщик', ListingType::Service);
    ListingExtractionAgent::fake([
        fullExtraction(['category' => 'Сварщик', 'clarifying_question' => 'Что именно за техника?']),
    ]);
    $session = collectorSession(); // ветка «техника»

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Что именно за техника?');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Предлагаю услуги сварщика в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['fields']['category'])->toBeNull()
        ->and(Listing::count())->toBe(0);
});

test('an ambiguous location sends a pick list without spending an attempt', function () {
    $abai = locationNamed('область Абай');
    $abaiDistrict = locationNamed('Абайский район', $abai);
    $shymkentDistrict = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendList')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, array $rows) => str_contains($text, 'уточните')
            && count($rows) === 2
            && collect($rows)->pluck('id')->contains('listing_location:'.$abaiDistrict->id)
            && collect($rows)->pluck('id')->contains('listing_location:'.$shymkentDistrict->id));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('locating')
        ->and($session->fresh()->state['attempts'])->toBe(0);
});

test('picking a location from the list resolves it and continues to confirmation', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));

    $outcome = $collector->resume(
        $session->fresh(),
        supplierAiNode(),
        new InboundMessage(replyId: 'listing_location:'.$picked->id),
    );

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and(Listing::sole()->location_id)->toBe($picked->id);
});

test('a location outside the dictionary is asked again with the not-found hint', function () {
    ListingExtractionAgent::fake([fullExtraction(['location' => 'Хогвартс'])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не нашли «Хогвартс»'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Хогвартс, 10000 тг/час'));

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
