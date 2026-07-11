<?php

use App\Models\Listing;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Supplier web portal (CTA target). Placeholder until Module 3 builds the
 * real editing interface; the signed link and route name are already what
 * the bot hands off to, so the portal can replace this without touching
 * the AI assistant.
 */
Route::get('/supplier/listings/{listing}/edit', function (Listing $listing) {
    return response()->view('supplier.listing-edit-placeholder', ['listing' => $listing]);
})->middleware('signed')->name('supplier.listings.edit');
