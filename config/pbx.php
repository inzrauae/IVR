<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default package
    |--------------------------------------------------------------------------
    |
    | Slug of the package new self-registered organizations start their trial on.
    |
    */

    'default_package' => env('PBX_DEFAULT_PACKAGE', 'starter'),

    /*
    |--------------------------------------------------------------------------
    | PBX drivers
    |--------------------------------------------------------------------------
    |
    | "asterisk" talks to a real Asterisk server over ARI/AMI. "simulator" is a
    | development/test stand-in and refuses to run when APP_ENV=production.
    |
    */

    'drivers' => [
        'asterisk' => App\Pbx\Drivers\AsteriskDriver::class,
        'simulator' => App\Pbx\Drivers\SimulatorDriver::class,
    ],

    'http_timeout' => (int) env('PBX_HTTP_TIMEOUT', 5),

];
