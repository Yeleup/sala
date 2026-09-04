<?php

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\CustomerRequestStatus;
use App\Enums\ListingMediaType;
use App\Enums\RepairPlace;
use App\Exceptions\OutboundRequestBlocked;
use App\Models\AiOperation;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\Location;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CustomerSearchAssistant;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use App\Support\WhatsappText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\TranscriptionPrompt;
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
 * ожидания. Текст CTA пиннуется байт-в-байт (по умолчанию — CTA выдачи);
 * `$text` переопределяется для тупиковых сценариев (DEAD_END_CTA_TEXT).
 */
function expectCatalogCta(MockInterface $messenger, ?string $urlContains = null, string $text = 'Весь каталог с поиском и фильтрами по месту и категории — по кнопке ниже, ваш запрос уже подставлен.'): void
{
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $sentText, string $button, string $url): bool => $sentText === $text
            && str_contains($url, "/customer/{$contact->id}/listings")
            && str_contains($url, 'signature=')
            && mb_strlen($button) <= 20
            && ($urlContains === null || str_contains(urldecode($url), $urlContains)),
    );
}

/**
 * Выдача не приходит списком в чат: заказчику уходит сообщение-заголовок
 * с кнопкой «В меню» (reply-кнопки и URL-кнопка не совмещаются в одном
 * сообщении WhatsApp), следом — CTA «Все варианты» в веб-каталог, где и
 * происходит выбор объявления. Без `$exactText` пиннуется только зачин
 * заголовка.
 */
function expectResultsHeader(MockInterface $messenger, ?string $exactText = null): void
{
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => ($exactText === null
            ? str_starts_with($text, 'Нашлись варианты по запросу')
            : $text === $exactText)
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
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

test('entering the block asks what the customer needs, with a way back to the menu', function () {
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'Расскажите, что нужно и в каком городе — можно голосом. Например: «нужен кран 25 тонн, Шымкент».'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );
    $session = BotSession::factory()->waitingAt('search')->create(['state' => null]);

    $outcome = app(CustomerSearchAssistant::class)->start($session, customerAiNode());

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('the search AI block sends the operator text instead of the built-in prompt', function () {
    $session = searchSession();

    fakeSearchMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => $text === 'Что ищете и где?'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU);

    app(CustomerSearchAssistant::class)->start($session, customerAiNode() + ['text' => 'Что ищете и где?']);
});

test('a complete query hands the ranked results off to the web catalog', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $shymkent = locationNamed('г.Шымкент');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн со стрелой', 'location_id' => $shymkent->id, 'price' => '20000 тг/ч',
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger, 'Нашлись варианты по запросу «кран 25 тонн» в г.Шымкент. Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    expectCatalogCta($messenger, 'кран 25 тонн');

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    // Список в чат не отправляется: выбор объявления происходит в
    // каталоге, а диалог ждёт уточнение запроса или «В меню».
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['offered'])->toBe([]);
});

