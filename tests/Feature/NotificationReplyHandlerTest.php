<?php

use App\Enums\CustomerRequestStatus;
use App\Enums\ListingStatus;
use App\Exceptions\SessionWindowClosed;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\NotificationReplyHandler;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function fakeReplyMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function pendingRequest(): CustomerRequest
{
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $customer = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);

    return CustomerRequest::factory()->create([
        'contact_id' => $customer->id,
        'listing_id' => $listing->id,
        'query_text' => 'нужен кран',
    ]);
}

test('«Согласиться» accepts the request and tells both sides', function () {
    $request = pendingRequest();
    $supplier = $request->listing->supplier;

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => $contact->is($supplier) && str_contains($text, 'сообщим заказчику'),
    );
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => $contact->is($request->customer)
            && str_contains($text, 'согласился')
            && str_contains($text, ltrim($supplier->phone, '+')),
    );

    $handled = app(NotificationReplyHandler::class)->handle(
        $supplier,
        new InboundMessage(text: 'Согласиться', replyId: NotificationReplyHandler::requestAcceptId($request)),
    );

    expect($handled)->toBeTrue()
        ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
});

test('«Отказаться» declines the request and informs the customer', function () {
    $request = pendingRequest();

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendText')->twice();

    app(NotificationReplyHandler::class)->handle(
        $request->listing->supplier,
        new InboundMessage(replyId: NotificationReplyHandler::requestDeclineId($request)),
    );

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Declined);
});

test('a decision is final: the second click does not change it', function () {
    $request = pendingRequest();
    $request->accept();

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'уже зафиксирован'),
    );

    $handled = app(NotificationReplyHandler::class)->handle(
        $request->listing->supplier,
        new InboundMessage(replyId: NotificationReplyHandler::requestDeclineId($request)),
    );

    expect($handled)->toBeTrue()
        ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
});

test('клик по заявке, закрытой без ответа, честно говорит «закрыта», а не «вы ответили»', function () {
    $request = pendingRequest();
    $request->expire();

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendText')->once()->withArgs(
        fn (Contact $contact, string $text): bool => str_contains($text, 'закрыта без ответа'),
    );

    app(NotificationReplyHandler::class)->handle(
        $request->listing->supplier,
        new InboundMessage(replyId: NotificationReplyHandler::requestAcceptId($request)),
    );

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Expired);
});

test('a foreign contact cannot answer someone else\'s request', function () {
    $request = pendingRequest();
    $stranger = Contact::factory()->withOpenSessionWindow()->create();

    fakeReplyMessenger()->shouldNotReceive('sendText');

    $handled = app(NotificationReplyHandler::class)->handle(
        $stranger,
        new InboundMessage(replyId: NotificationReplyHandler::requestAcceptId($request)),
    );

    expect($handled)->toBeTrue()
        ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Pending);
});

test('a closed customer window does not break the acceptance', function () {
    $request = pendingRequest();
    $request->customer->update(['last_inbound_at' => now()->subDays(3)]);

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendText')->once(); // only the supplier confirmation
    $messenger->shouldReceive('sendText')->once()->andThrow(
        new SessionWindowClosed($request->customer),
    );

    $handled = app(NotificationReplyHandler::class)->handle(
        $request->listing->supplier,
        new InboundMessage(replyId: NotificationReplyHandler::requestAcceptId($request)),
    );

    expect($handled)->toBeTrue()
        ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
});

test('an ordinary message is not intercepted', function () {
    $contact = Contact::factory()->create();

    fakeReplyMessenger()->shouldNotReceive('sendText');

    expect(app(NotificationReplyHandler::class)->handle($contact, new InboundMessage(text: 'Привет')))
        ->toBeFalse();
});

