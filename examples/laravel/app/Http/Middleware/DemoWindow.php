<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a demo, a window may be someone else than the browser session (App\Support\Demo): its address names their token
 * (/as/<token>), and its requests carry it as a bearer. This request is theirs. The session stays as it was. No search
 * engine keeps a window (noindex): each new shop has new tokens, so it would find new addresses without end.
 *
 * And a new visitor opening the start page gets their shop there and then, with no redirect to /login and back, which a
 * search engine, keeping no cookie, would follow without end.
 */
class DemoWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::on() && $request->isMethod('GET') && $request->is('/') && Auth::guest()) {
            Demo::start($request);
        }
        if (Demo::on()) {
            $token = $request->route('token') ?? $request->bearerToken();
            if (null !== $token && null !== ($user = Demo::userOf($token))) {
                Auth::setUser($user);
            }
        }

        $response = $next($request);
        if (Demo::on() && null !== $request->route('token')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
