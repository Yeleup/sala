<?php

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\CustomerRequestStatus;
use App\Enums\ListingMediaType;
use App\Models\AiOperation;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CustomerSearchAssistant;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Transcription;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

// Гибридный поиск векторизует запрос; объявления без эмбеддингов
// ранжируются по словам — прежние ожидания тестов не меняются.
beforeEach(fn () => Embeddings::fake());

/**
 * @return array<string, mixed>
 */
function customerAiNode(): array
{
    return ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'];
}

function searchSession(array $state = []): BotSession
{
    return BotSession::factory()->waitingAt('search')->create([
        'state' => array_merge(
            ['phase' => 'searching', 'attempts' => 0, 'clarifications' => 0, 'transcript' => [], 'query' => null, 'offered' => []],
            $state,
        ),
    ]);
}

function fakeSearchMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

/**
 * Каждая выдача и каждый нетерминальный тупик сопровождаются CTA-кнопкой
 * в веб-каталог — персональной подписанной ссылкой на страницу каталога
 * контакта; открытые вопросы (уточнения, списки мест, «Поискать шире?»)
 * кнопкой не сопровождаются — это гарантируют строгие моки без этого
 * ожидания.
 */
function expectCatalogCta(MockInterface $messenger, ?string $urlContains = null): void
{
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => str_contains($url, "/customer/{$contact->id}/listings")
            && str_contains($url, 'signature=')
            && mb_strlen($button) <= 20
            && ($urlContains === null || str_contains(urldecode($url), $urlContains)),
    );
}

