<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a demo, a window may be someone else than the browser session (App\Support\Demo): its address names their token
 * (/as/<token>), and its requests carry it as a bearer. This request is theirs. The session stays as it was.
 */
class DemoWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::on()) {
            $token = $request->route('token') ?? $request->bearerToken();
            if (null !== $token && null !== ($user = Demo::userOf($token))) {
                Auth::setUser($user);
            }
        }

        return $next($request);
    }
}
