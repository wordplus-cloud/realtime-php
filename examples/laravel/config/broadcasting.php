<?php

// Broadcasting through WordPlus Cloud: the `wordplus` driver of wordplus/realtime-php publishes each broadcast event to its
// channels, sealed, and routes/channels.php decides who may listen, as for any broadcaster.
return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'wordplus' => [
            'driver' => 'wordplus',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