/**
 * Ответ разборщика поискового запроса: заказчик назвал и предмет
 * поиска, и место — интейк завершён, поиск запускается сразу.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fullSearchIntake(array $overrides = []): array
{
    return array_merge([
        'subject' => 'кран 25 тонн',
        'location' => 'Шымкент',
        'location_any' => false,
        'clarifying_question' => '',
    ], $overrides);
}

test('entering the block asks what the customer needs', function () {
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'что вам нужно'),
    );
    $session = BotSession::factory()->waitingAt('search')->create(['state' => null]);

    $outcome = app(CustomerSearchAssistant::class)->start($session, customerAiNode());

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('a complete query returns a ranked list of matching published listings', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $shymkent = locationNamed('г.Шымкент');
    $crane25 = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн со стрелой', 'location_id' => $shymkent->id, 'price' => '20000 тг/ч',
    ]);
    $crane10 = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 10 тонн', 'location_id' => $shymkent->id,
    ]);
    Listing::factory()->published()->create(['category_id' => categoryNamed('Экскаватор')->id, 'description' => 'Гусеничный', 'location_id' => locationNamed('г.Астана')->id]);
    Listing::factory()->create(['category_id' => categoryNamed('Автокран')->id, 'description' => 'Черновик крана', 'location_id' => $shymkent->id]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(function (Contact $contact, string $text, string $button, array $rows) use ($crane25, $crane10): bool {
        return count($rows) === 2
            && $rows[0]['id'] === "listing:{$crane25->id}"
            && $rows[0]['title'] === 'Автокран'
            && str_contains($rows[0]['description'], 'Шымкент')
            && $rows[1]['id'] === "listing:{$crane10->id}";
    });
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['offered'])->toBe([$crane25->id, $crane10->id]);
});

test('a listing title leads the search result row instead of the category name', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $listing = Listing::factory()->published()->create([
        'title' => 'Аренда крана 25 т',
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн со стрелой',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}"
            && $rows[0]['title'] === 'Аренда крана 25 т',
    );
    expectCatalogCta($messenger);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('the query words match the listing title alone', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'манипулятор', 'location' => null, 'location_any' => true])]);
    $listing = Listing::factory()->published()->create([
        'title' => 'Кран-манипулятор 5 т',
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Борт 6 м',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 1
            && $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен манипулятор'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a brand in the query ranks the branded listing first', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'экскаватор Hitachi'])]);
    $shymkent = locationNamed('г.Шымкент');
    $hitachi = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Экскаватор')->id, 'brand_id' => brandNamed('Hitachi')->id,
        'description' => 'Гусеничный', 'location_id' => $shymkent->id,
    ]);
    $noBrand = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Экскаватор')->id, 'brand_id' => null,
        'description' => 'Колёсный', 'location_id' => $shymkent->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 2
            && $rows[0]['id'] === "listing:{$hitachi->id}"
            && $rows[1]['id'] === "listing:{$noBrand->id}",
    );
    expectCatalogCta($messenger);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен экскаватор Hitachi, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a voice message is transcribed and used as the search query', function () {
    Transcription::fake(['нужен кран, Шымкент']);
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    $crane = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-1')
        ->andReturn(['contents' => 'OGG-BYTES', 'mime_type' => 'audio/ogg']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$crane->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-1'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('кран, Шымкент')
        ->and($session->state['transcript'])->toBe(['нужен кран, Шымкент'])
        ->and(AiOperation::query()->where('operation', AiOperationType::Transcription)->count())->toBe(1);
});

test('an undownloadable voice message asks to type the query without spending an attempt', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-2')
        ->andThrow(new RuntimeException('403 Медиа принадлежит другой компании'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Не удалось распознать голосовое'),
    );

    $session = searchSession();
    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-2'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(0);
});

test('a silent voice message asks to type the query', function () {
    Transcription::fake(['']);
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-3')
        ->andReturn(['contents' => 'OGG-BYTES', 'mime_type' => 'audio/ogg']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Не удалось распознать голосовое'),
    );

    $outcome = app(ScenarioAiAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-3'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a fruitless search asks to rephrase with a way back to the menu', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'вертолёт', 'location' => null, 'location_any' => true])]);
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'ничего не нашлось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'вертолёт'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
});

test('pressing «В меню» at a dead-end releases the contact from the search block', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    fakeSearchMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList', 'sendCtaUrl');

    $session = searchSession(['attempts' => 1]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('the third fruitless search releases the contact back to the scenario with the catalog CTA', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'вертолёт', 'location' => null, 'location_any' => true])]);
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => str_contains($text, 'Загляните в каталог')
            && $button === CustomerSearchAssistant::CATALOG_BUTTON_DEAD_END
            && str_contains($url, "/customer/{$contact->id}/listings"),
    );

    $session = searchSession(['attempts' => 2]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'вертолёт'));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('picking a row creates a pending request and notifies the supplier in the open window', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text, array $buttons) use ($supplier, $listing): bool {
        return $contact->is($supplier)
            && str_contains($text, 'Автокран')
            && str_contains($text, 'нужен кран')
            && $buttons[0]['title'] === 'Согласиться'
            && str_contains($buttons[0]['id'], 'request_accept:')
            && $buttons[1]['title'] === 'Отказаться';
    });
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'отправлена поставщику'),
    );

    $session = searchSession(['phase' => 'choosing', 'query' => 'нужен кран', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect($outcome)->toBe(AiOutcome::Completed);

    $request = CustomerRequest::sole();
    expect($request)
        ->status->toBe(CustomerRequestStatus::Pending)
        ->listing_id->toBe($listing->id)
        ->query_text->toBe('нужен кран')
        ->contact_id->toBe($session->contact->id);
});

test('outside the window the supplier gets the approved template with reply payloads', function () {
    $supplier = Contact::factory()->withClosedSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $template = WhatsappTemplate::factory()->approved()->create([
        'name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST,
        'language' => 'ru',
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendTemplate')->once()->withArgs(function (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads) use ($supplier, $template): bool {
        return $contact->is($supplier)
            && $sent->is($template)
            && $params[0] === 'Автокран'
            && str_contains($payloads[0], 'request_accept:')
            && str_contains($payloads[1], 'request_decline:');
    });
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'нужен кран', 'offered' => [$listing->id]]);
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect(CustomerRequest::count())->toBe(1);
});

test('a missing approved template does not break the request', function () {
    $supplier = Contact::factory()->withClosedSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once(); // only the customer confirmation

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('typing the exact row title equals picking it', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Экскаватор')->id]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'экскаватор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'Экскаватор'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('typing the listing title equals picking it and the texts name the listing by title', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create([
        'title' => 'Аренда экскаватора', 'category_id' => categoryNamed('Экскаватор')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, '«Аренда экскаватора»'),
    );
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, '«Аренда экскаватора»'),
    );

    $session = searchSession(['phase' => 'choosing', 'query' => 'экскаватор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'Аренда экскаватора'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('typing the full title of a listing whose row title is clamped still equals picking it', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    // 27 символов — в строке списка заголовок обрезан до 24 с «…», но
    // набранное полное название всё равно засчитывается как выбор.
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create([
        'title' => 'Аренда трактора с водителем', 'category_id' => categoryNamed('Трактор')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'трактор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'аренда трактора с водителем'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('a long category name is truncated with an ellipsis within the row title limit', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'экскаватор погрузчик', 'location' => null, 'location_any' => true])]);
    $longName = 'Гидравлические экскаваторы-погрузчики'; // 38 chars, over the 24-char WhatsApp limit
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed($longName), 'description' => 'Модель', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(function (Contact $contact, string $text, string $button, array $rows) {
        expect(mb_strlen($rows[0]['title']))->toBeLessThanOrEqual(24)
            ->and($rows[0]['title'])->toEndWith('…');

        return true;
    });
    expectCatalogCta($messenger);

    $session = searchSession();
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен экскаватор погрузчик'));
});

test('a long location and price are truncated with an ellipsis within the row description limit', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'автокран', 'location' => null, 'location_any' => true])]);
    $longLocation = locationNamed('Каратауский район Шымкент, промышленная зона №5, въезд с южной стороны');
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран'), 'location_id' => $longLocation->id, 'price' => '20000 тг/ч',
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(function (Contact $contact, string $text, string $button, array $rows) {
        expect(mb_strlen($rows[0]['description']))->toBeLessThanOrEqual(72)
            ->and($rows[0]['description'])->toEndWith('…');

        return true;
    });
    expectCatalogCta($messenger);

    $session = searchSession();
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен автокран'));
});

test('typing the truncated title of a listing with a long category name still equals picking it', function () {
    $longName = 'Гидравлические экскаваторы-погрузчики';
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed($longName)]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'экскаватор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: App\Support\WhatsappText::clamp($longName, 24)));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('any other text while choosing is treated as a refined search', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'экскаватор', 'location' => null, 'location_any' => true])]);
    $crane = Listing::factory()->published()->create(['category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн']);
    $digger = Listing::factory()->published()->create(['category_id' => categoryNamed('Экскаватор')->id, 'description' => 'Гусеничный экскаватор']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 1
            && $rows[0]['id'] === "listing:{$digger->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$crane->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'лучше экскаватор'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['offered'])->toBe([$digger->id]);
});

test('a city query covers listings in the city districts', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    $city = locationNamed('г.Шымкент');
    $district = locationNamed('Каратауский район', $city);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн',
        'location_id' => $district->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 1
            && $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a listing outside the requested location subtree is not offered', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    locationNamed('г.Шымкент');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн',
        'location_id' => locationNamed('г.Астана')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'ничего не нашлось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
});

test('an empty subtree offers to widen the search one level up and the click is free', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран', 'location' => 'Карааул'])]);
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);
    $village = locationNamed('с.Карааул', $district);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран в райцентре',
        'location_id' => $district->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Поискать шире')
            && str_contains($text, 'Абайский район')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_EXPAND
            && mb_strlen($buttons[0]['title']) <= 20,
    );
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $assistant = app(CustomerSearchAssistant::class);
    $session = searchSession();
    $assistant->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Караауле'));

    expect($session->fresh()->state['phase'])->toBe('expanding');

    $fresh = $session->fresh();
    $outcome = $assistant->resume($fresh, customerAiNode(), new InboundMessage(replyId: CustomerSearchAssistant::BUTTON_EXPAND));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($fresh->fresh()->state['phase'])->toBe('choosing')
        // Расширение — наша собственная подсказка: попытка потрачена только
        // на первоначальную пустую выдачу.
        ->and($fresh->fresh()->state['attempts'])->toBe(1);
});

test('when there is nowhere wider to search the dead-end offers a way back to the menu', function () {
    $region = locationNamed('область Абай'); // верхний уровень дерева локаций

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Шире искать уже некуда')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    expectCatalogCta($messenger);

    $session = searchSession([
        'phase' => 'expanding',
        'query' => 'кран',
        'expand_location_id' => $region->id,
        'attempts' => 1,
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: CustomerSearchAssistant::BUTTON_EXPAND));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('a selection of a listing that expired after the search is not accepted', function () {
    $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once(); // «ничего не нашлось» from the re-search fallback

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect(CustomerRequest::count())->toBe(0)
        ->and($outcome)->toBe(AiOutcome::InProgress);
});

test('a query without a place asks a clarifying question before showing listings', function () {
    SearchQueryExtractionAgent::fake([
        fullSearchIntake(['location' => null, 'clarifying_question' => 'В каком городе нужен кран?']),
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => $text === 'В каком городе нужен кран?',
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['clarifications'])->toBe(1)
        // Уточняющий вопрос не расходует безрезультатную попытку поиска.
        ->and($session->state['attempts'])->toBe(0)
        ->and($session->state['transcript'])->toBe(['нужен кран 25 тонн']);
});

test('the answer to the clarifying question completes the intake and lists the listings', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_starts_with($text, 'Вот что нашлось')
            && $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession(['transcript' => ['нужен кран 25 тонн'], 'clarifications' => 1]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('кран 25 тонн, Шымкент');

    // Разбор идёт по всей переписке: более поздние сообщения уточняют более ранние.
    SearchQueryExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->contains('нужен кран 25 тонн') && $prompt->contains('Шымкент'),
    );
});

test('a place missing from the dictionary asks to name it precisely instead of searching', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'погрузчик мусора', 'location' => 'Сарыагаш'])]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => locationNamed('г.Алматы')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Не нашли «Сарыагаш» в справочнике мест'),
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен погрузчик мусора в Сарыагаше'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['clarifications'])->toBe(1)
        // Ненайденное место — уточняющий вопрос, а не молчаливый поиск по
        // всей базе: безрезультатная попытка не тратится, выдачи нет.
        ->and($session->state['attempts'])->toBe(0)
        ->and($session->state['phase'])->toBe('searching');
});

test('a voice-distorted place name is corrected to the dictionary and filters the results', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'погрузчик', 'location' => 'Сарагаш'])]);
    $district = locationNamed('Сарыагашский район', locationNamed('Туркестанская область'));
    $inPlace = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => locationNamed('г.Сарыагаш', $district)->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => locationNamed('г.Алматы')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_starts_with($text, 'Вот что нашлось')
            && count($rows) === 1
            && $rows[0]['id'] === "listing:{$inPlace->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен погрузчик, Сарагаш'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('the exhausted clarification limit searches without the place and labels the list honestly', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'погрузчик', 'location' => 'Сарыагаш'])]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => locationNamed('г.Алматы')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Не нашли «Сарыагаш» в справочнике мест')
            && str_contains($text, 'без учёта места')
            && $rows[0]['id'] === "listing:{$listing->id}",
    );
    // Место так и не разрешилось — CTA приходит без префилла места.
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => ! str_contains($url, 'location_id='),
    );

    $session = searchSession(['transcript' => ['нужен погрузчик', 'Сарыагаш'], 'clarifications' => 3]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'город Сарыагаш'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['unresolved_location'])->toBe('Сарыагаш')
        ->and($session->state['query'])->toBe('погрузчик, Сарыагаш');
});

test('an ambiguous place offers the same-named locations to pick without spending a clarification', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест')
            && $button === CustomerSearchAssistant::LOCATION_LIST_BUTTON
            && count($rows) === 2
            && $rows[0]['id'] === "search_location:{$districtA->id}"
            && $rows[0]['title'] === 'Абайский район'
            && $rows[0]['description'] === 'Карагандинская область'
            && $rows[1]['id'] === "search_location:{$districtB->id}"
            && $rows[1]['description'] === 'г.Шымкент',
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating')
        // Список мест — не уточняющий вопрос и не безрезультатная попытка.
        ->and($session->state['clarifications'])->toBe(0)
        ->and($session->state['attempts'])->toBe(0)
        ->and($session->state['location_candidates'])->toBe([$districtA->id, $districtB->id])
        ->and($session->state['query'])->toBe('кран 25 тонн, Абайский район');
});

test('picking a place from the list searches inside the picked subtree', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $inPicked = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtA->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtB->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_starts_with($text, 'Вот что нашлось')
            && count($rows) === 1
            && $rows[0]['id'] === "listing:{$inPicked->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "search_location:{$districtA->id}"));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['location_candidates'])->toBe([])
        // Выбранное место запоминается для последующих уточнений.
        ->and($session->state['location_id'])->toBe($districtA->id)
        // Выбор из списка бесплатен: попытка не потрачена.
        ->and($session->state['attempts'])->toBe(0);
});

test('picking a place with an empty subtree offers to widen the search', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Поискать шире')
            && str_contains($text, 'Карагандинская область')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_EXPAND,
    );

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "search_location:{$districtA->id}"));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('expanding')
        // Пустая выдача по выбранному месту — это и есть отложенный
        // первоначальный поиск: попытка тратится как обычно.
        ->and($session->state['attempts'])->toBe(1);
});

test('typing the shared name of the same-named places cannot pick and re-offers the list', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест'),
    );

    $session = searchSession([
        'phase' => 'locating',
        'transcript' => ['нужен кран 25 тонн в Абайском районе'],
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'Абайский район'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating')
        // Одинаковые названия текстом не различить — их различают подписи;
        // повторный список бесплатен, уточнение не потрачено.
        ->and($session->state['clarifications'])->toBe(0);
});

test('typing the exact name of one distinct candidate equals picking it', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $region = locationNamed('Туркестанская область');
    $bulan = locationNamed('с.Карабулан', $region);
    $bulat = locationNamed('с.Карабулат', $region);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => $bulan->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'погрузчик, Карабулак',
        'location_candidates' => [$bulan->id, $bulat->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'с.Карабулан'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing');
});

test('any other text while picking a place is treated as a refined search', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран 25 тонн', 'location' => 'Астана'])]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Астана')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_starts_with($text, 'Вот что нашлось')
            && $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession([
        'phase' => 'locating',
        'transcript' => ['нужен кран 25 тонн в Абайском районе'],
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'лучше в Астане'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['location_candidates'])->toBe([]);
});

test('pressing «В меню» while picking a place releases the contact', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    fakeSearchMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList', 'sendCtaUrl');
    $district = locationNamed('Абайский район', locationNamed('Карагандинская область'));

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран, Абайский район',
        'location_candidates' => [$district->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('the pick list is offered even after the clarification limit is exhausted', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    locationNamed('Абайский район', locationNamed('Карагандинская область'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест'),
    );

    $session = searchSession(['transcript' => ['нужен кран 25 тонн'], 'clarifications' => 3]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating')
        // Список и выбор из него бесплатны, поэтому лимит им не помеха —
        // в отличие от вопроса, место здесь решается одним нажатием.
        ->and($session->state['clarifications'])->toBe(3);
});

test('a subject question outranks the place pick list', function () {
    SearchQueryExtractionAgent::fake([
        fullSearchIntake(['subject' => null, 'location' => 'Абайский район', 'clarifying_question' => 'Что именно вам нужно?']),
    ]);
    locationNamed('Абайский район', locationNamed('Карагандинская область'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => $text === 'Что именно вам нужно?',
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['clarifications'])->toBe(1)
        ->and($session->state['phase'])->toBe('searching');
});

test('more than ten same-named places ask for a bigger unit instead of the false not-found', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    foreach (range(1, 11) as $i) {
        locationNamed('Абайский район', locationNamed("Область {$i}"));
    }

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Мест с названием «Абайский район» в справочнике слишком много'),
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // В список WhatsApp помещается максимум 10 строк — за пределами
        // действует уточнение; «Не нашли» здесь было бы неправдой.
        ->and($session->refresh()->state['clarifications'])->toBe(1)
        ->and($session->state['phase'])->toBe('searching');
});

test('exactly ten same-named places still fit the pick list', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    foreach (range(1, 10) as $i) {
        locationNamed('Абайский район', locationNamed("Область {$i}"));
    }

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 10
            && str_contains($text, 'Нашли несколько подходящих мест'),
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating');
});

test('equally close spelling corrections offer the pick list through the intake', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'погрузчик', 'location' => 'Карабулак'])]);
    $region = locationNamed('Туркестанская область');
    $bulan = locationNamed('с.Карабулан', $region);
    $bulat = locationNamed('с.Карабулат', $region);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест')
            && $rows[0]['id'] === "search_location:{$bulan->id}"
            && $rows[1]['id'] === "search_location:{$bulat->id}",
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен погрузчик в Карабулаке'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating');
});

test('a location row id outside the offered candidates is not a pick and keeps the list alive', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $foreign = locationNamed('г.Астана');

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Опишите, пожалуйста, текстом'),
    );

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "search_location:{$foreign->id}"));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // Посторонний id (или стикер) не выбирает и не гасит открытый
        // список — нажатие на видимую строку продолжает работать.
        ->and($session->refresh()->state['phase'])->toBe('locating')
        ->and($session->state['location_candidates'])->toBe([$districtA->id, $districtB->id]);
});

test('at the exhausted limit the pick list still outranks the country-wide search', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => null, 'location' => 'Абайский район'])]);
    locationNamed('Абайский район', locationNamed('Карагандинская область'));
    locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест'),
    );

    $session = searchSession(['transcript' => ['нужно что-то арендовать'], 'clarifications' => 3]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // Вопрос о предмете задать уже нельзя, а список бесплатен — он
        // важнее выдачи по всей стране с ложной пометкой «Не нашли».
        ->and($session->refresh()->state['phase'])->toBe('locating')
        ->and($session->state['clarifications'])->toBe(3);
});

test('a refinement after the pick keeps the picked place without re-offering the list', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран дешевле', 'location' => 'Абайский район'])]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $inPicked = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtA->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtB->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_starts_with($text, 'Вот что нашлось')
            && count($rows) === 1
            && $rows[0]['id'] === "listing:{$inPicked->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession([
        'transcript' => ['нужен кран 25 тонн в Абайском районе'],
        'location_id' => $districtA->id,
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'а дешевле есть?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // Сделанный ранее выбор места действует и на уточнения — список
        // повторно не приходит, лишний AI-вызов не тратится.
        ->and($session->refresh()->state['phase'])->toBe('choosing');
});

test('an explicit «any place» satisfies the intake and searches the whole base', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран', 'location' => null, 'location_any' => true])]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Астана')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession(['transcript' => ['нужен кран'], 'clarifications' => 1]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'город не важен'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('кран');
});

test('the exhausted clarification limit searches with whatever was collected', function () {
    SearchQueryExtractionAgent::fake([
        fullSearchIntake(['subject' => null, 'location' => null, 'clarifying_question' => 'Что именно вам нужно?']),
    ]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession(['transcript' => ['нужен кран'], 'clarifications' => 3]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // Сырой текст переписки — запасной запрос, когда предмет так и не понят.
        ->and($session->refresh()->state['query'])->toBe('нужен кран, в Шымкенте')
        ->and($session->state['clarifications'])->toBe(3);
});

test('an unavailable AI provider searches the raw text right away', function () {
    SearchQueryExtractionAgent::fake([fn () => throw new RuntimeException('AI недоступен')]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => $rows[0]['id'] === "listing:{$listing->id}",
    );
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('нужен кран, Шымкент')
        ->and(AiOperation::query()->where('operation', AiOperationType::SearchQueryExtraction)->sole()->status)
        ->toBe(AiOperationStatus::Failed);
});

test('the intake extraction is recorded in the AI audit with dialog links', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => null, 'clarifying_question' => 'Где нужен кран?'])]);

    fakeSearchMessenger()->shouldReceive('sendText')->once();

    $session = searchSession();
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран'));

    $operation = AiOperation::query()->where('operation', AiOperationType::SearchQueryExtraction)->sole();
    expect($operation)
        ->contact_id->toBe($session->contact_id)
        ->bot_session_id->toBe($session->id);
});

test('the results CTA link carries the query and the resolved place', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    $city = locationNamed('г.Шымкент');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $city->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $button === CustomerSearchAssistant::CATALOG_BUTTON_RESULTS
            && str_contains($url, "/customer/{$contact->id}/listings")
            && str_contains($url, 'signature=')
            && str_contains(urldecode($url), 'кран')
            && str_contains($url, "location_id={$city->id}"),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен кран, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a failing catalog CTA does not break the delivered выдача', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once();
    $messenger->shouldReceive('sendCtaUrl')->once()->andThrow(new RuntimeException('Dereu недоступен'));

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    // Кнопка — дополнение к выдаче: её сбой не гасит открытый список.
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['offered'])->toBe([$listing->id]);
});

test('a chat pick with a pending web request for the same listing does not ping the supplier twice', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    CustomerRequest::create([
        'contact_id' => $session->contact->id,
        'listing_id' => $listing->id,
        'query_text' => 'выбор в веб-каталоге',
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'уже отправлена поставщику'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    // Заявка по этой паре «заказчик-объявление» ещё ждёт ответа —
    // дубль не создаётся, поставщик повторно не уведомляется.
    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('a declined request does not block a new chat pick of the same listing', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    CustomerRequest::create([
        'contact_id' => $session->contact->id,
        'listing_id' => $listing->id,
        'query_text' => 'кран',
        'status' => CustomerRequestStatus::Declined,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once(); // уведомление поставщику
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'отправлена поставщику. Как только он ответит'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(2);
});
