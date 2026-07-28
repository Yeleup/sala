<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use Illuminate\View\View;

/**
 * «Посмотреть объявление» from the admin: the listing rendered by the very
 * page the customer sees, so the operator moderates what will actually be
 * published instead of guessing from the form fields.
 *
 * Two things separate it from the customer's own page: it opens for any
 * status (the point is to look before publishing) and it never offers
 * «Выбрать» — placing a request is the customer's act, and the operator
 * does not act on a contact's behalf.
 */
class ListingPreviewController extends Controller
{
    public function __invoke(Listing $listing): View
    {
        $listing->load(['supplier', 'category', 'brand', 'location', 'photos']);

        $note = 'Предпросмотр: так объявление видит заказчик. Кнопки «Выбрать» здесь нет — заявку от лица заказчика оператор не оформляет.';

        if (! Listing::query()->searchable()->whereKey($listing->getKey())->exists()) {
            $note .= ' Сейчас в поиске и каталоге этого объявления нет.';
        }

        return view('customer.listing-show', [
            'listing' => $listing,
            'backUrl' => ListingResource::getUrl('edit', ['record' => $listing]),
            'backLabel' => 'К объявлению в админке',
            'preview' => true,
            'previewNote' => $note,
        ]);
    }
}
