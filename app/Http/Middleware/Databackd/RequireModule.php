<?php

namespace App\Http\Middleware\Databackd;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AFV (S8): the screen belongs to a AFV module that must be in the company's package.
 * Use in a route file: ->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')
 * Answers 403 with a clear message otherwise.
 */
class RequireModule
{

    public function handle(Request $request, Closure $next, string $module): Response
    {
        // S6/P1: CompanyScope filters only while company() is known. A user without a company (superadmin) would see the rows
        // of ALL companies on these screens, so such a session never reaches them (today it is also stopped by the module check).
        abort_if(!company(), 403);

        if (!databackd_module($module)) {
            // the controller (which sets the user's language) has not run yet - the message must be in the user's language
            if (!empty(user()->locale)) {
                \Illuminate\Support\Facades\App::setLocale(user()->locale);
            }

            // S12: the package has the module, only this role does not get it (a client outside "Teams", or the module switched off for
            // employees in the module settings) - a plain "access denied"; "not in your package" would send the user to the wrong person
            if (databackd_company_module((int)company()->id, $module)) {
                abort(403, __('messages.permissionDenied'));
            }

            abort(403, __('databackd-packages.module_not_in_package', [
                'module' => __('databackd-packages.modules.' . $module . '.name'),
            ]));
        }

        return $next($request);
    }

}
