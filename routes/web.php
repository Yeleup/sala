<?php

use App\Http\Controllers\SupplierListingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Supplier web portal (Module 3). Every route requires a valid signed URL:
 * personal, time-limited links issued by CtaLinkBuilder and handed off from
 * WhatsApp (or embedded into the portal pages themselves). The edit route
 * keeps its historical path so previously issued CTA links stay valid.
 */
Route::middleware('signed')->name('supplier.listings.')->group(function (): void {
    Route::get('/supplier/{contact}/listings', [SupplierListingController::class, 'index'])
        ->whereNumber('contact')->name('index');
    Route::get('/supplier/listings/{listing}/edit', [SupplierListingController::class, 'edit'])
        ->whereNumber('listing')->name('edit');
    Route::post('/supplier/listings/{listing}', [SupplierListingController::class, 'update'])
        ->whereNumber('listing')->name('update');
    Route::post('/supplier/listings/{listing}/archive', [SupplierListingController::class, 'archive'])
        ->whereNumber('listing')->name('archive');
});
