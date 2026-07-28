<?php

use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('предпросмотр закрыт для незалогиненных — гость уходит на вход в админку', function () {
    $listing = Listing::factory()->published()->create();

    $this->get(route('moderation.listings.preview', $listing))
        ->assertRedirect(route('filament.admin.auth.login'));
});

describe('оператор смотрит объявление глазами заказчика', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    test('предпросмотр рисует ту же страницу, что видит заказчик', function () {
        $listing = Listing::factory()->published()->create([
            'title' => 'Аренда автокрана 25 т',
            'description' => 'Кран 25 тонн, стрела 28 м',
            'price' => '20000 тг/ч',
            'category_id' => categoryNamed('Автокран')->id,
            'location_id' => locationNamed('г.Шымкент')->id,
        ]);
        ListingMedia::factory()->for($listing)->create();

        $this->get(route('moderation.listings.preview', $listing))
            ->assertOk()
            ->assertViewIs('customer.listing-show')
            ->assertSee('Аренда автокрана 25 т')
            ->assertSee('Кран 25 тонн, стрела 28 м')
            ->assertSee('20000 тг/ч')
            ->assertSee('г.Шымкент');
    });

    test('в предпросмотре нет «Выбрать» — заявку от лица заказчика оператор не оформляет', function () {
        $listing = Listing::factory()->published()->create();

        // Судим по отсутствию самой формы, а не слова: подсказка сама
        // объясняет оператору, почему «Выбрать» здесь нет.
        $this->get(route('moderation.listings.preview', $listing))
            ->assertOk()
            ->assertDontSee('/select')
            ->assertDontSee('<form', escape: false)
            ->assertSee('Кнопки «Выбрать» здесь нет', escape: false);
    });

    test('предпросмотр открывается на любом статусе — в этом весь смысл', function (string $state) {
        $listing = Listing::factory()->{$state}()->create();

        $this->get(route('moderation.listings.preview', $listing))->assertOk();
    })->with(['publishable', 'pendingModeration', 'rejected', 'archived']);

    test('предпросмотр предупреждает, когда объявления в каталоге ещё нет', function () {
        $draft = Listing::factory()->publishable()->create();
        $published = Listing::factory()->published()->create();

        $this->get(route('moderation.listings.preview', $draft))
            ->assertSee('Сейчас в поиске и каталоге этого объявления нет.');

        $this->get(route('moderation.listings.preview', $published))
            ->assertDontSee('Сейчас в поиске и каталоге этого объявления нет.');
    });

    test('ссылка «назад» из предпросмотра ведёт к объявлению в админке', function () {
        $listing = Listing::factory()->published()->create();

        $this->get(route('moderation.listings.preview', $listing))
            ->assertSee('К объявлению в админке')
            ->assertSee(EditListing::getUrl(['record' => $listing]), escape: false);
    });

    test('кнопка предпросмотра есть и в карточке, и в списке', function () {
        $listing = Listing::factory()->publishable()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->assertActionVisible('preview');

        Livewire::test(ListListings::class)
            ->filterTable('status', 'draft')
            ->assertActionVisible(TestAction::make('preview')->table($listing));
    });
});
