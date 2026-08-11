<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\LocationChoiceAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotReplyKey;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Models\AiOperation;
use App\Models\BotReplyText;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Ai\SupplierListingCollector;
use App\Services\Bot\BotReplyTexts;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function supplierAiNode(): array
{
    return ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'];
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
        ], $state),
    ]);
}

function fakeCollectorMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

/**
 * Ответ агента, разбирающего одноимённые места. По умолчанию — «не уверен»,
 * и поставщику уходит список; замыкание отвечает на любое число вызовов.
 */
function fakeLocationChoice(?int $id = null): void
{
    LocationChoiceAgent::fake(fn (): array => ['location_id' => $id === null ? null : (string) $id]);
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
        'title' => 'Аренда трактора с водителем',
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

test('the AI block sends the operator text instead of the built-in greeting', function () {
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Что сдаёте? Напишите или наговорите.');

    app(SupplierListingCollector::class)->start(
        $session,
        supplierAiNode() + ['text' => 'Что сдаёте? Напишите или наговорите.'],
    );
});

test('an empty AI block text keeps the built-in greeting', function () {
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Расскажите'));

    app(SupplierListingCollector::class)->start($session, supplierAiNode() + ['text' => '   ']);
});

test('a complete description creates a draft and asks for confirmation', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => str_contains($text, 'Название: Аренда трактора с водителем')
            && str_contains($text, 'Трактор, Шымкент, 10000 тг/ч')
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
        ->title->toBe('Аренда трактора с водителем')
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

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => str_contains(
        (string) ($prompt->providerOptions['prompt'] ?? ''), 'русском или казахском',
    ));

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

test('the extractor sees the bot\'s last question as context for a short reply', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession(['last_question' => 'Какая цена или тариф?', 'transcript' => ['Сдаю трактор']]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(text: 'не надо'));

    // Короткий ответ («не надо») читается только против вопроса, на который
    // отвечает, — без вопроса бота в промпте модель гадает вслепую и может
    // принять отказ от просьбы за отказ от размещения.
    ListingExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->contains('Какая цена или тариф?') && $prompt->contains('не надо'),
    );
});

test('during confirmation the extractor knows the summary and the photo ask were shown', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession(['phase' => 'confirming', 'transcript' => ['Сдаю трактор'], 'fields' => fullExtraction()]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(text: 'не надо'));

    ListingExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->contains('сводку') && $prompt->contains('фотографии'),
    );
});

test('an undownloadable photo asks to rephrase without spending an attempt', function () {
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-403')
        ->andThrow(new RuntimeException('403 Медиа принадлежит другой компании'));

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(mediaType: ListingMediaType::Photo, mediaId: 'media-403'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(0)
        ->and(Listing::count())->toBe(0);
    ListingExtractionAgent::assertNeverPrompted();
});

test('a failed photo download still feeds the caption to the extraction', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-403')
        ->andThrow(new RuntimeException('403 Медиа принадлежит другой компании'));

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $outcome = app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        text: 'Сдаю трактор в Шымкенте, 10000 тг/час',
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-403',
    ));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['unreadable'])->toBe(0)
        ->and(ListingMedia::count())->toBe(0);
    ListingExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->attachments->count() === 0 && $prompt->contains('Сдаю трактор'),
    );
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

test('a caption-less photo is never treated as leaving the block', function () {
    Storage::fake('public');
    // Транскрипт пуст: модели уходит наш собственный синтетический промпт
    // («поставщик прислал только фотографии»), и классифицировать в нём
    // нечего — слов поставщика на этом ходе не было вовсе. Ответ модели
    // про намерение здесь не должен завершать блок на фото без подписи.
    ListingExtractionAgent::fake([fullExtraction(['user_intent' => 'abandoned'])]);

    $session = collectorSession();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-5')
        ->andReturn(['contents' => 'JPEG-BYTES', 'mime_type' => 'image/jpeg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $outcome = app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-5',
    ));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming');
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

test('the confirmation asks for photos when the draft has none', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession();

    // Просьба не заменяет подтверждение и не мешает его нажать: объявление
    // без фотографий уходит на модерацию так же, как и с ними.
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => str_contains($text, 'Фотографий пока нет')
            && str_contains($text, 'Всё верно?')
            && array_column($buttons, 'title') === ['Да, отправить', 'Исправить']);

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming');
});

