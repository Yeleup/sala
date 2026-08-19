<?php

use App\Enums\LicenceType;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\RepairPlace;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use App\Services\Ai\CtaLinkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function portalLinks(): CtaLinkBuilder
{
    return app(CtaLinkBuilder::class);
}

/**
 * Валидный набор полей формы кабинета, собранный из самого объявления, —
 * фото-тесты меняют только «свои» ключи.
 */
function supplierListingPayload(Listing $listing, array $overrides = []): array
{
    return array_merge([
        'title' => $listing->title ?? 'Аренда автокрана 25 т',
        'category_id' => $listing->category_id,
        'description' => $listing->description,
        'location_id' => $listing->location_id,
        'price' => $listing->price,
    ], $overrides);
}

/**
 * Валидная анкета мастера по ремонту для формы кабинета.
 */
function validRepairPayload(Listing $listing, array $overrides = []): array
{
    return array_merge([
        'title' => 'Ремонт спецтехники',
        'person_name' => 'Сервис «Мотор»',
        'services' => 'Диагностика, ремонт двигателя, гидравлика.',
        'repair_place' => RepairPlace::Both->value,
        'location_id' => $listing->location_id,
    ], $overrides);
}

/**
 * Валидная анкета водителя для формы кабинета. Документ в набор не входит:
 * тесты либо загружают файл сами, либо кладут медиа-строку в базу.
 */
function validDriverPayload(Listing $listing, array $overrides = []): array
{
    return array_merge([
        'title' => 'Машинист экскаватора',
        'person_name' => 'Серик',
        'licence_type' => LicenceType::TractorOperator->value,
        'experience_years' => 8,
        'location_id' => $listing->location_id,
        'travels_to_other_cities' => '1',
        'machine_categories' => [categoryNamed('Экскаватор')->id],
    ], $overrides);
}

/**
 * Медиа-строка уже загруженного документа водителя.
 */
function storedDocumentFor(Listing $listing, string $path = 'old.jpg'): ListingMedia
{
    return ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => ListingMediaType::Document,
        'disk' => 'local',
        'path' => $path,
    ]);
}

