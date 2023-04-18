<?php

namespace App\Http\Middleware\Databackd;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AFV (S8): like RequireModule, for the stateless API (no session user). Must run after FinApiTokenAuth,
 * which stores the company id of the token in the request attributes (fin_company_id).
 * Usage: ->middleware(RequireCompanyModule::class . ':databackd_integrations'). Answers 403 JSON.
 */
class RequireCompanyModule
{

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $companyId = (int)$request->attributes->get('fin_company_id');

        if (!$companyId || !databackd_company_module($companyId, $module)) {
            return response()->json(['message' => 'The package of this company does not include the module ' . $module], 403);
        }

        return $next($request);
    }

}
