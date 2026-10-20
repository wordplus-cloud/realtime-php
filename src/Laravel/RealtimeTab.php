<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel;

use Closure;
use Illuminate\Http\Request;

/**
 * The tab a request came from, as Laravel's toOthers() reads it: @wordplus/realtime's pages name their tab (`rt.tab`) in
 * X-Realtime-Tab, Laravel Echo's in X-Socket-ID. A request with the first and not the second gets the second, so
 * `broadcast( $event )->toOthers()` skips that tab.
 */
final class RealtimeTab
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tab = $request->headers->get('X-Realtime-Tab');

        if (is_string($tab) && '' !== $tab && !$request->headers->has('X-Socket-ID')) {
            $request->headers->set('X-Socket-ID', $tab);
        }

        return $next($request);
    }
}
