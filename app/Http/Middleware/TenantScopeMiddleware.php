<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Company;

class TenantScopeMiddleware
{
    public static $companyId = null;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-ID');
        
        if ($companyId) {
            self::$companyId = (int)$companyId;
        } else {
            // Default fallback to Vexa HQ
            $vexa = Company::where('slug', 'vexa-hq')->first();
            self::$companyId = $vexa ? $vexa->id : null;
        }

        return $next($request);
    }
}
