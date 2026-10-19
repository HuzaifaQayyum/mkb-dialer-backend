<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make every /api route answer in JSON.
 *
 * Without an Accept: application/json header Laravel treats a validation
 * failure as a browser form post and answers 302 to "/" instead of 422 with
 * the field errors. The frontend's fetch helper sends Content-Type but not
 * Accept, so every validation error arrived as an opaque redirect.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