test('the results header falls back to the raw query as the subject when it was never extracted', function () {
    SearchQueryExtractionAgent::fake([fn () => throw new RuntimeException('AI недоступен')]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Астана')->id,
    ]);

    $messenger = fakeSearchMessenger();
    // Место не встречается в справочнике внутри самого запроса — заголовок
    // остаётся без «в …», предмет — вся сырая фраза.
    expectResultsHeader($messenger, 'Нашлись варианты по запросу «нужен кран». Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    expectCatalogCta($messenger);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен кран'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

// Нажатия и набранные заголовки строк СТАРЫХ списков выдачи (сообщения,
// отправленные до передачи выдачи каталогу) продолжают работать: фаза
// choosing с offered в state — легаси уже отправленных сообщений, как и
// кнопка «Искать шире».
test('an ordinal digit picks the N-th offered row of a legacy list, title matching still taking priority', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $first = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $second = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Экскаватор')->id]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once(); // уведомление поставщику
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'техника', 'offered' => [$first->id, $second->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: '2'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::sole()->listing_id)->toBe($second->id);
});

test('the query words match the listing title alone', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'манипулятор', 'location' => null, 'location_any' => true])]);
    Listing::factory()->published()->create([
        'title' => 'Кран-манипулятор 5 т',
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Борт 6 м',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    // Совпадение только по названию: без него выдача была бы пуста и
    // пришёл бы тупик — заголовок выдачи доказывает матч.
    expectResultsHeader($messenger);
    expectCatalogCta($messenger);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен манипулятор'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a voice message is transcribed and used as the search query', function () {
    Transcription::fake(['нужен кран, Шымкент']);
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-1')
        ->andReturn(['contents' => 'OGG-BYTES', 'mime_type' => 'audio/ogg']);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger);

    $session = searchSession();
    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-1'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('кран, Шымкент')
        ->and($session->state['transcript'])->toBe(['нужен кран, Шымкент'])
        ->and(AiOperation::query()->where('operation', AiOperationType::Transcription)->count())->toBe(1);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => str_contains(
        (string) ($prompt->providerOptions['prompt'] ?? ''), 'русском или казахском',
    ));
});

test('an undownloadable voice message asks to type the query without spending an attempt', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-2')
        ->andThrow(new RuntimeException('403 Медиа принадлежит другой компании'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Голосовое не расшифровалось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Голосовое не расшифровалось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );

    $outcome = app(ScenarioAiAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-3'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a fruitless search asks to rephrase with a way back to the menu', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'вертолёт', 'location' => null, 'location_any' => true])]);
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Пока по такому запросу пусто')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );
    expectCatalogCta($messenger, text: 'Или загляните в каталог — там все объявления, база пополняется каждый день.');

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

test('typing «в меню» equals pressing it, in every phase', function (string $phase, array $extraState) {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    fakeSearchMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList', 'sendCtaUrl');

    $session = searchSession(['phase' => $phase, ...$extraState]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: '  в МЕНЮ  '));

    // Набранное название кнопки — без регистра и с тримом — не запускает
    // поиск и не тратит попытку/уточнение: та же конвенция, что и у
    // matchesExpandButton/matchChoice.
    expect($outcome)->toBe(AiOutcome::Completed);
})->with([
    'searching' => ['searching', []],
    'choosing' => ['choosing', ['query' => 'кран', 'offered' => [999999]]],
    'locating' => ['locating', ['query' => 'кран, тест', 'location_candidates' => [999999]]],
]);

test('the third fruitless search releases the contact back to the scenario with the catalog CTA', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'вертолёт', 'location' => null, 'location_any' => true])]);
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $text === 'Подходящего сейчас не нашлось — так бывает, база пополняется каждый день. Загляните в каталог: вдруг что-то уже появилось.'
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text, array $buttons) use ($supplier): bool {
        return $contact->is($supplier)
            && str_contains($text, 'Автокран')
            && str_contains($text, 'нужен кран')
            && $buttons[0]['title'] === 'Согласиться'
            && str_contains($buttons[0]['id'], 'request_accept:')
            && $buttons[1]['title'] === 'Отказаться';
    });
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'ушла поставщику'),
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

test('typing the truncated title of a listing with a long category name still equals picking it', function () {
    $longName = 'Гидравлические экскаваторы-погрузчики';
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed($longName)]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'экскаватор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: WhatsappText::clamp($longName, 24)));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('any other text while choosing is treated as a refined search', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'экскаватор', 'location' => null, 'location_any' => true])]);
    $crane = Listing::factory()->published()->create(['category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн']);
    Listing::factory()->published()->create(['category_id' => categoryNamed('Экскаватор')->id, 'description' => 'Гусеничный экскаватор']);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger, 'Нашлись варианты по запросу «экскаватор». Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    expectCatalogCta($messenger, 'экскаватор');

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$crane->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'лучше экскаватор'));

    // Новая выдача гасит легаси-список: остатки offered затираются, чтобы
    // старые строки не выбирались после смены запроса.
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['offered'])->toBe([]);
});

