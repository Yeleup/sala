<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDereuSignature
{
    /**
     * Verify the X-Dereu-Signature HMAC of the raw request body before it is parsed.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.dereu.webhook_secret');

        if (blank($secret)) {
            abort(503, 'Dereu webhook secret is not configured.');
        }

        $signature = $request->header('X-Dereu-Signature');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
            abort(401, 'Invalid Dereu webhook signature.');
        }

        return $next($request);
    }
}