test('the confirmation does not ask for photos when the draft already has one', function () {
    ListingExtractionAgent::fake([fullExtraction()]);

    $draft = Listing::factory()->create();
    ListingMedia::factory()->create(['listing_id' => $draft->id]);
    $session = collectorSession(['draft_id' => $draft->id]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?')
            && ! str_contains($text, 'Фотографий пока нет'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a photo sent at confirmation drops the request from the repeated summary', function () {
    Storage::fake('public');
    ListingExtractionAgent::fake([fullExtraction()]);

    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
        'fields' => fullExtraction(),
    ]);

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('media-6')
        ->andReturn(['contents' => 'JPEG-BYTES', 'mime_type' => 'image/jpeg']);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?')
            && ! str_contains($text, 'Фотографий пока нет'));

    $outcome = app(SupplierListingCollector::class)->resume($session, supplierAiNode(), new InboundMessage(
        mediaType: ListingMediaType::Photo,
        mediaId: 'media-6',
    ));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($draft->photos()->count())->toBe(1);
});

test('an unreadable follow-up does not spend a clarification attempt', function () {
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $session = collectorSession(['attempts' => 1, 'transcript' => ['Сдаю трактор в Шымкенте']]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage);

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1);
    ListingExtractionAgent::assertNeverPrompted();
});

test('three unreadable messages in a row hand the supplier over to the web form', function () {
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $session = collectorSession(['unreadable' => 2, 'transcript' => ['Сдаю трактор']]);

    fakeCollectorMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => mb_strlen($button) <= 20
            && str_contains($url, '/supplier/listings/')
            && str_contains($url, 'signature='));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage);

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(Listing::count())->toBe(1);
    ListingExtractionAgent::assertNeverPrompted();
});

test('a readable message resets the unreadable streak', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession(['unreadable' => 2]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($session->fresh()->state['unreadable'])->toBe(0);
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
    $agent = new ListingExtractionAgent(ListingKind::Rental, ['Автокран', 'Экскаватор']);

    $categorySchema = $agent->schema(new JsonSchemaTypeFactory)['category']->toArray();

    expect($categorySchema['enum'])->toContain('Автокран')->toContain('Экскаватор')
        ->and((string) $agent->instructions())->toContain('- Автокран')->toContain('- Экскаватор');
});

