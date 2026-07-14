<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\Locations\LocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete for the supplier web form: public read-only dictionary
 * lookup. A matched node brings its subtree along, so typing a city also
 * offers the city's districts.
 */
class LocationSearchController extends Controller
{
    private const int MAX_RESULTS = 10;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, LocationResolver $resolver): JsonResponse
    {
        $locations = $resolver->suggest((string) $request->query('q', ''), self::MAX_RESULTS);

        return response()->json($locations->map(fn (Location $location): array => [
            'id' => $location->id,
            'label' => $location->label(),
        ])->all());
    }
}