describe('ответы на 30-дневный опрос', function () {
    test('«Да, актуально» продлевает публикацию ещё на 30 дней', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')
            ->create(['expires_at' => now()->addHours(10), 'renewal_requested_at' => now()]);

        $messenger = fakeReplyMessenger();
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Продлили'),
        );

        $handled = app(NotificationReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: NotificationReplyHandler::renewalYesId($listing)),
        );

        expect($handled)->toBeTrue();
        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Published)
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            ->and($listing->renewal_requested_at)->toBeNull();
    });

    test('«Нет, в архив» снимает объявление с публикации', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        $messenger = fakeReplyMessenger();
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'в архив'),
        );

        app(NotificationReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: NotificationReplyHandler::renewalNoId($listing)),
        );

        expect($listing->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('запоздалый ответ по уже заархивированному объявлению не воскрешает его', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->archived()->for($supplier, 'supplier')->create();

        $messenger = fakeReplyMessenger();
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'уже в архиве'),
        );

        app(NotificationReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: NotificationReplyHandler::renewalYesId($listing)),
        );

        expect($listing->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('чужой контакт не может ответить на опрос', function () {
        $listing = Listing::factory()->published()->create();
        $stranger = Contact::factory()->withOpenSessionWindow()->create();

        fakeReplyMessenger()->shouldNotReceive('sendText');

        $handled = app(NotificationReplyHandler::class)->handle(
            $stranger,
            new InboundMessage(replyId: NotificationReplyHandler::renewalNoId($listing)),
        );

        expect($handled)->toBeTrue()
            ->and($listing->refresh()->status)->toBe(ListingStatus::Published);
    });
});

describe('кнопка «Открыть объявление» уведомления о вердикте модерации', function () {
    test('нажатие присылает персональную ссылку на объявление', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->rejected()->for($supplier, 'supplier')
            ->create(['category_id' => categoryNamed('Автокран')->id]);

        $messenger = fakeReplyMessenger();
        $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
            fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($supplier)
                && str_contains($text, 'Автокран')
                && str_contains($url, "/supplier/listings/{$listing->id}/edit")
                && str_contains($url, 'signature='),
        );

        $handled = app(NotificationReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(text: 'Открыть объявление', replyId: NotificationReplyHandler::listingOpenId($listing)),
        );

        expect($handled)->toBeTrue();
    });

    test('чужой контакт не получает ссылку на чужое объявление', function () {
        $listing = Listing::factory()->rejected()->create();
        $stranger = Contact::factory()->withOpenSessionWindow()->create();

        fakeReplyMessenger()->shouldNotReceive('sendCtaUrl');

        $handled = app(NotificationReplyHandler::class)->handle(
            $stranger,
            new InboundMessage(replyId: NotificationReplyHandler::listingOpenId($listing)),
        );

        expect($handled)->toBeTrue();
    });

    test('нажатие по удалённому объявлению отвечает текстом, а не молчит', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->rejected()->for($supplier, 'supplier')->create();
        $replyId = NotificationReplyHandler::listingOpenId($listing);
        $listing->delete();

        $messenger = fakeReplyMessenger();
        $messenger->shouldNotReceive('sendCtaUrl');
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($supplier)
                && str_contains($text, 'уже нет'),
        );

        $handled = app(NotificationReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: $replyId),
        );

        expect($handled)->toBeTrue();
    });
});

test('кнопка «Обновить объявления» присылает персональную ссылку на кабинет', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();

    $messenger = fakeReplyMessenger();
    $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
        fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($supplier)
            && str_contains($url, "/supplier/{$supplier->id}/listings")
            && str_contains($url, 'signature='),
    );

    $handled = app(NotificationReplyHandler::class)->handle(
        $supplier,
        new InboundMessage(text: 'Обновить объявления', replyId: NotificationReplyHandler::MY_LISTINGS_REPLY),
    );

    expect($handled)->toBeTrue();
});

