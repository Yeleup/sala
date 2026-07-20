<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the URL signature while ignoring every query parameter except
 * `expires`. The customer catalog link is signed for its path (the
 * personal contact id) and expiry only, so the search box, filters,
 * pagination and any junk appended by in-app browsers (fbclid and the
 * like) never invalidate the personal link. Ignoring everything instead
 * of enumerating the filter names keeps a later-added filter from
 * silently 403-ing the page. Filter values are non-sensitive display
 * state — leaving them unsigned is safe.
 */
class ValidateSignatureExceptQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignatureWhileIgnoring(fn (string $parameter): bool => $parameter !== 'expires')) {
            return $next($request);
        }

        throw new InvalidSignatureException;
    }
}
