<?php

use App\Enums\ListingStatus;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function fakeCycleMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function expiringListing(array $supplierStates = ['withOpenSessionWindow']): Listing
{
    $supplier = Contact::factory();
    foreach ($supplierStates as $state) {
        $supplier = $supplier->{$state}();
    }

    return Listing::factory()
        ->published()
        ->for($supplier->create(), 'supplier')
        ->create(['category_id' => categoryNamed('Автокран')->id, 'expires_at' => now()->addHours(12)]);
}

/**
 * Несколько истекающих объявлений одного поставщика: ровно тот случай,
 * ради которого опрос уходит одним сообщением.
 *
 * @return Collection<int, Listing>
 */
function expiringListingsOf(Contact $supplier, int $count): Collection
{
    return Listing::factory()
        ->count($count)
        ->published()
        ->for($supplier, 'supplier')
        ->create(['expires_at' => now()->addHours(12)]);
}

/**
 * Явные названия: ListingFactory оставляет title пустым, и displayName()
 * скатывается к случайному имени категории — на таком не проверить, какое
 * объявление назвала пачка.
 *
 * @param  Collection<int, Listing>  $listings
 * @return Collection<int, Listing>
 */
function titled(Collection $listings): Collection
{
    return $listings->values()->each(
        fn (Listing $listing, int $index) => $listing->update(['title' => 'Объявление '.($index + 1)]),
    );
}

