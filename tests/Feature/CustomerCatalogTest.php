<?php

use App\Enums\CustomerRequestStatus;
use App\Exceptions\SessionWindowClosed;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

// Поиск каталога — тот же гибридный матчер, что в чате: эмбеддинги
// объявлений в транзакционных тестах не создаются (джоба ждёт коммита),
// поэтому ранжирование детерминировано по словам. Внимание: описание и
// цена из фабрики случайны и могут совпасть со словами запроса («25»,
// «тонн») — тесты с поиском задают их явно.
beforeEach(fn () => Embeddings::fake());

function catalogLinks(): CtaLinkBuilder
{
    return app(CtaLinkBuilder::class);
}

describe('доступ по подписанной ссылке', function () {
    test('страница и выбор недоступны без подписи', function () {
        $contact = Contact::factory()->create();
        $listing = Listing::factory()->published()->create();

        $this->get("/customer/{$contact->id}/listings")->assertForbidden();
        $this->post("/customer/{$contact->id}/listings/{$listing->id}/select")->assertForbidden();
    });

    test('просроченная ссылка не открывается', function () {
        $contact = Contact::factory()->create();

        $url = URL::temporarySignedRoute('customer.listings.index', now()->subMinute(), ['contact' => $contact->id]);

        $this->get($url)->assertForbidden();
    });

    test('добавленные и изменённые параметры фильтров не ломают подпись', function () {
        // Ключевое свойство ссылки: подпись покрывает только путь и срок,
        // поэтому форма фильтров, пагинация и мусор встроенных браузеров
        // (fbclid и подобное) не превращают личную ссылку в 403.
        $contact = Contact::factory()->create();

        $url = catalogLinks()->catalogUrl($contact);

        $this->get($url.'&q=кран&category_id=999999&location_id=abc&sort=bogus&page=3&fbclid=IwAR123')
            ->assertOk();
    });

    test('подмена подписанной части ссылки закрывает доступ', function () {
        $contact = Contact::factory()->create();
        $other = Contact::factory()->create();

        $url = catalogLinks()->catalogUrl($contact);

        $this->get(str_replace("/customer/{$contact->id}/", "/customer/{$other->id}/", $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertForbidden();
    });

    test('подпись страницы каталога не годится для действия «Выбрать»', function () {
        $contact = Contact::factory()->create();
        $listing = Listing::factory()->published()->create();

        $query = parse_url(catalogLinks()->catalogUrl($contact), PHP_URL_QUERY);

        $this->post("/customer/{$contact->id}/listings/{$listing->id}/select?{$query}")->assertForbidden();
    });
});

describe('содержимое каталога', function () {
    test('видны только опубликованные неистёкшие объявления', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->create(['title' => 'Опубликованный кран']);
        Listing::factory()->create(['title' => 'Черновик крана']);
        Listing::factory()->expired()->create(['title' => 'Истёкший кран']);
        Listing::factory()->archived()->create(['title' => 'Архивный кран']);

        $this->get(catalogLinks()->catalogUrl($contact))
            ->assertOk()
            ->assertSee('Опубликованный кран')
            ->assertSee('Найдено объявлений: 1')
            ->assertDontSee('Черновик крана')
            ->assertDontSee('Истёкший кран')
            ->assertDontSee('Архивный кран');
    });

    test('поисковый запрос из ссылки префиллит форму и фильтрует выдачу тем же матчером', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->create([
            'title' => 'Аренда крана 25 тонн', 'category_id' => categoryNamed('Автокран')->id,
            'description' => 'Стрела 40 метров', 'price' => '20000 тг/ч',
        ]);
        Listing::factory()->published()->create([
            'title' => 'Гусеничный экскаватор', 'category_id' => categoryNamed('Экскаватор')->id,
            'description' => 'Копаем котлованы', 'price' => 'договорная',
        ]);

        $this->get(catalogLinks()->catalogUrl($contact, 'кран 25 тонн'))
            ->assertOk()
            ->assertSee('value="кран 25 тонн"', false)
            ->assertSee('Аренда крана 25 тонн')
            ->assertDontSee('Гусеничный экскаватор');
    });

    test('опечатка в поисковом запросе исправляется по справочнику категорий', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->create([
            'title' => 'Гусеничный экскаватор', 'category_id' => categoryNamed('Экскаваторы')->id,
            'description' => 'Копаем котлованы', 'price' => 'договорная',
        ]);

        $this->get(catalogLinks()->catalogUrl($contact, 'эксковаторы'))
            ->assertOk()
            ->assertSee('Гусеничный экскаватор');
    });

    test('релевантность чат-матчера сохраняется в порядке карточек', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->create([
            'title' => 'Автокран обычный', 'category_id' => categoryNamed('Автокран')->id,
            'description' => 'Для стройки', 'price' => 'договорная',
        ]);
        Listing::factory()->published()->create([
            'title' => 'Кран 25 тонн со стрелой', 'category_id' => categoryNamed('Автокран')->id,
            'description' => 'Стрела 40 метров', 'price' => 'договорная',
        ]);

        $content = $this->get(catalogLinks()->catalogUrl($contact, 'кран 25 тонн'))->assertOk()->getContent();

        // Полное совпадение слов запроса ранжируется выше частичного —
        // как в чат-списке.
        expect(mb_strpos($content, 'Кран 25 тонн со стрелой'))
            ->toBeLessThan(mb_strpos($content, 'Автокран обычный'));
    });

    test('фильтр категории сужает выдачу', function () {
        $contact = Contact::factory()->create();
        $crane = categoryNamed('Автокран');
        Listing::factory()->published()->create(['title' => 'Кран для фильтра', 'category_id' => $crane->id]);
        Listing::factory()->published()->create(['title' => 'Экскаватор для фильтра', 'category_id' => categoryNamed('Экскаватор')->id]);

        $this->get(catalogLinks()->catalogUrl($contact)."&category_id={$crane->id}")
            ->assertOk()
            ->assertSee('Кран для фильтра')
            ->assertDontSee('Экскаватор для фильтра');
    });

    test('фильтр места накрывает всё поддерево КАТО', function () {
        $contact = Contact::factory()->create();
        $city = locationNamed('г.Шымкент');
        $district = locationNamed('Каратауский район', $city);
        Listing::factory()->published()->create(['title' => 'Кран в районе города', 'location_id' => $district->id]);
        Listing::factory()->published()->create(['title' => 'Кран в столице', 'location_id' => locationNamed('г.Астана')->id]);

        $this->get(catalogLinks()->catalogUrl($contact)."&location_id={$city->id}")
            ->assertOk()
            ->assertSee('Кран в районе города')
            ->assertDontSee('Кран в столице');
    });

    test('поле места — выпадающий список с префиллом выбранного места', function () {
        $contact = Contact::factory()->create();
        $city = locationNamed('г.Шымкент');
        Listing::factory()->published()->create(['location_id' => $city->id]);

        $this->get(catalogLinks()->catalogUrl($contact)."&location_id={$city->id}")
            ->assertOk()
            ->assertSee('location-picker', false)
            ->assertSee('name="location_id"', false)
            ->assertSee('value="г.Шымкент"', false);
    });

    test('без запроса каталог сортируется от новых к старым, «сначала старые» переворачивает', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->create(['title' => 'Первое объявление']);
        Listing::factory()->published()->create(['title' => 'Второе объявление']);

        $newest = $this->get(catalogLinks()->catalogUrl($contact))->assertOk()->getContent();
        expect(mb_strpos($newest, 'Второе объявление'))->toBeLessThan(mb_strpos($newest, 'Первое объявление'));

        $oldest = $this->get(catalogLinks()->catalogUrl($contact).'&sort=oldest')->assertOk()->getContent();
        expect(mb_strpos($oldest, 'Первое объявление'))->toBeLessThan(mb_strpos($oldest, 'Второе объявление'));
    });

    test('выдача пагинируется по 20, ссылка второй страницы сохраняет подпись', function () {
        $contact = Contact::factory()->create();
        foreach (range(1, 25) as $i) {
            Listing::factory()->published()->create(['title' => sprintf('Кран модель %02d', $i)]);
        }

        $pageOne = $this->get(catalogLinks()->catalogUrl($contact))->assertOk();
        // Сначала новые: страница 1 — модели 25..06, страница 2 — 05..01.
        $pageOne->assertSee('Найдено объявлений: 25')
            ->assertSee('Кран модель 25')
            ->assertSee('Кран модель 06')
            ->assertDontSee('Кран модель 05')
            ->assertSee('Страница 1 из 2');

        $this->get(catalogLinks()->catalogUrl($contact).'&page=2')
            ->assertOk()
            ->assertSee('Кран модель 05')
            ->assertSee('Кран модель 01')
            ->assertDontSee('Кран модель 06');
    });

    test('пустая выдача предлагает изменить запрос или сбросить фильтры', function () {
        $contact = Contact::factory()->create();

        $this->get(catalogLinks()->catalogUrl($contact, 'вертолёт'))
            ->assertOk()
            ->assertSee('Ничего не нашлось. Измените запрос или сбросьте фильтры.');
    });
});