test('a city query covers listings in the city districts', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    $city = locationNamed('г.Шымкент');
    $district = locationNamed('Каратауский район', $city);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран 25 тонн',
        'location_id' => $district->id,
    ]);

    $messenger = fakeSearchMessenger();
    // Единственное объявление лежит в районе города: непустая выдача
    // доказывает, что запрос по городу накрыл поддерево.
    expectResultsHeader($messenger, 'Нашлись варианты по запросу «кран» в г.Шымкент. Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    expectCatalogCta($messenger, "location_id={$city->id}");

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
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Пока по такому запросу пусто')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    expectCatalogCta($messenger, text: 'Или загляните в каталог — там все объявления, база пополняется каждый день.');

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
});

test('пустое поддерево места присылает ссылку в каталог уровнем выше', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран', 'location' => 'Карааул'])]);
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);
    locationNamed('с.Карааул', $district);

    $messenger = fakeSearchMessenger();
    // WhatsApp не смешивает reply-кнопки и URL-кнопку: выход «В меню»
    // едет отдельным сообщением перед CTA в каталог.
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'В «с.Карааул» пока пусто. Посмотрите шире: в каталоге уже подставлены ваш запрос и «Абайский район».'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $text === 'Или загляните в каталог — там все объявления, база пополняется каждый день.'
            && $button === CustomerSearchAssistant::CATALOG_BUTTON_DEAD_END
            && str_contains($url, "location_id={$district->id}")
            && str_contains(urldecode($url), 'кран')
            && ! str_contains(urldecode($url), 'Карааул'),
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Караауле'));

    // Ссылка ведёт в каталог с запросом и местом на уровень выше; попытка
    // потрачена на сам пустой поиск, диалог ждёт уточнённый запрос.
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['attempts'])->toBe(1);
});

test('нажатие старой кнопки «Искать шире» из прежних сообщений продолжает работать', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Кран в райцентре',
        'price' => 'договорная',
        'location_id' => $district->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$district->id}");

    // Сессия, ждущая на кнопке прежней версии (фаза expanding).
    $session = searchSession([
        'phase' => 'expanding',
        'query' => 'кран',
        'expand_location_id' => $district->id,
        'attempts' => 1,
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: CustomerSearchAssistant::BUTTON_EXPAND));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        // Расширение — наша собственная подсказка: попытка потрачена только
        // на первоначальную пустую выдачу.
        ->and($session->state['attempts'])->toBe(1);
});

test('старая кнопка при пустом уровне выше присылает ссылку в каталог ещё шире', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'В «Абайский район» пока пусто. Посмотрите шире: в каталоге уже подставлены ваш запрос и «область Абай».'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $text === 'Или загляните в каталог — там все объявления, база пополняется каждый день.'
            && str_contains($url, "location_id={$region->id}"),
    );

    $session = searchSession([
        'phase' => 'expanding',
        'query' => 'кран',
        'expand_location_id' => $district->id,
        'attempts' => 1,
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: CustomerSearchAssistant::BUTTON_EXPAND));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('when there is nowhere wider to search the dead-end offers a way back to the menu', function () {
    $region = locationNamed('область Абай'); // верхний уровень дерева локаций

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'шире уже некуда')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    expectCatalogCta($messenger, text: 'Или загляните в каталог — там все объявления, база пополняется каждый день.');

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

test('a tap on a listing that expired after the search sends an honest stale-row message and restarts the search for free', function () {
    $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);

    $messenger = fakeSearchMessenger();
    // Устаревшая строка — это наша выдача, не промах заказчика: сначала
    // честное сообщение, потом перезапуск сохранённого запроса.
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Этот вариант уже сняли с публикации. Сейчас поищем свежие.');
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Пока по такому запросу пусто')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    expectCatalogCta($messenger, text: 'Или загляните в каталог — там все объявления, база пополняется каждый день.');

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect(CustomerRequest::count())->toBe(0)
        ->and($outcome)->toBe(AiOutcome::InProgress)
        // Перезапуск после устаревшей строки не тратит безрезультатную
        // попытку — новый поиск по «кран» тоже пуст, но attempts не растёт.
        ->and($session->refresh()->state['attempts'])->toBe(0);
});