describe('пачечный опрос: один ответ за все объявления', function () {
    function pollBatch(int $count = 3, string $window = 'withOpenSessionWindow'): ListingRenewalBatch
    {
        $supplier = Contact::factory()->{$window}()->create();
        $listings = Listing::factory()->count($count)->published()->for($supplier, 'supplier')
            ->create(['expires_at' => now()->addHours(10), 'renewal_requested_at' => now()]);

        $batch = ListingRenewalBatch::query()->create(['contact_id' => $supplier->id]);
        $batch->listings()->attach($listings->pluck('id')->all());

        return $batch->setRelation('supplier', $supplier);
    }

    test('«Все актуальны» продлевает все объявления пачки одним нажатием', function () {
        $batch = pollBatch();

        fakeReplyMessenger()->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Продлили'),
        );

        $handled = app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchRenewAllId($batch)),
        );

        expect($handled)->toBeTrue();

        foreach ($batch->listings()->get() as $listing) {
            expect($listing->status)->toBe(ListingStatus::Published)
                ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
                ->and($listing->renewal_requested_at)->toBeNull();
        }
    });

    test('«Все в архив» убирает из поиска всю пачку', function () {
        $batch = pollBatch();

        fakeReplyMessenger()->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'в архив'),
        );

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchArchiveAllId($batch)),
        );

        expect($batch->listings()->pluck('status')->unique()->all())->toBe([ListingStatus::Archived]);
    });

    test('«Разобрать по одному» присылает ссылку на кабинет и ничего не решает за поставщика', function () {
        $batch = pollBatch();

        fakeReplyMessenger()->shouldReceive('sendCtaUrl')->once()->withArgs(
            fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($batch->supplier)
                && str_contains($text, 'кабинет')
                && str_contains($url, "/supplier/{$batch->contact_id}/listings"),
        );

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchPickId($batch)),
        );

        expect($batch->listings()->pluck('status')->unique()->all())->toBe([ListingStatus::Published]);
    });

    test('решение применяется только к тем объявлениям пачки, что ещё опубликованы', function () {
        $batch = pollBatch();
        $archived = $batch->listings()->first();
        $archived->archive();

        fakeReplyMessenger()->shouldReceive('sendText')->once();

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchRenewAllId($batch)),
        );

        expect($archived->refresh()->status)->toBe(ListingStatus::Archived)
            ->and($batch->listings()->where('status', ListingStatus::Published)->count())->toBe(2);
    });

    test('запоздалый ответ по целиком заархивированной пачке никого не воскрешает', function () {
        $batch = pollBatch();
        $batch->listings()->get()->each(fn (Listing $listing) => $listing->archive());

        fakeReplyMessenger()->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'вопрос уже закрыт'),
        );

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchRenewAllId($batch)),
        );

        expect($batch->listings()->pluck('status')->unique()->all())->toBe([ListingStatus::Archived]);
    });

    test('прежний владелец не решает за объявление, переданное другому поставщику', function () {
        $batch = pollBatch();
        $handedOver = $batch->listings()->first();
        $handedOver->update(['contact_id' => Contact::factory()->create()->id]);

        fakeReplyMessenger()->shouldReceive('sendText')->once();

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchArchiveAllId($batch)),
        );

        expect($handedOver->refresh()->status)->toBe(ListingStatus::Published)
            ->and($batch->listings()->where('status', ListingStatus::Archived)->count())->toBe(2);
    });

    test('возвращённое из архива объявление кнопке прежнего опроса больше не подчиняется', function () {
        $batch = pollBatch();
        $listings = $batch->listings()->get();
        // Срок вышел без ответа — автоархив; поставщик передумал и вернул
        // объявление в поиск сам, кнопки старого опроса при этом остались.
        $listings->each(fn (Listing $listing) => $listing->archive());
        $restored = $listings->first();
        $restored->restoreFromArchive();

        fakeReplyMessenger()->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'вопрос уже закрыт'),
        );

        app(NotificationReplyHandler::class)->handle(
            $batch->supplier,
            new InboundMessage(replyId: NotificationReplyHandler::batchArchiveAllId($batch)),
        );

        expect($restored->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('чужой контакт не может ответить за пачку', function () {
        $batch = pollBatch();
        $stranger = Contact::factory()->withOpenSessionWindow()->create();

        fakeReplyMessenger()->shouldNotReceive('sendText');

        $handled = app(NotificationReplyHandler::class)->handle(
            $stranger,
            new InboundMessage(replyId: NotificationReplyHandler::batchArchiveAllId($batch)),
        );

        expect($handled)->toBeTrue()
            ->and($batch->listings()->pluck('status')->unique()->all())->toBe([ListingStatus::Published]);
    });
});
