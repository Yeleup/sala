<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\Locations\LocationName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete for the supplier web form: public read-only dictionary
 * lookup, top matches by the normalized name prefix.
 */
class LocationSearchController extends Controller
{
    private const int MAX_RESULTS = 10;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $key = LocationName::searchKey((string) $request->query('q', ''));

        if ($key === '') {
            return response()->json([]);
        }

        $locations = Location::query()
            ->where('search_name', 'like', $key.'%')
            ->orderBy('depth')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json($locations->map(fn (Location $location): array => [
            'id' => $location->id,
            'label' => $location->label(),
        ])->all());
    }
}
