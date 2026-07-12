<?php

use App\Enums\AiOutcome;
use App\Enums\CustomerRequestStatus;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CustomerSearchAssistant;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

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
    $crane25 = Listing::factory()->published()->create([
        'category' => 'Автокран', 'description' => 'Кран 25 тонн со стрелой', 'location' => 'Шымкент', 'price' => '20000 тг/ч',
    ]);
    $crane10 = Listing::factory()->published()->create([
        'category' => 'Автокран', 'description' => 'Кран 10 тонн', 'location' => 'Алматы',
    ]);
    Listing::factory()->published()->create(['category' => 'Экскаватор', 'description' => 'Гусеничный', 'location' => 'Астана']);
    Listing::factory()->create(['category' => 'Автокран', 'description' => 'Черновик крана']);

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

test('a fruitless search asks to rephrase without ending the block', function () {
    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'ничего не нашлось'),
    );

    $session = searchSession();
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'вертолёт'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->refresh()->state['attempts'])->toBe(1);
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
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category' => 'Автокран']);

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
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category' => 'Автокран']);
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
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category' => 'Экскаватор']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendButtons')->once();
    $messenger->shouldReceive('sendText')->once();

    $session = searchSession(['phase' => 'choosing', 'query' => 'экскаватор', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(text: 'Экскаватор'));

    expect($outcome)->toBe(AiOutcome::Completed)
        ->and(CustomerRequest::count())->toBe(1);
});

test('any other text while choosing is treated as a refined search', function () {
    $crane = Listing::factory()->published()->create(['category' => 'Автокран', 'description' => 'Кран 25 тонн']);
    $digger = Listing::factory()->published()->create(['category' => 'Экскаватор', 'description' => 'Гусеничный экскаватор']);

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

test('a selection of a listing that expired after the search is not accepted', function () {
    $listing = Listing::factory()->expired()->create(['category' => 'Автокран']);

    $messenger = fakeSearchMessenger();
    $messenger->shouldReceive('sendText')->once(); // «ничего не нашлось» from the re-search fallback

    $session = searchSession(['phase' => 'choosing', 'query' => 'кран', 'offered' => [$listing->id]]);
    $outcome = app(CustomerSearchAssistant::class)
        ->resume($session, customerAiNode(), new InboundMessage(replyId: "listing:{$listing->id}"));

    expect(CustomerRequest::count())->toBe(0)
        ->and($outcome)->toBe(AiOutcome::InProgress);
});
