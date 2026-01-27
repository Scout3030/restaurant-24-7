<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyApiKey
{
    /**
     * Handle an incoming request.
     *
     * Espera un header `X-API-Key` que coincida con el api_token de la empresa.
     */
    public function handle(Request $request, Closure $next): JsonResponse|\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
    {

        /** @var Company|null $company */
        $company = $request->route('company');

        $providedKey = $request->header('X-API-Key');

        if (!$company || !$company->api_token || !$providedKey || $company->api_token !== $providedKey) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}

