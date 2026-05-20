<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNightwatchSelfTestEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('overwatch.self_test.enabled'), 404);

        return $next($request);
    }
}