test('the location choice schema and prompt hard-limit the answer to the offered places', function () {
    $agent = new LocationChoiceAgent([
        18385 => 'Абайский район, область Абай',
        26158 => 'Абайский район, Карагандинская область',
    ]);

    $schema = $agent->schema(new JsonSchemaTypeFactory)['location_id']->toArray();

    expect($schema['enum'])->toBe(['18385', '26158'])
        ->and((string) $agent->instructions())
        ->toContain('- 18385: Абайский район, область Абай')
        ->toContain('- 26158: Абайский район, Карагандинская область')
        // Модель обязана отказаться, а не угадать.
        ->toContain('верни null');
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

test('the extraction schema and prompt hard-limit the brand to the dictionary', function () {
    $agent = new ListingExtractionAgent(ListingKind::Rental, ['Автокран'], ['Hitachi', 'CAT']);

    $brandSchema = $agent->schema(new JsonSchemaTypeFactory)['brand']->toArray();

    expect($brandSchema['enum'])->toContain('Hitachi')->toContain('CAT')
        ->and((string) $agent->instructions())->toContain('- Hitachi')->toContain('- CAT');
});

test('an empty brand dictionary degrades the schema and tells the model to keep null', function () {
    $agent = new ListingExtractionAgent(ListingKind::Rental, ['Автокран']);

    $brandSchema = $agent->schema(new JsonSchemaTypeFactory)['brand']->toArray();

    expect($brandSchema)->not->toHaveKey('enum')
        ->and((string) $agent->instructions())->toContain('справочник марок пуст');
});

test('схема извлечения собирается из вида', function () {
    $schema = new JsonSchemaTypeFactory;

    $rental = array_keys((new ListingExtractionAgent(ListingKind::Rental, ['Автокран'], ['Hitachi']))->schema($schema));
    $repair = array_keys((new ListingExtractionAgent(ListingKind::Repair))->schema($schema));
    $driver = array_keys((new ListingExtractionAgent(ListingKind::Driver, ['Экскаватор']))->schema($schema));

    expect($rental)->toContain('category', 'brand', 'price')->not->toContain('services', 'licence_type')
        ->and($repair)->toContain('person_name', 'services', 'repair_place', 'price')->not->toContain('category', 'brand')
        ->and($driver)->toContain('person_name', 'machine_categories', 'licence_type', 'experience_years', 'travels_to_other_cities')
        ->not->toContain('price', 'brand');
});

test('a missing title never blocks confirmation and never spends an attempt', function () {
    ListingExtractionAgent::fake([fullExtraction(['title' => null])]);
    $session = collectorSession();

    // Название составляет сама модель: коллектор не считает его обязательным
    // и вопросов про него не задаёт — черновик уходит на подтверждение без него.
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(0)
        ->and(Listing::sole()->title)->toBeNull();
});

test('newlines and space runs in the extracted title are normalized before saving', function () {
    // Название уходит в параметры шаблонов WhatsApp, где Meta отклоняет
    // переводы строк и серии пробелов — храним уже нормализованным.
    ListingExtractionAgent::fake([fullExtraction(['title' => "Аренда\nкрана   25 т"])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю кран в Шымкенте, 10000 тг/час'));

    expect(Listing::sole()->title)->toBe('Аренда крана 25 т');
});

test('an overlong extracted title is clipped to the column limit before saving', function () {
    ListingExtractionAgent::fake([fullExtraction(['title' => str_repeat('А', 300)])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect(mb_strlen(Listing::sole()->title))->toBe(255);
});

test('the extraction schema carries a nullable title the model composes itself', function () {
    $agent = new ListingExtractionAgent(ListingKind::Rental, ['Автокран']);

    $titleSchema = $agent->schema(new JsonSchemaTypeFactory)['title']->toArray();

    expect($titleSchema)->not->toHaveKey('enum')
        ->and((string) $agent->instructions())->toContain('короткое название объявления')
        ->and((string) $agent->instructions())->toContain('не спрашивай у поставщика');
});

test('an ambiguous location sends a pick list without spending an attempt', function () {
    $abai = locationNamed('область Абай');
    $abaiDistrict = locationNamed('Абайский район', $abai);
    $shymkentDistrict = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    fakeLocationChoice();
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

test('the third same-named place list is replaced by a clarifying question', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    fakeLocationChoice();
    $session = collectorSession([
        'location_lists' => 2,
        'transcript' => ['Сдаю трактор в Абайском районе, 10000 тг/час'],
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->never();
    // Место в справочнике есть — именно поэтому список показывали дважды.
    // Формулировка «Не нашли» здесь была бы неправдой, а проверка на одно
    // лишь название места её бы пропустила.
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Мест с названием «Абайский район» в справочнике несколько, и мы не поняли, какое из них ваше. Напишите точнее — вместе с областью или районом.');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'да там же'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1);
});

test('a confident AI pick resolves same-named places without asking', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('Карагандинская область'));

    // Поставщик сам назвал область — переспрашивать нечего.
    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    fakeLocationChoice($picked->id);
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->never();
    // Привязанное место названо с цепочкой родителей: выбор сделан без
    // вопроса, и поставщик должен видеть, какой именно из тёзок это был.
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?')
            && str_contains($text, 'Место: Абайский район, Карагандинская область'));

    $outcome = app(SupplierListingCollector::class)->resume(
        $session,
        supplierAiNode(),
        new InboundMessage(text: 'Трактор, Абайский район Карагандинской области, 10000 тг/час'),
    );

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming')
        // Выбор запоминается как ручной: на следующих сообщениях модель
        // об этом месте больше не спрашивают.
        ->and($session->fresh()->state['picked_location_id'])->toBe($picked->id)
        ->and(Listing::sole()->location_id)->toBe($picked->id)
        // Вызов виден в отчёте по расходам AI.
        ->and(AiOperation::query()->where('operation', AiOperationType::LocationDisambiguation)->count())->toBe(1);
});

test('a confident AI pick after a shown list resets the repeat counter', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('Карагандинская область'));

    // Первое сообщение модель не разбирает — список уходит и счётчик
    // становится 1. Второе называет место иначе («Абайская г.а.» —
    // тот же поисковый ключ, что и «Абайский район», см.
    // docs/modules/ai-assistant.md), и модель разбирает его уверенно.
    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район']),
        fullExtraction(['location' => 'Абайская г.а.']),
    ]);
    LocationChoiceAgent::fake([
        fn (): array => ['location_id' => null],
        fn (): array => ['location_id' => (string) $picked->id],
    ]);
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendButtons')->once();

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));

    expect($session->fresh()->state['location_lists'])->toBe(1);

    $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: 'Это в Карагандинской области'));

    expect($session->fresh()->state['location_lists'])->toBe(0)
        ->and($session->fresh()->state['picked_location_id'])->toBe($picked->id);
});