describe('ежедневный опрос актуальности', function () {
    test('за сутки до истечения поставщику уходят кнопки, опрос помечается отправленным', function () {
        $listing = expiringListing();

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text, array $buttons) use ($listing): bool {
            return $contact->is($listing->supplier)
                && str_contains($text, 'Автокран')
                && str_contains($text, 'актуально')
                && $buttons[0] === ['id' => "renewal_yes:{$listing->id}", 'title' => 'Да, актуально']
                && $buttons[1] === ['id' => "renewal_no:{$listing->id}", 'title' => 'Нет, в архив'];
        });

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('объявление с названием опрашивается по названию, а не по категории', function () {
        $listing = expiringListing();
        $listing->update(['title' => 'Аренда крана 25 т']);

        fakeCycleMessenger()->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, '«Аренда крана 25 т»')
                && ! str_contains($text, 'Автокран'),
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();
    });

    test('повторный запуск не шлёт опрос второй раз', function () {
        $listing = expiringListing();
        $listing->update(['renewal_requested_at' => now()->subHours(2)]);

        fakeCycleMessenger()->shouldNotReceive('sendButtons');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();
    });

    test('вне окна опрос уходит утверждённым шаблоном с payload кнопок', function () {
        $listing = expiringListing(['withClosedSessionWindow']);
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
            'language' => 'ru',
        ]);

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $sent->is($template)
                && $params === ['Автокран']
                && $payloads === ["renewal_yes:{$listing->id}", "renewal_no:{$listing->id}"],
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('без утверждённого шаблона опрос откладывается и будет повторён завтра', function () {
        $listing = expiringListing(['withClosedSessionWindow']);

        fakeCycleMessenger()->shouldNotReceive('sendTemplate');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->toBeNull();
    });

    test('истёкшее без подтверждения объявление автоматически архивируется', function () {
        $expired = Listing::factory()->expired()->create(['renewal_requested_at' => now()->subDay()]);
        $active = Listing::factory()->published()->create(['expires_at' => now()->addDays(10)]);

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($expired->refresh()->status)->toBe(ListingStatus::Archived)
            ->and($active->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('истёкшее объявление, чей опрос так и не ушёл, не архивируется — его переспрашивают', function () {
        // Вчерашний опрос не состоялся (сбой отправки или асинхронный отказ
        // Meta снял отметку): вопрос не был задан, архив подождёт сутки.
        $listing = expiringListing();
        $listing->update(['expires_at' => now()->subHours(2)]);

        fakeCycleMessenger()->shouldReceive('sendButtons')->once();

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh())
            ->status->toBe(ListingStatus::Published)
            ->renewal_requested_at->not->toBeNull();
    });

    test('если опрос не ушёл и за льготные сутки, объявление всё же архивируется', function () {
        // Бессрочный недозвон держал бы «мёртвую душу» в поиске вечно:
        // повтор ровно один, дальше — архив.
        $listing = expiringListing();
        $listing->update(['expires_at' => now()->subHours(30)]);

        $messenger = fakeCycleMessenger();
        $messenger->shouldNotReceive('sendButtons');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('продление сбрасывает отметку опроса — следующий цикл спросит снова', function () {
        $listing = Listing::factory()->published()->create(['renewal_requested_at' => now()]);

        $listing->renew();

        expect($listing->refresh()->renewal_requested_at)->toBeNull()
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue();
    });
});

describe('опрос пачкой: одно сообщение на поставщика', function () {
    test('несколько истекающих объявлений спрашиваются одним сообщением с тремя кнопками', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listings = titled(expiringListingsOf($supplier, 3));

        $messenger = fakeCycleMessenger();
        // Пачка называет объявление, а не только считает их: сообщение о
        // конкретном объекте Meta относит к utility, голую цифру — к
        // маркетингу, вчетверо дороже.
        $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text, array $buttons) use ($supplier): bool {
            return $contact->is($supplier)
                && str_contains($text, 'Ваше объявление «Объявление 1» и ещё 2 объявления скоро перестанут показываться в поиске')
                && str_contains($text, 'Они ещё актуальны?')
                && collect($buttons)->pluck('title')->all() === ['Все актуальны', 'Разобрать по одному', 'Все в архив'];
        });

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        $batch = ListingRenewalBatch::query()->sole();

        expect($batch->contact_id)->toBe($supplier->id)
            ->and($batch->listings()->pluck('listings.id')->sort()->values()->all())->toBe($listings->pluck('id')->sort()->values()->all())
            ->and($listings->every(fn (Listing $listing): bool => $listing->refresh()->renewal_requested_at !== null))->toBeTrue();
    });

    test('вне окна пачка уходит одним платным шаблоном, а не одним на объявление', function () {
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        titled(expiringListingsOf($supplier, 12));
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL,
            'language' => 'ru',
        ]);

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $sent->is($template)
                && $params === ['Объявление 1', '11 объявлений']
                && count($payloads) === 3,
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();
    });

    test('без единого утверждённого шаблона опрос не уходит вовсе и записи о пачке не остаётся', function () {
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        $listings = expiringListingsOf($supplier, 3);

        fakeCycleMessenger()->shouldNotReceive('sendTemplate');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect(ListingRenewalBatch::query()->count())->toBe(0)
            ->and($listings->every(fn (Listing $listing): bool => $listing->refresh()->renewal_requested_at === null))->toBeTrue();
    });

    test('пока пачечный шаблон не утверждён, спрашиваем по объявлению — молчание стоило бы поставщику всей выдачи', function () {
        // Откладывать «на завтра» нельзя: льготный повтор всего один, и
        // тратить его, когда спросить можно прямо сейчас, — лишний риск.
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        $listings = expiringListingsOf($supplier, 3);
        $single = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
            'language' => 'ru',
        ]);

        fakeCycleMessenger()->shouldReceive('sendTemplate')->times(3)->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent): bool => $sent->is($single),
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect(ListingRenewalBatch::query()->count())->toBe(0)
            ->and($listings->every(fn (Listing $listing): bool => $listing->refresh()->renewal_requested_at !== null))->toBeTrue();
    });

    test('единственное истекающее объявление спрашивается по названию, без пачки', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = expiringListingsOf($supplier, 1)->first();
        $listing->update(['title' => 'Автокран 25 т']);

        fakeCycleMessenger()->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text, array $buttons): bool => str_contains($text, '«Автокран 25 т»')
                && count($buttons) === 2,
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect(ListingRenewalBatch::query()->count())->toBe(0);
    });

    test('объявления разных поставщиков в одну пачку не сваливаются', function () {
        $first = Contact::factory()->withOpenSessionWindow()->create();
        $second = Contact::factory()->withOpenSessionWindow()->create();
        expiringListingsOf($first, 2);
        expiringListingsOf($second, 3);

        fakeCycleMessenger()->shouldReceive('sendButtons')->twice();

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        $batches = ListingRenewalBatch::query()->with('listings')->get();

        expect($batches)->toHaveCount(2)
            ->and($batches->pluck('contact_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
            ->and($batches->map(fn (ListingRenewalBatch $batch): int => $batch->listings->count())->sort()->values()->all())->toBe([2, 3]);
    });

    test('пачка называет объявление с названием, а не первое попавшееся', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listings = expiringListingsOf($supplier, 3);
        // У первого по порядку нет ни названия, ни категории — назвать
        // его значило бы отправить в Meta «без названия».
        $listings->first()->update(['title' => null, 'category_id' => null]);
        $listings->get(1)->update(['title' => 'Экскаватор Hitachi']);
        $listings->last()->update(['title' => 'Самосвал 20 т']);

        fakeCycleMessenger()->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, '«Экскаватор Hitachi»')
                && ! str_contains($text, 'без названия'),
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();
    });

    test('внутри окна и вне его поставщик читает дословно одну фразу', function () {
        // Тело шаблона — единственный источник формулировки, а сессионный
        // текст написан руками: разъехавшись, они дадут двум поставщикам
        // разные сообщения об одном и том же.
        $inside = Contact::factory()->withOpenSessionWindow()->create();
        $outside = Contact::factory()->withClosedSessionWindow()->create();
        titled(expiringListingsOf($inside, 3));
        titled(expiringListingsOf($outside, 3));

        WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL,
            'language' => 'ru',
        ]);

        $session = null;
        $parameters = [];

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text) use (&$session): bool {
            $session = $text;

            return true;
        });
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(function (Contact $contact, WhatsappTemplate $template, array $params) use (&$parameters): bool {
            $parameters = $params;

            return true;
        });

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        $body = app(WhatsappTemplateLibrary::class)->all()
            ->firstWhere('name', WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL)['body'];

        $rendered = str_replace(['{{1}}', '{{2}}'], $parameters, $body);

        expect($session)->toBe($rendered);
    });

    test('склонение остатка выдерживает и подростковые числа, и единицы', function () {
        expect(ListingRenewalBatch::restPhrase(1))->toBe('одно объявление')
            ->and(ListingRenewalBatch::restPhrase(2))->toBe('2 объявления')
            ->and(ListingRenewalBatch::restPhrase(4))->toBe('4 объявления')
            ->and(ListingRenewalBatch::restPhrase(5))->toBe('5 объявлений')
            ->and(ListingRenewalBatch::restPhrase(11))->toBe('11 объявлений')
            ->and(ListingRenewalBatch::restPhrase(12))->toBe('12 объявлений')
            ->and(ListingRenewalBatch::restPhrase(14))->toBe('14 объявлений')
            ->and(ListingRenewalBatch::restPhrase(21))->toBe('21 объявление')
            ->and(ListingRenewalBatch::restPhrase(22))->toBe('22 объявления')
            ->and(ListingRenewalBatch::restPhrase(111))->toBe('111 объявлений');
    });
});
