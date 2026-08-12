<?php

use App\Http\Controllers\CustomerCatalogController;
use App\Http\Controllers\ListingDocumentController;
use App\Http\Controllers\ListingPreviewController;
use App\Http\Controllers\LocationSearchController;
use App\Http\Controllers\SupplierListingController;
use App\Http\Middleware\ValidateSignatureExceptQuery;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Static design-review mockup of the storefront UI (no real data).
 * Available only in the local environment.
 */
if (app()->environment('local')) {
    Route::view('/design-preview', 'storefront-design-preview')->name('design.preview');
}

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

/**
 * Moderator preview of a listing, rendered by the customer's own listing
 * page. Guarded by the admin panel's session, not by a signed link: it is
 * an operator's tool, and it opens a listing in any status.
 */
Route::middleware('auth')
    ->get('/moderation/listings/{listing}/preview', ListingPreviewController::class)
    ->whereNumber('listing')
    ->name('moderation.listings.preview');

/**
 * The driver's licence snapshot, stored on the non-public disk: an
 * operator's tool behind the admin session, 404 when the listing carries
 * no document.
 */
Route::middleware('auth')
    ->get('/moderation/listings/{listing}/document', ListingDocumentController::class)
    ->whereNumber('listing')
    ->name('moderation.listings.document');

/**
 * Customer web catalog (Modules 2–3): every published listing with search
 * and filters, opened from WhatsApp via a personal signed CTA link. The
 * catalog page validates only the path and expiry of its signature, so
 * the filter form and pagination never break the link; the select action
 * is a strictly signed personal URL.
 */
Route::name('customer.listings.')->group(function (): void {
    Route::get('/customer/{contact}/listings', [CustomerCatalogController::class, 'index'])
        ->middleware(ValidateSignatureExceptQuery::class)
        ->whereNumber('contact')->name('index');
    Route::get('/customer/{contact}/listings/{listing}', [CustomerCatalogController::class, 'show'])
        ->middleware(ValidateSignatureExceptQuery::class)
        ->whereNumber('contact')->whereNumber('listing')->name('show');
    Route::post('/customer/{contact}/listings/{listing}/select', [CustomerCatalogController::class, 'select'])
        ->middleware('signed')
        ->whereNumber('contact')->whereNumber('listing')->name('select');
});