test('a remembered AI pick is not put to the model again on later messages', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('Карагандинская область'));

    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район', 'price' => null, 'clarifying_question' => 'Какая цена?']),
        fullExtraction(['location' => 'Абайский район']),
    ]);
    fakeLocationChoice($picked->id);
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->never();
    $messenger->shouldReceive('sendText')->once();
    $messenger->shouldReceive('sendButtons')->once();

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район Карагандинской области'));
    $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: '10000 тг/час'));

    // Второе сообщение переиспользует запомненный выбор — второго платного
    // вызова разрешителя не было.
    expect(AiOperation::query()->where('operation', AiOperationType::LocationDisambiguation)->count())->toBe(1)
        ->and(Listing::sole()->location_id)->toBe($picked->id);
});

test('an AI answer outside the candidates falls back to the list and is not repeated', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $foreign = locationNamed('г.Караганда');

    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район']),
        fullExtraction(['location' => 'Абайский район']),
    ]);
    fakeLocationChoice($foreign->id);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendList')->twice();

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));
    $outcome = $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: 'ну этот же район'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('locating')
        ->and($session->fresh()->state['picked_location_id'])->toBeNull()
        // Модель уже отказалась разбирать ровно эту формулировку — второй
        // раз её об этом не спрашивают.
        ->and(AiOperation::query()->where('operation', AiOperationType::LocationDisambiguation)->count())->toBe(1);
});

test('an unavailable model for the location choice still shows the list', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    LocationChoiceAgent::fake(fn () => throw new RuntimeException('провайдер недоступен'));
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendList')->once();

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
    fakeLocationChoice();
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

test('a picked location survives later messages without re-asking the list', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    // Оба извлечения возвращают всё то же одноимённое место: поля
    // пересобираются с нуля на каждом сообщении, и без удержания выбора
    // ответ про цену снова получил бы список одноимённых мест.
    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район', 'price' => null, 'clarifying_question' => 'Какая цена?']),
        fullExtraction(['location' => 'Абайский район']),
    ]);
    fakeLocationChoice();
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Какая цена?');
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Всё верно?'));

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район'));
    $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(replyId: 'listing_location:'.$picked->id));
    $outcome = $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: '10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming')
        ->and(Listing::sole()->location_id)->toBe($picked->id);
});

test('naming a different place after a pick rebinds instead of keeping the pick', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $karaganda = locationNamed('г.Караганда');

    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район', 'price' => null, 'clarifying_question' => 'Какая цена?']),
        fullExtraction(['location' => 'Караганда']),
    ]);
    fakeLocationChoice();
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendText')->once();
    $messenger->shouldReceive('sendButtons')->once();

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район'));
    $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(replyId: 'listing_location:'.$picked->id));
    $outcome = $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: 'Вообще-то в Караганде, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming')
        ->and(Listing::sole()->location_id)->toBe($karaganda->id);
});

