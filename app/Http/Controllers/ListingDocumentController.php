<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The driver's licence snapshot for the operator's eyes. The file lives on
 * the non-public disk — it never renders to customers and has no public
 * URL — so the admin serves it through this authenticated route instead.
 */
class ListingDocumentController extends Controller
{
    public function __invoke(Listing $listing): StreamedResponse
    {
        $document = $listing->documents()->latest('id')->first();

        abort_if($document === null, 404);

        return Storage::disk($document->disk)->response($document->path);
    }
}
