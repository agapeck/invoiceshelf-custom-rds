<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CompanyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('user_company')) {
            $user = $request->user();
            $requestedCompany = $request->header('company');

            if (! $user) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }

            if ($requestedCompany) {
                if (! $user->hasCompany($requestedCompany)) {
                    return response()->json(['error' => 'invalid_company_context'], 403);
                }
            } else {
                $companies = $user->companies()->pluck('companies.id');

                if ($companies->count() !== 1) {
                    return response()->json(['error' => 'company_header_required'], 422);
                }

                $request->headers->set('company', $companies->first());
            }
        }

        return $next($request);
    }
}