test('correcting to a same-named place after a pick reopens the list', function () {
    locationNamed('Абайский район', locationNamed('область Абай'));
    $picked = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    // «Абайская г.а.» после выбора «Абайского района» — одноимённое место
    // с тем же поисковым ключом: правка названия должна заново открыть
    // список, а не молча удержать прежний выбор.
    ListingExtractionAgent::fake([
        fullExtraction(['location' => 'Абайский район', 'price' => null, 'clarifying_question' => 'Какая цена?']),
        fullExtraction(['location' => 'Абайская г.а.']),
    ]);
    fakeLocationChoice();
    $session = collectorSession();

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendList')->twice();
    $messenger->shouldReceive('sendText')->once();

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район'));
    $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(replyId: 'listing_location:'.$picked->id));
    $outcome = $collector->resume($session->fresh(), supplierAiNode(), new InboundMessage(text: 'Точнее — Абайская г.а., 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('locating');
});

test('too many namesakes for a list are cut to their biggest disputed level', function () {
    // Продакшен-форма «Абайского района»: 3 района (у одного внутри
    // одноимённая г.а.) и 9 одноимённых с.о. — всего больше лимита
    // списка, но спорный уровень один — районы.
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    locationNamed('Абайская г.а.', $districtA);
    $districtB = locationNamed('Абайский район', locationNamed('область Абай'));
    $districtC = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    foreach (range(1, 9) as $i) {
        locationNamed('Абайский с.о.', locationNamed("Р-{$i} район", locationNamed("Обл {$i}")));
    }

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    fakeLocationChoice();
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendList')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, array $rows) => count($rows) === 3
            && collect($rows)->pluck('id')->contains('listing_location:'.$districtA->id)
            && collect($rows)->pluck('id')->contains('listing_location:'.$districtB->id)
            && collect($rows)->pluck('id')->contains('listing_location:'.$districtC->id));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('locating')
        ->and($session->fresh()->state['attempts'])->toBe(0);
});