describe('доступ по подписанным ссылкам', function () {
    test('страницы недоступны без подписи', function () {
        $listing = Listing::factory()->create();

        $this->get("/supplier/{$listing->contact_id}/listings")->assertForbidden();
        $this->get("/supplier/listings/{$listing->id}/edit")->assertForbidden();
        $this->post("/supplier/listings/{$listing->id}")->assertForbidden();
        $this->post("/supplier/listings/{$listing->id}/archive")->assertForbidden();
    });

    test('просроченная ссылка не открывается', function () {
        $listing = Listing::factory()->create();

        $url = URL::temporarySignedRoute('supplier.listings.edit', now()->subMinute(), ['listing' => $listing->id]);

        $this->get($url)->assertForbidden();
    });

    test('ссылка без привязки к владельцу больше не открывается', function () {
        // Прежний формат (только id объявления) не позволял проверить,
        // что объявление всё ещё принадлежит получателю ссылки. TTL — 7
        // дней, так что переходный период стоит одну неделю.
        $listing = Listing::factory()->create();

        $url = URL::temporarySignedRoute('supplier.listings.edit', now()->addDays(7), ['listing' => $listing->id]);

        $this->get($url)->assertForbidden();
    });

    test('ссылка прежнего владельца после передачи объявления не работает', function () {
        $listing = Listing::factory()->create();
        $editUrl = portalLinks()->editUrl($listing);
        $updateUrl = portalLinks()->updateUrl($listing);

        $published = Listing::factory()->published()->create();
        $archiveUrl = portalLinks()->archiveUrl($published);

        $listing->update(['contact_id' => Contact::factory()->create()->id]);
        $published->update(['contact_id' => Contact::factory()->create()->id]);

        $this->get($editUrl)->assertForbidden();
        $this->post($updateUrl, supplierListingPayload($listing))->assertForbidden();
        $this->post($archiveUrl)->assertForbidden();

        expect($published->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('поле contact в теле POST не обходит привязку ссылки к владельцу', function () {
        // Подпись покрывает только query string: подсунуть id нового
        // владельца в тело формы по старой ссылке ничего не должно давать.
        $listing = Listing::factory()->create();
        $updateUrl = portalLinks()->updateUrl($listing);

        $published = Listing::factory()->published()->create();
        $archiveUrl = portalLinks()->archiveUrl($published);

        $newOwner = Contact::factory()->create();
        $listing->update(['contact_id' => $newOwner->id]);
        $published->update(['contact_id' => $newOwner->id]);

        $this->post($updateUrl, supplierListingPayload($listing) + ['contact' => $newOwner->id])
            ->assertForbidden();
        $this->post($archiveUrl, ['contact' => $newOwner->id])->assertForbidden();

        expect($published->refresh()->status)->toBe(ListingStatus::Published);
    });
});

describe('мои объявления', function () {
    test('показывает только объявления контакта со статусами и причиной отклонения', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->for($contact, 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);
        Listing::factory()->for($contact, 'supplier')->rejected()->create(['category_id' => categoryNamed('Экскаватор')->id]);
        Listing::factory()->create(['category_id' => categoryNamed('Чужой самосвал')->id]);

        $response = $this->get(portalLinks()->myListingsUrl($contact));

        $response->assertOk()
            ->assertSee('Автокран')
            ->assertSee('Черновик')
            ->assertSee('Экскаватор')
            ->assertSee('Отклонено')
            ->assertSee('Не указана цена — добавьте тариф.')
            ->assertDontSee('Чужой самосвал');
    });

    test('у опубликованного объявления есть срок и кнопка снятия, у черновика — редактирование', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->for($contact, 'supplier')->published()->create();
        Listing::factory()->for($contact, 'supplier')->create();

        $this->get(portalLinks()->myListingsUrl($contact))
            ->assertOk()
            ->assertSee('Опубликовано до')
            ->assertSee('Снять с публикации')
            ->assertSee('Редактировать');
    });
});

describe('имя поставщика', function () {
    test('страница показывает текущее имя и форму его смены', function () {
        $contact = Contact::factory()->create(['profile_name' => 'Асхат', 'display_name' => null]);

        $this->get(portalLinks()->myListingsUrl($contact))
            ->assertOk()
            ->assertSee('Ваше имя')
            ->assertSee('Асхат')
            ->assertSee('Сохранить имя');
    });

    test('смена имени сохраняется в контакте и видна на странице', function () {
        $contact = Contact::factory()->create(['profile_name' => 'Асхат']);

        $this->post(portalLinks()->updateNameUrl($contact), ['display_name' => '  Мағжан  '])
            ->assertRedirect();

        expect($contact->refresh()->display_name)->toBe('Мағжан');
        $this->get(portalLinks()->myListingsUrl($contact))->assertSee('Мағжан');
    });

    test('пустое поле сбрасывает имя к имени из WhatsApp', function () {
        $contact = Contact::factory()->create(['profile_name' => 'Асхат', 'display_name' => 'Мағжан']);

        $this->post(portalLinks()->updateNameUrl($contact), ['display_name' => ''])->assertRedirect();

        $contact->refresh();
        expect($contact->display_name)->toBeNull()
            ->and($contact->displayName())->toBe('Асхат');
    });

    test('без подписи имя не меняется', function () {
        $contact = Contact::factory()->create();

        $this->post("/supplier/{$contact->id}/name", ['display_name' => 'Хакер'])->assertForbidden();

        expect($contact->refresh()->display_name)->toBeNull();
    });

    test('слишком длинное имя не принимается', function () {
        $contact = Contact::factory()->create(['display_name' => 'Мағжан']);

        $response = $this->post(portalLinks()->updateNameUrl($contact), ['display_name' => str_repeat('а', 256)]);

        $response->assertSessionHasErrors(['display_name']);
        expect($contact->refresh()->display_name)->toBe('Мағжан');
    });
});

describe('редактирование', function () {
    test('черновик открывается с формой и текущими значениями', function () {
        $listing = Listing::factory()->create([
            'category_id' => categoryNamed('Автокран')->id,
            'location_id' => locationNamed('г.Шымкент')->id,
        ]);

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('Сохранить и отправить на проверку')
            ->assertSee('Автокран')
            ->assertSee('Шымкент');
    });

    test('форма предлагает выбрать фото из галереи или снять камерой', function () {
        $listing = Listing::factory()->create();

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('Выбрать фото')
            ->assertSee('Снять на камеру')
            ->assertSee('name="photos[]"', false)
            ->assertSee('capture="environment"', false);
    });

    test('поле локации — выпадающий список с префиллом текущего места', function () {
        $listing = Listing::factory()->create([
            'location_id' => locationNamed('Каратауский район', locationNamed('г.Шымкент'))->id,
        ]);

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('location-picker', false)
            ->assertSee('name="location_id"', false)
            ->assertSee('value="Каратауский район, г.Шымкент"', false);
    });

    test('отклонённое открывается с формой и причиной отклонения', function () {
        $listing = Listing::factory()->rejected()->create();

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('Причина отклонения: Не указана цена — добавьте тариф.')
            ->assertSee('Сохранить и отправить на проверку');
    });

    test('объявление на модерации открывается только на просмотр', function () {
        $listing = Listing::factory()->pendingModeration()->create();

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('на проверке у модератора')
            ->assertDontSee('Сохранить и отправить на проверку');
    });

    test('сохранение черновика обновляет поля и отправляет на модерацию', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'title' => 'Аренда автокрана 25 т',
            'category_id' => categoryNamed('Автокран')->id,
            'description' => 'Автокран 25 тонн, стрела 28 м.',
            'location_id' => locationNamed('г.Алматы')->id,
            'location_detail' => 'Ауэзовский район',
            'price' => '5000 тг/ч',
        ]);

        $response->assertRedirect();
        expect($listing->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->title->toBe('Аренда автокрана 25 т')
            ->category->name->toBe('Автокран')
            ->location->name->toBe('г.Алматы')
            ->location_detail->toBe('Ауэзовский район')
            ->price->toBe('5000 тг/ч');
    });

    test('исправленное отклонённое объявление уходит на модерацию повторно', function () {
        $listing = Listing::factory()->rejected()->create();

        $this->post(portalLinks()->updateUrl($listing), [
            'title' => 'Аренда автокрана',
            'category_id' => $listing->category_id,
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '12000 тг/ч',
        ]);

        expect($listing->refresh())->status->toBe(ListingStatus::PendingModeration);
    });

    test('переводы строк и серии пробелов в названии нормализуются при сохранении', function () {
        // Название уходит в параметры шаблонов WhatsApp, где Meta отклоняет
        // переводы строк и серии пробелов.
        $listing = Listing::factory()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'title' => "Аренда\nкрана     25 т",
        ]));

        expect($listing->refresh()->title)->toBe('Аренда крана 25 т');
    });

    test('для отправки на проверку обязательны все бизнес-поля', function () {
        $listing = Listing::factory()->create(['title' => null, 'category_id' => null, 'price' => null]);

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'title' => '',
            'category_id' => '',
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '',
        ]);

        $response->assertSessionHasErrors(['title', 'category_id', 'price']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('категория вне справочника не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'category_id' => 999999,
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '10000 тг/ч',
        ]);

        $response->assertSessionHasErrors(['category_id']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('локация вне справочника не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'category_id' => $listing->category_id,
            'description' => $listing->description,
            'location_id' => 999999,
            'price' => '10000 тг/ч',
        ]);

        $response->assertSessionHasErrors(['location_id']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('марка сохраняется вместе с объявлением', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'brand_id' => brandNamed('Hitachi')->id,
        ]));

        $response->assertRedirect();
        expect($listing->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->brand->name->toBe('Hitachi');
    });

    test('марка необязательна — без неё объявление уходит на модерацию', function () {
        $listing = Listing::factory()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing));

        expect($listing->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->brand_id->toBeNull();
    });

    test('марка вне справочника не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'brand_id' => 999999,
        ]));

        $response->assertSessionHasErrors(['brand_id']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('форма показывает выбор марки, когда справочник заполнен', function () {
        brandNamed('Hitachi');
        $listing = Listing::factory()->create();

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('Марка (необязательно)')
            ->assertSee('Hitachi');
    });

    test('опубликованное объявление сохранить нельзя', function () {
        $listing = Listing::factory()->published()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'category_id' => categoryNamed('Другая категория')->id,
        ]))->assertForbidden();

        expect($listing->refresh())->status->toBe(ListingStatus::Published);
    });
});

