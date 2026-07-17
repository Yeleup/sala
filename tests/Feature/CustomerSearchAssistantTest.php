<?php

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
            ['phase' => 'searching', 'attempts' => 0, 'query' => null, 'offered' => []],
            $state,
        ),
    ]);
}

function fakeSearchMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
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

test('a query returns a ranked list of matching published listings', function () {
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

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен кран 25 тонн, Шымкент'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['phase'])->toBe('choosing')
        ->and($session->state['offered'])->toBe([$crane25->id, $crane10->id]);
});

test('a brand in the query ranks the branded listing first', function () {
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

    $outcome = app(CustomerSearchAssistant::class)
        ->resume(searchSession(), customerAiNode(), new InboundMessage(text: 'нужен экскаватор Hitachi'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a voice message is transcribed and used as the search query', function () {
    Transcription::fake(['нужен кран, Шымкент']);
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

    $session = searchSession();
    $outcome = app(ScenarioAiAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-1'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['query'])->toBe('нужен кран, Шымкент')
        ->and(AiOperation::query()->where('operation', AiOperationType::Transcription)->count())->toBe(1);
});

test('an undownloadable voice message asks to type the query without spending an attempt', function () {
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
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once()->withArgs(
        fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, 'ничего не нашлось')
            && $buttons[0]['id'] === CustomerSearchAssistant::BUTTON_MENU
            && $buttons[0]['title'] === CustomerSearchAssistant::BUTTON_MENU_TITLE,
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'вертолёт'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
});

test('pressing «В меню» at a dead-end releases the contact from the search block', function () {
    fakeSearchMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList');

    $session = searchSession(['attempts' => 1]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU));

    expect($outcome)->toBe(AiOutcome::Completed);
});

test('the third fruitless search releases the contact back to the scenario', function () {
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'Загляните позже'),
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

test('a long category name is truncated with an ellipsis within the row title limit', function () {
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

    $session = searchSession();
    app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'нужен экскаватор погрузчик'));
});

test('a long location and price are truncated with an ellipsis within the row description limit', function () {
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
    $crane = Listing::factory()->published()->create(['category_id' => categoryNamed('Автокран')->id, 'description' => 'Кран 25 тонн']);
    $digger = Listing::factory()->published()->create(['category_id' => categoryNamed('Экскаватор')->id, 'description' => 'Гусеничный экскаватор']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendList')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, array $rows): bool => count($rows) === 1
            && $rows[0]['id'] === "listing:{$digger->id}",
    );

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$crane->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'лучше экскаватор'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['offered'])->toBe([$digger->id]);
});

test('a city query covers listings in the city districts', function () {
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

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress);
});

test('a listing outside the requested location subtree is not offered', function () {
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

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'кран в Шымкенте'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
});

test('an empty subtree offers to widen the search one level up and the click is free', function () {
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
