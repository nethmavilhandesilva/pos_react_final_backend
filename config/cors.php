<?php

return [
    'paths' => ['api/*', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'], // allow all for local dev

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