test('a query without a place asks a clarifying question before showing listings', function () {
    SearchQueryExtractionAgent::fake([
        fullSearchIntake(['location' => null, 'clarifying_question' => 'В каком городе нужен кран?']),
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'В каком городе нужен кран?'
            && count($buttons) === 1
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
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

test('the intake extractor sees the bot\'s last question as context for a short reply', function () {
    SearchQueryExtractionAgent::fake([
        fullSearchIntake(['location' => null, 'clarifying_question' => 'В каком городе нужен кран?']),
    ]);

    fakeSearchMessenger()->shouldReceive('sendButtons')->once();

    $session = searchSession(['last_question' => 'В каком городе нужна техника?', 'transcript' => ['нужен кран']]);
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'не важно'));

    // «Не важно» имеет смысл только против вопроса о месте — без вопроса
    // бота в промпте модель гадает, к чему относится короткий ответ.
    SearchQueryExtractionAgent::assertPrompted(
        fn ($prompt): bool => $prompt->contains('В каком городе нужна техника?') && $prompt->contains('не важно'),
    );
});

test('the answer to the clarifying question completes the intake and hands the results off', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Место «Сарыагаш» в справочнике не нашлось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
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
    $city = locationNamed('г.Сарыагаш', $district);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => $city->id,
    ]);

    $messenger = fakeSearchMessenger();
    // Заголовок и префилл каталога называют исправленное место из
    // справочника, а не искажённое «Сарагаш».
    expectResultsHeader($messenger, 'Нашлись варианты по запросу «погрузчик» в г.Сарыагаш. Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    expectCatalogCta($messenger, "location_id={$city->id}");

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен погрузчик, Сарагаш'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('the exhausted clarification limit searches without the place and labels the results honestly', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'погрузчик', 'location' => 'Сарыагаш'])]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => locationNamed('г.Алматы')->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger, 'Место «Сарыагаш» не нашлось в справочнике, поэтому подобрали варианты без учёта места. Выбирайте в каталоге — заявка сразу уйдёт поставщику.');
    // Место так и не разрешилось — CTA приходит без префилла места, слово
    // остаётся в строке поиска ссылки.
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $button === CustomerSearchAssistant::CATALOG_BUTTON_RESULTS
            && ! str_contains($url, 'location_id='),
    );

    $session = searchSession(['transcript' => ['нужен погрузчик', 'Сарыагаш'], 'clarifications' => 3]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'город Сарыагаш'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
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
            && count($rows) === 3
            && $rows[0]['id'] === "search_location:{$districtA->id}"
            && $rows[0]['title'] === 'Абайский район'
            && $rows[0]['description'] === 'Карагандинская область'
            && $rows[1]['id'] === "search_location:{$districtB->id}"
            && $rows[1]['description'] === 'г.Шымкент'
            // Последняя строка списка мест — тоже «В меню».
            && $rows[2]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $rows[2]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
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
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtA->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtB->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    // Каталог открывается с фильтром по выбранному месту.
    expectCatalogCta($messenger, "location_id={$districtA->id}");

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "search_location:{$districtA->id}"));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['location_candidates'])->toBe([])
        // Выбранное место запоминается для последующих уточнений.
        ->and($session->state['location_id'])->toBe($districtA->id)
        // Выбор из списка бесплатен: попытка не потрачена.
        ->and($session->state['attempts'])->toBe(0);
});

