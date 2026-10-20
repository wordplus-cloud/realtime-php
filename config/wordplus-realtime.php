<?php

return [
    // The app's key and secret, from the WordPlus Cloud account (Apps), and a key of the app's own that never leaves its
    // servers: openssl rand -hex 32.
    'site' => env('WORDPLUS_SITE'),
    'secret' => env('WORDPLUS_SECRET'),
    'local_key' => env('WORDPLUS_LOCAL_KEY'),

    // The routes the browser asks: POST {prefix}/auth, /calls and /keys, on these middleware (the session's user); and
    // POST {prefix}/webhook, which the realtime server signs, outside them. Any of them at an address of its own, in
    // place of {prefix}/{name}: 'calls' => '/check-call'.
    'routes' => [
        'enabled' => true,
        'prefix' => 'wordplus/realtime',
        'middleware' => ['web'],
        // 'auth' => '/permit/rooms',
        // 'calls' => '/check-call',
        // 'keys' => '/my/keys',
        // 'webhook' => '/hooks/wordplus',
    ],

    // The browser files: the package serves them from GET {prefix}/files/{version}/{name}, and the browser's settings
    // name the worker and the room client there. false, with 'worker' and 'rooms', for files the app serves itself
    // (npx wordplus-realtime copy public/).
    'files' => true,
    'worker' => null,
    'rooms' => null,

    // Where the keys' generations are kept, for rotateRoom() and rotateUser(), and kick(): a cache store that doesn't
    // evict (database, redis), or null for none.
    'generations' => env('WORDPLUS_REALTIME_GENERATIONS'),

    // Seal what goes through the realtime server.
    'seal' => true,

    // The broadcasting connection whose routes/channels.php decides the rooms no Realtime::room() owns: who joins, and
    // what each room is, from a channel's third argument ([ 'presence' => true, 'history' => 50, … ]).
    'broadcasting' => 'wordplus',

    'server' => env('WORDPLUS_REALTIME_SERVER', 'https://ws.wordplus.cloud'),
    'api' => env('WORDPLUS_REALTIME_API', 'https://rest.wordplus.cloud'),
];
