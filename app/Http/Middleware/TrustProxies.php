<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array
     */
    protected $proxies;

    /**
     * The current proxy header mappings.
     *
     * @var array
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Get the trusted proxies.
     *
     * @return array|string|null
     */
    protected function proxies()
    {
        $configured = env('TRUSTED_PROXIES');

        if ($configured === '*') {
            $this->proxies = '*';

            return $this->proxies;
        }

        if (! $configured) {
            $this->proxies = null;

            return $this->proxies;
        }

        $this->proxies = array_map('trim', explode(',', $configured));

        return $this->proxies;
    }
}