test('picking a place with an empty subtree sends the wider catalog link', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $regionA = locationNamed('Карагандинская область');
    $districtA = locationNamed('Абайский район', $regionA);
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'В «Абайский район» пока пусто. Посмотрите шире: в каталоге уже подставлены ваш запрос и «Карагандинская область».'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $text === 'Или загляните в каталог — там все объявления, база пополняется каждый день.'
            && $button === CustomerSearchAssistant::CATALOG_BUTTON_DEAD_END
            && str_contains($url, "location_id={$regionA->id}"),
    );

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "search_location:{$districtA->id}"));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
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
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Погрузчик')->id, 'description' => 'Фронтальный погрузчик', 'location_id' => $bulan->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$bulan->id}");

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'погрузчик, Карабулак',
        'location_candidates' => [$bulan->id, $bulat->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'с.Карабулан'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['location_id'])->toBe($bulan->id);
});

test('an ordinal digit picks the N-th place candidate while picking a place', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtA->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtB->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$districtA->id}");

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    // «1» — первая строка текущего списка мест (districtA), а не второй
    // одноимённый кандидат (districtB).
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: '1'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['location_id'])->toBe($districtA->id);
});

test('an ordinal at the last displayed row picks it, even with a tenth hidden candidate behind it', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    // Список мест держит максимум 9 строк (плюс «В меню»); state хранит
    // до 10 (LocationResolver::MAX_CANDIDATES) — «9» должна попадать в
    // последнюю ПОКАЗАННУЮ строку, а не быть отклонена из-за того, что
    // где-то за пределами экрана есть ещё один кандидат.
    $districts = collect(range(1, 10))
        ->map(fn (int $i): Location => locationNamed('Абайский район', locationNamed("Область {$i}")))
        ->values();
    $ninth = $districts[8];
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $ninth->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$ninth->id}");

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => $districts->pluck('id')->all(),
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: '9'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
        ->and($session->state['location_id'])->toBe($ninth->id);
});

test('an ordinal past the displayed rows does not pick the hidden tenth location candidate', function () {
    // «10» не входит в отображённые 9 строк списка мест (MAX_OFFERED_ROWS),
    // хотя в state лежит все 10 кандидатов (LocationResolver::MAX_CANDIDATES)
    // — цифра не должна вслепую выбирать скрытого десятого кандидата, а
    // должна уйти обычным текстом в интейк.
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'task',
    ]]);
    $districts = collect(range(1, 10))
        ->map(fn (int $i): Location => locationNamed('Абайский район', locationNamed("Область {$i}")))
        ->values();
    $tenth = $districts[9];

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'Какая техника нужна?'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );

    $session = searchSession([
        'phase' => 'locating',
        'query' => 'кран, Абайский район',
        'location_candidates' => $districts->pluck('id')->all(),
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: '10'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['location_id'])->not->toBe($tenth->id)
        ->and($session->state['location_id'])->toBeNull();
});