describe('анкета по виду', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    test('веб-форма водителя требует поля вида и документ, но не цену', function () {
        $draft = Listing::factory()->driver()->create();

        $response = $this->post(portalLinks()->updateUrl($draft), ['title' => 'Машинист']);

        $response->assertSessionHasErrors(['person_name', 'licence_type', 'experience_years', 'location_id', 'travels_to_other_cities', 'machine_categories', 'document'])
            ->assertSessionDoesntHaveErrors(['price', 'category_id', 'description']);
        expect($draft->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('веб-форма мастера требует поля вида, но не цену и описание', function () {
        $draft = Listing::factory()->repair()->create();

        $response = $this->post(portalLinks()->updateUrl($draft), ['title' => 'Ремонт спецтехники']);

        $response->assertSessionHasErrors(['person_name', 'services', 'repair_place', 'location_id'])
            ->assertSessionDoesntHaveErrors(['price', 'description', 'category_id', 'document', 'machine_categories']);
        expect($draft->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('анкета мастера сохраняется и уходит на модерацию', function () {
        $draft = Listing::factory()->repair()->create(['person_name' => null, 'services' => null, 'repair_place' => null]);

        $this->post(portalLinks()->updateUrl($draft), validRepairPayload($draft))->assertRedirect();

        expect($draft->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->person_name->toBe('Сервис «Мотор»')
            ->services->toBe('Диагностика, ремонт двигателя, гидравлика.')
            ->repair_place->toBe(RepairPlace::Both);
    });

    test('анкета водителя сохраняется: техника синкается, документ ложится на закрытый диск', function () {
        $draft = Listing::factory()->driver()->create(['person_name' => null, 'licence_type' => null]);

        $this->post(portalLinks()->updateUrl($draft), validDriverPayload($draft, [
            'machine_categories' => [categoryNamed('Экскаватор')->id, categoryNamed('Самосвал')->id],
            'document' => UploadedFile::fake()->image('licence.jpg'),
        ]))->assertRedirect();

        $draft->refresh();
        expect($draft->status)->toBe(ListingStatus::PendingModeration)
            ->and($draft->person_name)->toBe('Серик')
            ->and($draft->licence_type)->toBe(LicenceType::TractorOperator)
            ->and($draft->travels_to_other_cities)->toBeTrue()
            ->and($draft->machineCategories->pluck('name')->sort()->values()->all())->toBe(['Самосвал', 'Экскаватор'])
            ->and($draft->documents()->count())->toBe(1);

        $document = $draft->documents()->first();
        expect($document->disk)->toBe('local');
        Storage::disk('local')->assertExists($document->path);
    });

    test('скрытый ноль чекбокса «готов выезжать» сохраняется как false', function () {
        $draft = Listing::factory()->driver()->create();
        storedDocumentFor($draft);

        $this->post(portalLinks()->updateUrl($draft), validDriverPayload($draft, [
            'travels_to_other_cities' => '0',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        expect($draft->refresh()->travels_to_other_cities)->toBeFalse();
    });

    test('замена документа сбрасывает галочку проверки', function () {
        $draft = Listing::factory()->driver()->create([
            'document_verified_at' => now(),
            'document_verified_by' => User::factory(),
        ]);
        Storage::disk('local')->put('old.jpg', 'JPEG');
        storedDocumentFor($draft);

        $this->post(portalLinks()->updateUrl($draft), validDriverPayload($draft, [
            'document' => UploadedFile::fake()->image('new.jpg'),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        // Старый снимок удалён вместе с файлом, остался только новый.
        expect($draft->document_verified_at)->toBeNull()
            ->and($draft->document_verified_by)->toBeNull()
            ->and($draft->documents()->count())->toBe(1)
            ->and($draft->documents()->first()->path)->not->toBe('old.jpg');
        Storage::disk('local')->assertMissing('old.jpg');
    });

    test('документ не обязателен, когда он уже загружен, — проверка не сбрасывается', function () {
        $draft = Listing::factory()->driver()->create([
            'document_verified_at' => now(),
            'document_verified_by' => User::factory(),
        ]);
        storedDocumentFor($draft);

        $this->post(portalLinks()->updateUrl($draft), validDriverPayload($draft))
            ->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        expect($draft->status)->toBe(ListingStatus::PendingModeration)
            ->and($draft->document_verified_at)->not->toBeNull()
            ->and($draft->documents()->count())->toBe(1);
    });

    test('техника вне справочника не принимается', function () {
        $draft = Listing::factory()->driver()->create();
        storedDocumentFor($draft);

        $response = $this->post(portalLinks()->updateUrl($draft), validDriverPayload($draft, [
            'machine_categories' => [999999],
        ]));

        $response->assertSessionHasErrors(['machine_categories.0']);
        expect($draft->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('форма водителя показывает анкету вида и загрузку документа вместо цены', function () {
        categoryNamed('Экскаватор');
        $draft = Listing::factory()->driver()->create();

        $this->get(portalLinks()->editUrl($draft))
            ->assertOk()
            ->assertSee('Тип удостоверения')
            ->assertSee('Тракторист-машинист')
            ->assertSee('Стаж, лет')
            ->assertSee('Готов выезжать в другие города')
            ->assertSee('Техника, на которой работаете')
            ->assertSee('name="machine_categories[]"', false)
            ->assertSee('Снимок увидит только оператор — в объявлении он не показывается.')
            ->assertDontSee('Цена / тариф')
            ->assertDontSee('name="category_id"', false)
            ->assertDontSee('name="price"', false);
    });

    test('форма водителя с загруженным документом предлагает замену', function () {
        $draft = Listing::factory()->driver()->create();
        storedDocumentFor($draft);

        $this->get(portalLinks()->editUrl($draft))
            ->assertOk()
            ->assertSee('Документ загружен. Загрузите новый файл, чтобы заменить (проверка будет выполнена заново).');
    });

    test('форма мастера показывает поля ремонта без категории и техники', function () {
        $draft = Listing::factory()->repair()->create();

        $this->get(portalLinks()->editUrl($draft))
            ->assertOk()
            ->assertSee('Имя или название сервиса')
            ->assertSee('Услуги')
            ->assertSee('Где выполняете ремонт')
            ->assertSee('В своём сервисе')
            ->assertSee('Цена за диагностику (необязательно)')
            ->assertSee('Описание (необязательно)')
            ->assertDontSee('name="category_id"', false)
            ->assertDontSee('name="machine_categories[]"', false);
    });

    test('просмотр анкеты водителя показывает поля вида списком', function () {
        $listing = Listing::factory()->driver()->pendingModeration()->create(['title' => 'Машинист экскаватора']);
        $listing->machineCategories()->sync([categoryNamed('Экскаватор')->id]);
        storedDocumentFor($listing);

        $this->get(portalLinks()->editUrl($listing))
            ->assertOk()
            ->assertSee('Серик')
            ->assertSee('Экскаватор')
            ->assertSee('Тракторист-машинист')
            ->assertSee('8 лет')
            ->assertSee('Готов выезжать в другие города')
            ->assertSee('Фото удостоверения')
            ->assertDontSee('Цена / тариф');
    });

    test('карточки «Мои объявления» показывают подзаголовок по виду', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->for($contact, 'supplier')->repair()->create();
        $driver = Listing::factory()->for($contact, 'supplier')->driver()->create();
        $driver->machineCategories()->sync([categoryNamed('Экскаватор')->id]);

        $this->get(portalLinks()->myListingsUrl($contact))
            ->assertOk()
            ->assertSee('Сервис «Мотор»')
            ->assertSee('Диагностика, ремонт двигателя')
            ->assertSee('Серик')
            ->assertSee('Экскаватор');
    });
});

describe('фотографии', function () {
    beforeEach(function () {
        Storage::fake('public');
    });

    test('загруженные фотографии сохраняются в объявлении', function () {
        $listing = Listing::factory()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'photos' => [UploadedFile::fake()->image('crane.jpg'), UploadedFile::fake()->image('side.png')],
        ]))->assertRedirect();

        $photos = $listing->refresh()->photos;
        expect($photos)->toHaveCount(2);
        $photos->each(fn (ListingMedia $photo) => Storage::disk('public')->assertExists($photo->path));
    });

    test('отмеченные фотографии удаляются вместе с файлами', function () {
        $listing = Listing::factory()->create();
        Storage::disk('public')->put("listings/{$listing->id}/photos/old.jpg", 'JPEG');
        $photo = ListingMedia::factory()->for($listing)->create(['path' => "listings/{$listing->id}/photos/old.jpg"]);

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'remove_photos' => [$photo->id],
        ]))->assertRedirect();

        expect(ListingMedia::count())->toBe(0);
        Storage::disk('public')->assertMissing("listings/{$listing->id}/photos/old.jpg");
    });

    test('фото чужого объявления через remove_photos не удаляется', function () {
        $listing = Listing::factory()->create();
        $foreign = ListingMedia::factory()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'remove_photos' => [$foreign->id],
        ]))->assertRedirect();

        expect($foreign->fresh())->not->toBeNull();
    });

    test('файл, не являющийся изображением, не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'photos' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')],
        ]));

        $response->assertSessionHasErrors(['photos.0']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft)
            ->and(ListingMedia::count())->toBe(0);
    });

    test('фото крупнее лимита в 10 МБ не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'photos' => [UploadedFile::fake()->image('big.jpg')->size(ListingMedia::MAX_PHOTO_KILOBYTES + 1)],
        ]));

        $response->assertSessionHasErrors(['photos.0' => 'Фото слишком большое — не более 10 МБ.']);
        expect(ListingMedia::count())->toBe(0);
    });

    test('фото в пределах 10 МБ принимается', function () {
        $listing = Listing::factory()->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'photos' => [UploadedFile::fake()->image('large.jpg')->size(8 * 1024)],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        expect($listing->photos()->count())->toBe(1);
    });

    test('больше 10 фотографий у объявления быть не может', function () {
        $listing = Listing::factory()->create();
        ListingMedia::factory()->count(Listing::MAX_PHOTOS)->for($listing)->create();

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'photos' => [UploadedFile::fake()->image('one-more.jpg')],
        ]));

        $response->assertSessionHasErrors(['photos']);
        expect($listing->photos()->count())->toBe(Listing::MAX_PHOTOS)
            ->and($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('удаление освобождает место под новое фото в пределах лимита', function () {
        $listing = Listing::factory()->create();
        $photos = ListingMedia::factory()->count(Listing::MAX_PHOTOS)->for($listing)->create();

        $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'remove_photos' => [$photos->first()->id],
            'photos' => [UploadedFile::fake()->image('replacement.jpg')],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        expect($listing->photos()->count())->toBe(Listing::MAX_PHOTOS);
    });
});

