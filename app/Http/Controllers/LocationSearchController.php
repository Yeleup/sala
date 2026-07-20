<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\Locations\LocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete for the «Место» dropdown of the web pages (the customer
 * catalog filter and the supplier listing form): public read-only KATO
 * dictionary suggestions. An empty query opens the menu with the top of
 * the tree — the cities of republican significance and the oblasts — so
 * a place can be picked without typing; a typed query suggests by name
 * prefix (a matched node brings its subtree along), tolerating close
 * distortions of the spelling.
 */
class LocationSearchController extends Controller
{
    private const int MAX_RESULTS = 10;

    public function __invoke(Request $request, LocationResolver $resolver): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        $locations = $query === ''
            ? $resolver->topLevel()
            : $resolver->suggest($query, self::MAX_RESULTS);

        $chains = Location::chainsFor($locations);

        return response()->json($locations->map(fn (Location $location): array => [
            'id' => $location->id,
            'name' => $location->name,
            'chain' => $chains[$location->id],
        ])->all());
    }
}