test('namesakes beyond the list even at their biggest level ask for a bigger unit', function () {
    foreach (range(1, 11) as $i) {
        locationNamed('Абайский район', locationNamed("Обл {$i}"));
    }

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район'])]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Мест с названием «Абайский район» в справочнике слишком много'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Трактор, Абайский район, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1)
        ->and(Listing::count())->toBe(0);
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
        ->resume($session, supplierAiNode(), new InboundMessage);

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

test('an explicit refusal saves the partial draft and releases the supplier', function () {
    ListingExtractionAgent::fake([fullExtraction(['price' => null, 'user_intent' => 'abandoned'])]);
    $session = collectorSession([
        'transcript' => ['Сдаю трактор в Шымкенте'],
        'fields' => fullExtraction(['price' => null]),
    ]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Хорошо, остановимся')
            && str_contains($text, 'кабинете'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'ой нет, я передумал'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(Listing::sole())
        ->status->toBe(ListingStatus::Draft)
        ->category->name->toBe('Трактор');
});

test('a refusal with nothing collected releases the supplier without mentioning a draft', function () {
    ListingExtractionAgent::fake([[
        'title' => null, 'category' => null, 'brand' => null,
        'description' => null, 'location' => null, 'location_detail' => null,
        'price' => null, 'clarifying_question' => '', 'summary' => null,
        'user_intent' => 'abandoned',
    ]]);
    $session = collectorSession();

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Хорошо, остановимся.');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'не буду ничего размещать'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(Listing::count())->toBe(0);
});

test('a refusal during confirmation ends the loop instead of re-confirming', function () {
    ListingExtractionAgent::fake([fullExtraction(['user_intent' => 'abandoned'])]);
    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
    ]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Хорошо, остановимся'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'да ну его, не надо'));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('a refusal never lands in the listing description', function () {
    ListingExtractionAgent::fake([fullExtraction(['user_intent' => 'abandoned'])]);
    $session = collectorSession([
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
        'fields' => fullExtraction(),
    ]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'всё, я передумал'));

    // Сообщение с отказом изъято: транскрипт остался как был до него.
    expect($session->fresh()->state['transcript'])
        ->toBe(['Сдаю трактор в Шымкенте, 10000 тг/час']);
});

test('a question about the service does not spend a clarification attempt', function () {
    ListingExtractionAgent::fake([fullExtraction(['price' => null, 'user_intent' => 'service_question'])]);
    $session = collectorSession([
        'attempts' => 1,
        'transcript' => ['Сдаю трактор в Шымкенте'],
        'last_question' => 'Какая цена или тариф?',
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Какая цена или тариф?');

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'а размещение платное?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1)
        ->and($session->fresh()->state['transcript'])->toBe(['Сдаю трактор в Шымкенте']);
});

test('a fourth service question in a row walks the ordinary collection path', function () {
    // Залипшая классификация не должна крутить диалог бесконечно: вопрос
    // изымается из транскрипта, поэтому неизменённая переформулировка
    // классифицируется так же. Три вопроса подряд отвечаются встроенным
    // текстом, четвёртый разбирается как обычное сообщение.
    ListingExtractionAgent::fake(fn (): array => fullExtraction([
        'price' => null,
        'clarifying_question' => 'Какая цена или тариф?',
        'user_intent' => 'service_question',
    ]));
    $session = collectorSession([
        'transcript' => ['Сдаю трактор в Шымкенте'],
        'last_question' => 'Какая цена или тариф?',
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->times(3)
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    // Три повтора текущего вопроса плюс он же как уточнение на четвёртом ходе.
    $messenger->shouldReceive('sendText')->times(4)
        ->withArgs(fn (Contact $to, string $text) => $text === 'Какая цена или тариф?');

    $collector = app(SupplierListingCollector::class);
    $question = new InboundMessage(text: 'ну я и спрашиваю, сколько это стоит');

    foreach (range(1, 3) as $ignored) {
        $collector->resume($session->fresh(), supplierAiNode(), $question);
    }

    expect($session->fresh()->state)->toMatchArray([
        'attempts' => 0,
        'service_questions' => 3,
        'transcript' => ['Сдаю трактор в Шымкенте'],
    ]);

    $outcome = $collector->resume($session->fresh(), supplierAiNode(), $question);

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['attempts'])->toBe(1)
        ->and($session->fresh()->state['transcript'])
        ->toBe(['Сдаю трактор в Шымкенте', 'ну я и спрашиваю, сколько это стоит']);
});

test('a service question before any clarifying question repeats the operator greeting', function () {
    // Вопрос про сервис пришёл раньше любого уточняющего: повторять нечего,
    // кроме приветствия блока — а его задаёт оператор в редакторе схем.
    ListingExtractionAgent::fake([fullExtraction(['user_intent' => 'service_question'])]);
    $session = collectorSession(['transcript' => ['Сдаю трактор']]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Что сдаёте? Напишите или наговорите.');

    $outcome = app(SupplierListingCollector::class)->resume(
        $session,
        supplierAiNode() + ['text' => 'Что сдаёте? Напишите или наговорите.'],
        new InboundMessage(text: 'а это платно?'),
    );

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a service question while a place pick list is open resends the same list', function () {
    $districtA = locationNamed('Абайский район', locationNamed('область Абай'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    ListingExtractionAgent::fake([fullExtraction(['location' => 'Абайский район', 'user_intent' => 'service_question'])]);
    $session = collectorSession([
        'phase' => 'locating',
        'location_lists' => 1,
        'transcript' => ['Трактор, Абайский район, 10000 тг/час'],
        'fields' => fullExtraction([
            'location' => 'Абайский район',
            'location_id' => null,
            'location_candidates' => [$districtA->id, $districtB->id],
        ]),
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendList')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, array $rows) => str_contains($text, 'уточните')
            && count($rows) === 2);

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'а как это у вас работает?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state)
        // Ни попытка уточнения, ни счёт повторов списка на вопрос не тратятся.
        ->toMatchArray(['phase' => 'locating', 'attempts' => 0, 'location_lists' => 1]);
});

test('a hand-off from the confirmation phase does not claim the data is missing', function () {
    // Данные уже собраны, и бот ждал нажатия кнопки: «не получилось собрать
    // все данные» здесь было бы неправдой.
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'unreadable' => 2,
        'fields' => fullExtraction(),
    ]);

    fakeCollectorMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => $text === 'Данные объявления собраны. Откройте форму, чтобы проверить и отправить объявление.'
            && mb_strlen($button) <= 20
            && str_contains($url, "/supplier/listings/{$draft->id}/edit"));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage);

    expect($outcome)->toBe(AiOutcome::Completed);
    ListingExtractionAgent::assertNeverPrompted();
});

test('an unreadable message during confirmation keeps the phase and the submit button working', function () {
    // Стикер на сводке не переводит сессию в сбор: иначе следующее нажатие
    // «Да, отправить» проглатывалось бы, и объявление нельзя было бы отправить.
    ListingExtractionAgent::fake()->preventStrayPrompts();
    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'fields' => fullExtraction(),
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Не удалось разобрать'));
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'отправлено на проверку'));

    $collector = app(SupplierListingCollector::class);
    $collector->resume($session, supplierAiNode(), new InboundMessage);

    expect($session->fresh()->state['phase'])->toBe('confirming');

    $outcome = $collector->resume(
        $session->fresh(),
        supplierAiNode(),
        new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT),
    );

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and($draft->fresh()->status)->toBe(ListingStatus::PendingModeration);
    ListingExtractionAgent::assertNeverPrompted();
});

test('a message about the listing resets the service question streak', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession(['service_questions' => 2]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($session->fresh()->state['service_questions'])->toBe(0);
});

test('a question during confirmation repeats the summary instead of re-collecting', function () {
    ListingExtractionAgent::fake([fullExtraction(['user_intent' => 'service_question'])]);
    $draft = Listing::factory()->create();
    $session = collectorSession([
        'phase' => 'confirming',
        'draft_id' => $draft->id,
        'fields' => fullExtraction(),
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
    ]);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => str_contains($text, 'Всё верно?')
            && array_column($buttons, 'title') === ['Да, отправить', 'Исправить']);

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'а сколько объявление висит?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming');
});

