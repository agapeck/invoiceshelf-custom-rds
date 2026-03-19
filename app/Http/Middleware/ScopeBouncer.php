<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Silber\Bouncer\Bouncer;
use Symfony\Component\HttpFoundation\Response;

class ScopeBouncer
{
    /**
     * The Bouncer instance.
     *
     * @var \Silber\Bouncer\Bouncer
     */
    protected $bouncer;

    /**
     * Constructor.
     */
    public function __construct(Bouncer $bouncer)
    {
        $this->bouncer = $bouncer;
    }

    /**
     * Set the proper Bouncer scope for the incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $tenantId = (int) $request->header('company');
        if (! $tenantId || ! $user->hasCompany($tenantId)) {
            return response()->json(['error' => 'invalid_company_context'], 403);
        }

        $this->bouncer->scope()->to($tenantId);

        return $next($request);
    }
}