test('any other text while picking a place is treated as a refined search', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран 25 тонн', 'location' => 'Астана'])]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $astana = locationNamed('г.Астана');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $astana->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$astana->id}");

    $session = searchSession([
        'phase' => 'locating',
        'transcript' => ['нужен кран 25 тонн в Абайском районе'],
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'лучше в Астане'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching')
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => $text === 'Что именно вам нужно?'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Мест с названием «Абайский район» в справочнике слишком много')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
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

test('exactly ten same-named places still fit the pick list, capped at nine plus the menu row', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['location' => 'Абайский район'])]);
    foreach (range(1, 10) as $i) {
        locationNamed('Абайский район', locationNamed("Область {$i}"));
    }

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        // Список WhatsApp держит максимум 10 строк: девять кандидатов и
        // последней строкой — «В меню» (десятый кандидат остаётся в
        // state и доступен по точному набранному названию).
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 10
            && str_contains($text, 'Нашли несколько подходящих мест')
            && $rows[9]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $rows[9]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран в Абайском районе'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('locating')
        ->and($session->state['location_candidates'])->toHaveCount(10);
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
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'Напишите, пожалуйста, текстом')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
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
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtA->id,
    ]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $districtB->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, "location_id={$districtA->id}");

    $session = searchSession([
        'transcript' => ['нужен кран 25 тонн в Абайском районе'],
        'location_id' => $districtA->id,
    ]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'а дешевле есть?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        // Сделанный ранее выбор места действует и на уточнения — список
        // мест повторно не приходит, лишний AI-вызов не тратится.
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('an explicit «any place» satisfies the intake and searches the whole base', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран', 'location' => null, 'location_any' => true])]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Астана')->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
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
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
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
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
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

    fakeSearchMessenger()->shouldReceive('sendButtons')->once();

    $session = searchSession();
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран'));

    $operation = AiOperation::query()->where('operation', AiOperationType::SearchQueryExtraction)->sole();
    expect($operation)
        ->contact_id->toBe($session->contact_id)
        ->bot_session_id->toBe($session->id);
});

test('the results CTA link carries the subject and the resolved place without duplication', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake(['subject' => 'кран'])]);
    $city = locationNamed('г.Шымкент');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => $city->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $text === 'Весь каталог с поиском и фильтрами по месту и категории — по кнопке ниже, ваш запрос уже подставлен.'
            && $button === CustomerSearchAssistant::CATALOG_BUTTON_RESULTS
            && str_contains($url, "/customer/{$contact->id}/listings")
            && str_contains($url, 'signature=')
            && str_contains(urldecode($url), 'кран')
            && str_contains($url, "location_id={$city->id}")
            // Распознанное место уходит в фильтр каталога — в строке
            // поиска оно не дублируется.
            && ! str_contains(urldecode($url), 'Шымкент'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен кран, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a failing catalog CTA does not break the delivered выдача', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн', 'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendCtaUrl')->once()->andThrow(new RuntimeException('Dereu недоступен'));

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    // Сбой кнопки логируется и не роняет уже отправленный заголовок
    // выдачи: диалог живёт дальше, заказчик может уточнить запрос.
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('searching');
});

test('a chat pick with a pending web request for the same listing does not ping the supplier twice', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    CustomerRequest::create([
        'contact_id' => $session->contact->id,
        'listing_id' => $listing->id,
        'supplier_contact_id' => $supplier->id,
        'query_text' => 'выбор в веб-каталоге',
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'уже у поставщика'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    // Заявка по этой паре «заказчик-объявление» ещё ждёт ответа —
    // дубль не создаётся, поставщик повторно не уведомляется.
    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('a failed supplier notification is admitted honestly in chat', function () {
    $supplier = Contact::factory()->withClosedSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);

    // Окно поставщика закрыто, утверждённого шаблона нет — уведомить нечем.
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'не получилось'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::sole()->status)->toBe(CustomerRequestStatus::Expired);
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
        fn (Contact $contact, string $text): bool => str_contains($text, 'ушла поставщику. Как только он ответит'),
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(2);
});

test('an explicit refusal releases the customer', function () {
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'abandoned',
    ]]);
    $session = searchSession();

    fakeSearchMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Хорошо, остановимся.');

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'спасибо, я передумал'));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('a worded request for the menu releases the customer without a message, transcript rolled back', function () {
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'menu',
    ]]);
    $session = searchSession(['transcript' => ['нужен кран']]);

    // Как и нажатие кнопки: словесная просьба меню не оставляет
    // сообщения — граф сам приводит контакта в главное меню.
    fakeSearchMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList', 'sendCtaUrl');

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'хочу в главное меню'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and($session->refresh()->state['transcript'])->toBe(['нужен кран']);
});

