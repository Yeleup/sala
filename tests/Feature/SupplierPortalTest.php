<?php

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Contact;
use App\Models\Listing;
use App\Services\Ai\CtaLinkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function portalLinks(): CtaLinkBuilder
{
    return app(CtaLinkBuilder::class);
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
            ->category->name->toBe('Сварщик')
            ->location->name->toBe('г.Алматы')
            ->location_detail->toBe('Ауэзовский район')
            ->price->toBe('5000 тг/ч');
    });

    test('исправленное отклонённое объявление уходит на модерацию повторно', function () {
        $listing = Listing::factory()->rejected()->create();

        $this->post(portalLinks()->updateUrl($listing), [
            'type' => $listing->type->value,
            'category_id' => $listing->category_id,
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '12000 тг/ч',
        ]);

        expect($listing->refresh())->status->toBe(ListingStatus::PendingModeration);
    });

    test('для отправки на проверку обязательны все бизнес-поля', function () {
        $listing = Listing::factory()->create(['category_id' => null, 'price' => null]);

        $response = $this->post(portalLinks()->updateUrl($listing), [
            'type' => $listing->type->value,
            'category_id' => '',
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => '',
        ]);

        $response->assertSessionHasErrors(['category_id', 'price']);
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

    test('опубликованное объявление сохранить нельзя', function () {
        $listing = Listing::factory()->published()->create();

        $this->post(portalLinks()->updateUrl($listing), [
            'type' => $listing->type->value,
            'category_id' => categoryNamed('Другая категория')->id,
            'description' => $listing->description,
            'location_id' => $listing->location_id,
            'price' => $listing->price,
        ])->assertForbidden();

        expect($listing->refresh())->status->toBe(ListingStatus::Published);
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
