<?php

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
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
        'type' => $listing->type->value,
        'title' => $listing->title ?? 'Аренда автокрана 25 т',
        'category_id' => $listing->category_id,
        'description' => $listing->description,
        'location_id' => $listing->location_id,
        'price' => $listing->price,
    ], $overrides);
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

    test('ссылки формата, выданного до появления кабинета, продолжают открываться', function () {
        $listing = Listing::factory()->create();

        $url = URL::temporarySignedRoute('supplier.listings.edit', now()->addDays(7), ['listing' => $listing->id]);

        $this->get($url)->assertOk();
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
            'type' => ListingType::Service->value,
            'title' => 'Услуги сварщиков',
            'category_id' => categoryNamed('Сварщик', ListingType::Service)->id,
            'description' => 'Бригада сварщиков с допусками.',
            'location_id' => locationNamed('г.Алматы')->id,
            'location_detail' => 'Ауэзовский район',
            'price' => '5000 тг/ч',
        ]);

        $response->assertRedirect();
        expect($listing->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->type->toBe(ListingType::Service)
            ->title->toBe('Услуги сварщиков')
            ->category->name->toBe('Сварщик')
            ->location->name->toBe('г.Алматы')
            ->location_detail->toBe('Ауэзовский район')
            ->price->toBe('5000 тг/ч');
    });

    test('исправленное отклонённое объявление уходит на модерацию повторно', function () {
        $listing = Listing::factory()->rejected()->create();

        $this->post(portalLinks()->updateUrl($listing), [
            'type' => $listing->type->value,
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
            'type' => $listing->type->value,
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
            'type' => $listing->type->value,
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
            'type' => $listing->type->value,
            'category_id' => $listing->category_id,
            'description' => $listing->description,
            'location_id' => 999999,
            'price' => '10000 тг/ч',
        ]);

        $response->assertSessionHasErrors(['location_id']);
        expect($listing->refresh())->status->toBe(ListingStatus::Draft);
    });

    test('категория чужого типа не принимается', function () {
        $listing = Listing::factory()->create();

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'type' => ListingType::Service->value,
            'category_id' => categoryNamed('Автокран')->id,
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '10000 тг/ч',
        ]);

        $response->assertSessionHasErrors(['category_id']);
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

    test('смена типа на услугу молча сбрасывает марку', function () {
        $listing = Listing::factory()->create(['brand_id' => brandNamed('Hitachi')->id]);

        $response = $this->post(portalLinks()->updateUrl($listing), supplierListingPayload($listing, [
            'type' => ListingType::Service->value,
            'category_id' => categoryNamed('Сварщик', ListingType::Service)->id,
            'brand_id' => $listing->brand_id,
        ]));

        $response->assertSessionDoesntHaveErrors();
        expect($listing->refresh())
            ->status->toBe(ListingStatus::PendingModeration)
            ->brand_id->toBeNull();
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