test('a question about the service spends neither a clarification nor a fruitless attempt', function () {
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'service_question',
    ]]);
    $session = searchSession([
        'clarifications' => 1,
        'attempts' => 1,
        'transcript' => ['нужен кран'],
        'last_question' => 'В каком городе или районе нужен кран?',
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => $text === 'В каком городе или районе нужен кран?'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'а вы берёте комиссию?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state)
        ->toMatchArray(['clarifications' => 1, 'attempts' => 1, 'transcript' => ['нужен кран']]);
});

test('a fourth service question in a row walks the ordinary intake path', function () {
    // Вопрос изымается из транскрипта, поэтому неизменённая переформулировка
    // классифицируется так же: без предела диалог крутился бы вечно, платя
    // по вызову разбора за ход. Три подряд отвечаются встроенным текстом,
    // четвёртый разбирается как обычное сообщение и тратит уточнение.
    SearchQueryExtractionAgent::fake(fn (): array => [
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'service_question',
    ]);
    $session = searchSession(['last_question' => 'Какая техника нужна?']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->times(3)
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    // Три повтора текущего вопроса (repeatCurrentStep, тоже с «В меню») плюс
    // он же как свежее уточнение на четвёртом ходе — все четыре с кнопкой.
    $messenger->shouldReceive('sendButtons')->times(4)
        ->withArgs(fn (Contact $to, string $text, array $buttons) => $text === 'Какая техника нужна?'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU);

    $assistant = app(CustomerSearchAssistant::class);
    $question = new InboundMessage(text: 'ну я и спрашиваю, как это у вас устроено');

    foreach (range(1, 3) as $ignored) {
        $assistant->resume($session->fresh(), customerAiNode(), $question);
    }

    expect($session->fresh()->state)->toMatchArray([
        'clarifications' => 0,
        'attempts' => 0,
        'service_questions' => 3,
        'transcript' => [],
    ]);

    $outcome = $assistant->resume($session->fresh(), customerAiNode(), $question);

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['clarifications'])->toBe(1)
        ->and($session->fresh()->state['transcript'])->toBe(['ну я и спрашиваю, как это у вас устроено']);
});

test('a search requirement resets the service question streak', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    locationNamed('г.Шымкент');
    $session = searchSession(['service_questions' => 2]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once(); // пустая выдача с кнопкой «В меню»
    expectCatalogCta($messenger, text: 'Или загляните в каталог — там все объявления, база пополняется каждый день.');

    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн в Шымкенте'));

    expect($session->fresh()->state['service_questions'])->toBe(0);
});

test('a service question while a place pick list is open resends the same list', function () {
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'service_question',
    ]]);
    $districtA = locationNamed('Абайский район', locationNamed('Карагандинская область'));
    $districtB = locationNamed('Абайский район', locationNamed('г.Шымкент'));
    $session = searchSession([
        'phase' => 'locating',
        'clarifications' => 1,
        'attempts' => 1,
        'query' => 'кран 25 тонн, Абайский район',
        'location_candidates' => [$districtA->id, $districtB->id],
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => str_contains($text, 'Нашли несколько подходящих мест')
            && $button === CustomerSearchAssistant::LOCATION_LIST_BUTTON
            && count($rows) === 3
            && $rows[2]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'а как это работает?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state)
        ->toMatchArray(['phase' => 'locating', 'clarifications' => 1, 'attempts' => 1]);
});

test('a service question while a results list is open sends a hint instead of resending the list', function () {
    SearchQueryExtractionAgent::fake([[
        'subject' => null, 'location' => null, 'location_any' => false,
        'clarifying_question' => '', 'user_intent' => 'service_question',
    ]]);
    $listing = Listing::factory()->published()->create(['category_id' => categoryNamed('Автокран')->id]);
    $session = searchSession([
        'phase' => 'choosing',
        'clarifications' => 1,
        'attempts' => 1,
        'query' => 'кран',
        'offered' => [$listing->id],
    ]);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->never();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => str_contains($text, 'оператор'));
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => $text === 'Выберите вариант из списка выше — или уточните запрос словами.'
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU);

    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'а вы берёте комиссию?'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state)
        ->toMatchArray(['phase' => 'choosing', 'clarifications' => 1, 'attempts' => 1]);
});