describe('архивирование', function () {
    test('поставщик снимает опубликованное объявление с публикации', function () {
        $listing = Listing::factory()->published()->create();

        $this->post(portalLinks()->archiveUrl($listing))->assertRedirect();

        expect($listing->refresh())->status->toBe(ListingStatus::Archived);
    });

    test('черновик заархивировать нельзя', function () {
        $listing = Listing::factory()->create();

        $this->post(portalLinks()->archiveUrl($listing))->assertForbidden();

        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });
});

describe('продление и возврат из архива', function () {
    test('«Продлить» отодвигает срок показа ещё на 30 дней и сбрасывает отметку опроса', function () {
        $listing = Listing::factory()->published()->create([
            'expires_at' => now()->addHours(6),
            'renewal_requested_at' => now(),
        ]);

        $this->post(portalLinks()->renewUrl($listing))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Продлили'));

        $listing->refresh();

        expect($listing->status)->toBe(ListingStatus::Published)
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            ->and($listing->renewal_requested_at)->toBeNull();
    });

    test('«Продлить все» сводит сроки публикаций к одной дате', function () {
        $contact = Contact::factory()->create();
        $soon = Listing::factory()->published()->for($contact, 'supplier')->create(['expires_at' => now()->addHours(6)]);
        $later = Listing::factory()->published()->for($contact, 'supplier')->create(['expires_at' => now()->addDays(11)]);
        $archived = Listing::factory()->archived()->for($contact, 'supplier')->create();

        $this->post(portalLinks()->renewAllUrl($contact))->assertRedirect();

        expect($soon->refresh()->expires_at->diffInMinutes($later->refresh()->expires_at))->toBeLessThan(1)
            ->and($soon->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            // Продление — про публикации: архив им не трогается.
            ->and($archived->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('«Вернуть в поиск» возвращает архивное объявление в публикацию на 30 дней', function () {
        $listing = Listing::factory()->publishable()->archived()->create();

        $this->post(portalLinks()->restoreUrl($listing))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Вернули'));

        $listing->refresh();

        expect($listing->status)->toBe(ListingStatus::Published)
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            ->and($listing->renewal_requested_at)->toBeNull();
    });

    test('возврат работает и у объявления с незаполненными полями — оно уже было в поиске таким', function () {
        $listing = Listing::factory()->archived()->create(['title' => null, 'price' => null]);

        $this->post(portalLinks()->restoreUrl($listing))->assertRedirect();

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('продлить можно только опубликованное, вернуть — только архивное', function () {
        $published = Listing::factory()->publishable()->published()->create();
        $archived = Listing::factory()->publishable()->archived()->create();

        $this->post(portalLinks()->restoreUrl($published))->assertForbidden();
        $this->post(portalLinks()->renewUrl($archived))->assertForbidden();

        expect($published->refresh()->status)->toBe(ListingStatus::Published)
            ->and($archived->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('ссылки продления и возврата требуют подписи и живут вместе с владельцем', function () {
        $published = Listing::factory()->published()->create();
        $archived = Listing::factory()->publishable()->archived()->create();
        $renewUrl = portalLinks()->renewUrl($published);
        $restoreUrl = portalLinks()->restoreUrl($archived);

        $this->post("/supplier/listings/{$published->id}/renew")->assertForbidden();
        $this->post("/supplier/listings/{$archived->id}/restore")->assertForbidden();

        $published->update(['contact_id' => Contact::factory()->create()->id]);
        $archived->update(['contact_id' => Contact::factory()->create()->id]);

        $this->post($renewUrl)->assertForbidden();
        $this->post($restoreUrl)->assertForbidden();

        expect($archived->refresh()->status)->toBe(ListingStatus::Archived);
    });

    test('кабинет показывает кнопки по статусу объявления', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->published()->for($contact, 'supplier')->create();
        Listing::factory()->publishable()->archived()->for($contact, 'supplier')->create();

        $this->get(portalLinks()->myListingsUrl($contact))
            ->assertOk()
            ->assertSee('Продлить все на 30 дней')
            ->assertSee('Продлить на 30 дней')
            ->assertSee('Вернуть в поиск');
    });

    test('без опубликованных объявлений «Продлить все» не показывается', function () {
        $contact = Contact::factory()->create();
        Listing::factory()->archived()->for($contact, 'supplier')->create();

        $this->get(portalLinks()->myListingsUrl($contact))
            ->assertOk()
            ->assertDontSee('Продлить все на 30 дней');
    });
});
