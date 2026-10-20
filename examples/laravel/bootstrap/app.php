<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Who may listen to which private and presence channel. The browser asks the routes of wordplus/realtime-php
        // (POST /wordplus/realtime/auth), which ask these.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        // On a demo, a window may be one of its shop people other than the session (App\Support\Demo), before
        // `auth`, which the Laravel priority list would otherwise run first.
        $middleware->web(append: [App\Http\Middleware\DemoWindow::class]);
        $middleware->prependToPriorityList(before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, prepend: App\Http\Middleware\DemoWindow::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
