<?php

use App\Http\Middleware\CustomerPortalMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

test('customer portal middleware fails closed when customer is missing', function () {
    Auth::shouldReceive('guard')
        ->with('customer')
        ->twice()
        ->andReturnSelf();
    Auth::shouldReceive('user')
        ->once()
        ->andReturn(null);
    Auth::shouldReceive('logout')
        ->once();

    $middleware = new CustomerPortalMiddleware();

    $response = $middleware->handle(Request::create('/customer/test', 'GET'), function () {
        return response('ok', 200);
    });

    expect($response->getStatusCode())->toBe(401);
});