describe('«Выбрать» — заявка с веба', function () {
    test('создаёт заявку и уведомляет поставщика в открытое окно, заказчику приходит подтверждение', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text, array $buttons): bool => $contact->is($supplier)
                && str_contains($text, 'Автокран')
                && str_contains($text, 'кран 25 тонн')
                && str_contains($buttons[0]['id'], 'request_accept:')
                && str_contains($buttons[1]['id'], 'request_decline:'),
        );
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($customer)
                && str_contains($text, 'отправлена поставщику'),
        );

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран 25 тонн'])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Заявка отправлена поставщику'));

        $request = CustomerRequest::sole();
        expect($request)
            ->status->toBe(CustomerRequestStatus::Pending)
            ->contact_id->toBe($customer->id)
            ->listing_id->toBe($listing->id)
            ->query_text->toBe('кран 25 тонн');
    });

    test('выбор с пустой строкой поиска сохраняет честный текст запроса', function () {
        $customer = Contact::factory()->withClosedSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $this->post(catalogLinks()->selectUrl($customer, $listing))->assertRedirect();

        expect(CustomerRequest::sole()->query_text)->toBe('выбор в веб-каталоге');
    });

    test('вне окна поставщик получает утверждённый шаблон', function () {
        $customer = Contact::factory()->withClosedSessionWindow()->create();
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST,
            'language' => 'ru',
        ]);

        test()->mock(DereuMessenger::class)
            ->shouldReceive('sendTemplate')->once()->withArgs(
                fn (Contact $contact, WhatsappTemplate $sent): bool => $contact->is($supplier) && $sent->is($template),
            );

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])->assertRedirect();

        expect(CustomerRequest::count())->toBe(1);
    });

    test('отсутствие утверждённого шаблона не ломает заявку', function () {
        $customer = Contact::factory()->withClosedSessionWindow()->create();
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        test()->mock(DereuMessenger::class); // ни одного сообщения не уходит

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])
            ->assertRedirect()
            ->assertSessionHas('status');

        expect(CustomerRequest::count())->toBe(1);
    });

    test('повторный выбор того же объявления не создаёт дубль и не дёргает поставщика', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        CustomerRequest::create([
            'contact_id' => $customer->id, 'listing_id' => $listing->id, 'query_text' => 'кран',
        ]);

        test()->mock(DereuMessenger::class); // ни уведомления, ни подтверждения

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'уже отправляли заявку'));

        expect(CustomerRequest::count())->toBe(1);
    });

    test('отклонённая заявка не мешает выбрать то же объявление снова', function () {
        $customer = Contact::factory()->withClosedSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        CustomerRequest::create([
            'contact_id' => $customer->id, 'listing_id' => $listing->id,
            'query_text' => 'кран', 'status' => CustomerRequestStatus::Declined,
        ]);

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])->assertRedirect();

        expect(CustomerRequest::count())->toBe(2);
    });

    test('уже неактуальное объявление честно отказывает без заявки', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create();

        test()->mock(DereuMessenger::class);

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'уже не публикуется'));

        expect(CustomerRequest::count())->toBe(0);
    });

    test('сбой WhatsApp-подтверждения заказчику не ломает размещённую заявку', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendButtons')->once();
        $messenger->shouldReceive('sendText')->once()->andThrow(new SessionWindowClosed($customer));

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])
            ->assertRedirect()
            ->assertSessionHas('status');

        expect(CustomerRequest::count())->toBe(1);
    });

    test('заявка с карточки страницы показывает бейдж вместо кнопки при повторном открытии', function () {
        $customer = Contact::factory()->withClosedSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['title' => 'Кран с заявкой']);
        CustomerRequest::create([
            'contact_id' => $customer->id, 'listing_id' => $listing->id, 'query_text' => 'кран',
        ]);

        $this->get(catalogLinks()->catalogUrl($customer))
            ->assertOk()
            ->assertSee('Заявка отправлена — ждём ответа поставщика')
            ->assertDontSee('>Выбрать<', false);
    });

    test('открытый чат-диалог выбора не прерывается веб-заявкой', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        $session = BotSession::factory()->waitingAt('search')->create([
            'contact_id' => $customer->id,
            'state' => ['phase' => 'choosing', 'offered' => [$listing->id], 'query' => 'кран'],
        ]);

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendButtons')->once();
        $messenger->shouldReceive('sendText')->once();

        $this->post(catalogLinks()->selectUrl($customer, $listing), ['q' => 'кран'])->assertRedirect();

        // Сессия бота не тронута: открытый список в чате остаётся валидным,
        // гонку двойного выбора решает дедупликация заявок.
        expect($session->refresh()->state['phase'])->toBe('choosing')
            ->and($session->state['offered'])->toBe([$listing->id]);
    });
});