test('the operator can override the service question reply', function () {
    // Кэш встроенных ответов — rememberForever, и env контейнера перекрывает
    // CACHE_STORE из phpunit.xml: фиксируем array-стор и чистим набор, как в
    // BotReplyTextsTest.
    config()->set('cache.default', 'array');
    app(BotReplyTexts::class)->flush();

    BotReplyText::query()->create([
        'key' => BotReplyKey::ServiceQuestion->value,
        'text' => 'Условия — на нашем сайте.',
    ]);
    app(BotReplyTexts::class)->flush();

    ListingExtractionAgent::fake([fullExtraction(['price' => null, 'user_intent' => 'service_question'])]);
    $session = collectorSession(['last_question' => 'Какая цена или тариф?']);

    $messenger = fakeCollectorMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Условия — на нашем сайте.');
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Какая цена или тариф?');

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'это платно?'));

    app(BotReplyTexts::class)->flush();
});

test('a single AI provider failure asks to repeat without spending an attempt', function () {
    ListingExtractionAgent::fake([fn () => throw new RuntimeException('AI недоступен')]);
    $session = collectorSession(['transcript' => ['Сдаю трактор']]);

    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'Повторите его'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'в Шымкенте, 10000 тг/час'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state)
        ->toMatchArray(['attempts' => 0, 'provider_failures' => 1]);
});

test('two AI provider failures in a row hand the supplier over to the web form', function () {
    ListingExtractionAgent::fake([fn () => throw new RuntimeException('AI недоступен')]);
    $session = collectorSession(['provider_failures' => 1, 'transcript' => ['Сдаю трактор']]);

    fakeCollectorMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => mb_strlen($button) <= 20
            && str_contains($url, '/supplier/listings/'));

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'ещё раз'));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('a successful extraction resets the provider failure streak', function () {
    ListingExtractionAgent::fake([fullExtraction()]);
    $session = collectorSession(['provider_failures' => 1]);

    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();

    app(SupplierListingCollector::class)
        ->resume($session, supplierAiNode(), new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'));

    expect($session->fresh()->state['provider_failures'])->toBe(0);
});