test('вход в ветку поиска водителя пишет вид в state и здоровается по-своему', function () {
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'водитель или машинист')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU,
    );
    $session = BotSession::factory()->waitingAt('search_driver')->create(['state' => null]);

    $outcome = app(CustomerSearchAssistant::class)->start($session, customerAiNode() + ['kind' => 'driver']);

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['kind'])->toBe('driver');
});

test('ветка «ищу водителя» передаёт вид ветки в каталожную ссылку', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake([
        'subject' => 'машинист экскаватора', 'location' => null, 'location_any' => true,
    ])]);
    Listing::factory()->driver()->published()->create(['title' => 'Машинист экскаватора']);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    // Каталог по ссылке открывается отфильтрованным по виду ветки —
    // заказчик из «ищу водителя» видит только водителей.
    expectCatalogCta($messenger, 'kind=driver');

    $outcome = app(CustomerSearchAssistant::class)->resume(
        searchSession(['kind' => 'driver']),
        customerAiNode() + ['kind' => 'driver'],
        new InboundMessage(text: 'нужен машинист экскаватора'),
    );

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('ветка аренды передаёт вид ветки в каталожную ссылку', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake()]);
    $shymkent = locationNamed('г.Шымкент');
    Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн со стрелой',
        'location_id' => $shymkent->id, 'price' => '20000 тг/ч',
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    // Чат в ветке аренды ищет жёстко по аренде, поэтому каталог по кнопке
    // обязан показывать то же самое, а не подмешивать мастеров и водителей.
    expectCatalogCta($messenger, 'kind=rental');

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('сказанный заказчиком выезд превращается в жёсткий фильтр', function () {
    SearchQueryExtractionAgent::fake([fullSearchIntake([
        'subject' => 'ремонт гидравлики', 'location' => null, 'location_any' => true, 'needs_travel' => true,
    ])]);
    Listing::factory()->repair()->published()->create([
        'repair_place' => RepairPlace::OwnService, 'services' => 'гидравлика',
        'title' => 'Ремонт гидравлики', 'description' => 'Ремонт гидравлики в своём сервисе.',
    ]);
    Listing::factory()->repair()->published()->create([
        'repair_place' => RepairPlace::Travels, 'services' => 'гидравлика',
        'title' => 'Ремонт гидравлики с выездом', 'description' => 'Ремонт гидравлики с выездом.',
    ]);

    $messenger = fakeSearchMessenger();
    expectResultsHeader($messenger);
    expectCatalogCta($messenger, 'kind=repair');

    $session = searchSession(['kind' => 'repair']);
    $outcome = app(CustomerSearchAssistant::class)->resume(
        $session,
        customerAiNode() + ['kind' => 'repair'],
        new InboundMessage(text: 'нужен ремонт гидравлики, мастер пусть приедет ко мне'),
    );

    // Требование выезда переживает уточнения: оно хранится в state рядом
    // с предметом поиска, а не выводится заново из каждого сообщения.
    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['needs_travel'])->toBeTrue();
});

test('a voice message the local guard blocked stops the turn instead of blaming the recording', function () {
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
    test()->mock(DereuMediaDownloader::class)
        ->shouldReceive('download')->once()->with('voice-blocked')
        ->andThrow(OutboundRequestBlocked::host('api.dereu.example'));

    fakeSearchMessenger()->shouldNotReceive('sendButtons');

    $session = searchSession();

    expect(fn () => app(ScenarioAiAssistant::class)->resume(
        $session,
        customerAiNode(),
        new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-blocked'),
    ))->toThrow(OutboundRequestBlocked::class);
});
