<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use WordPlus\Realtime\Realtime;

/** POST {prefix}/auth, /calls and /keys, for the signed-in user of the session; /webhook, from the realtime server; and GET {prefix}/files/…, the browser files. */
final class RealtimeController
{
    public function __invoke(Request $request, Realtime $realtime, string $action): JsonResponse
    {
        $user = $request->user()?->getAuthIdentifier();
        $answer = $realtime->answerRequest($request->method(), (string) $request->header('Content-Type', ''), $action, (string) $request->getContent(), is_int($user) || is_string($user) ? $user : null);

        return new JsonResponse($answer['body'], $answer['status'], ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }

    /** GET {prefix}/files/{version}/{name}: a browser file the package carries, as Realtime::file() answers it. */
    public function file(Request $request, Realtime $realtime): Response
    {
        $answer = $realtime->file($request->method(), $request->getPathInfo(), $request->header('If-None-Match'));

        return null === $answer ? new Response('', 404) : new Response($answer['body'] ?? '', $answer['status'], $answer['headers']);
    }

    /** The realtime server's webhook: checked as it was sent, its events handed to the handlers Realtime::on() set. */
    public function webhook(Request $request, Realtime $realtime): JsonResponse|Response
    {
        $answer = $realtime->webhook((string) $request->getContent(), $request->headers->all(), $request->getRequestUri());

        return 204 === $answer['status'] ? new Response('', 204) : new JsonResponse($answer['body'], $answer['status'], ['Cache-Control' => 'no-store']);
    }
}
