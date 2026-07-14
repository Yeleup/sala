<?php

use App\Http\Controllers\LocationSearchController;
use App\Http\Controllers\SupplierListingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Location dictionary autocomplete: public read-only reference data used
 * by the supplier web form.
 */
Route::get('/locations/search', LocationSearchController::class)->name('locations.search');

/**
 * Supplier web portal (Module 3). Every route requires a valid signed URL:
 * personal, time-limited links issued by CtaLinkBuilder and handed off from
 * WhatsApp (or embedded into the portal pages themselves). The edit route
 * keeps its historical path so previously issued CTA links stay valid.
 */
Route::middleware('signed')->name('supplier.listings.')->group(function (): void {
    Route::get('/supplier/{contact}/listings', [SupplierListingController::class, 'index'])
        ->whereNumber('contact')->name('index');
    Route::post('/supplier/{contact}/name', [SupplierListingController::class, 'updateName'])
        ->whereNumber('contact')->name('update-name');
    Route::get('/supplier/listings/{listing}/edit', [SupplierListingController::class, 'edit'])
        ->whereNumber('listing')->name('edit');
    Route::post('/supplier/listings/{listing}', [SupplierListingController::class, 'update'])
        ->whereNumber('listing')->name('update');
    Route::post('/supplier/listings/{listing}/archive', [SupplierListingController::class, 'archive'])
        ->whereNumber('listing')->name('archive');
});
