<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use WordPlus\Realtime\Laravel\Facades\Realtime;

/**
 * The routes that start a call, the daily meeting and the live show: each the app's own check, then the token that lets
 * this user in. The page's elements ask them with token-url.
 */
class TokenController extends Controller
{
    // #region demo: members
    /**
     * GET /api/call-token?to=34&type=video: a call between two people of a shop, asked by <call-button> and <online-list>
     * as a call button is pressed.
     */
    public function call(Request $request): JsonResponse
    {
        $me = $request->user();
        $to = User::find((int) $request->query('to'));

        // Two people of the same shop, one of them staff: customers never call each other.
        abort_if(null === $to || $to->id === $me->id || $to->shop !== $me->shop || (!$me->staff && !$to->staff), 403);

        return response()->json(['token' => Realtime::callToken($me->id, $to->id, ['type' => $request->query('type')])]);
    }
    // #endregion

    // #region demo: meeting
    /**
     * GET /api/meeting-token?id=daily: the daily meeting of a shop, a group call, asked by <group-call-button> as it is
     * pressed. The name comes from the id: meeting:daily for the shop of the guides, meeting:7 for a demo shop.
     */
    public function meeting(Request $request): JsonResponse
    {
        $me = $request->user();
        $id = (string) $request->query('id', '');

        // Everyone in the shop, and nobody else.
        abort_unless($me->shop === ('daily' === $id ? 0 : (ctype_digit($id) ? (int) $id : null)), 403);

        return response()->json(['token' => Realtime::groupCallToken($me->id, 'meeting:'.$id)]);
    }
    // #endregion

    // #region demo: show
    /**
     * GET /api/show-token?id=mugs: the live show of a shop, asked by <live-stream>, again as each ten-minute token runs
     * out. The name comes from the id: show:mugs for the shop of the guides, show:7 for a demo shop.
     */
    public function show(Request $request): JsonResponse
    {
        $me = $request->user();
        $name = 'show:'.(string) $request->query('id', '');

        // 1. A show of a shop here: any other is refused.
        $s = Shop::ofRoom($name);
        abort_if(null === $s || !Shop::exists($s), 403);

        // 2. Ann of the shop presents it, and everyone else watches and writes in its chat, signed in or not.
        $host = null !== $me && $me->shop === $s && 'Ann' === $me->name;

        return response()->json(['token' => Realtime::streamToken($me?->id, $name, ($host ? ['role' => 'host'] : []) + ['settings' => ['reactions' => true]])]);
    }
    // #endregion
}
