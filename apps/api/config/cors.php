<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Not '*': the frontend and API are same-origin in every real
    // deployment (Nginx serves both — see docs/PRODUCTION_ARCHITECTURE.md),
    // so this only matters as defense-in-depth, and a wildcard origin is
    // rejected by browsers anyway once supports_credentials is true.
    //
    // CORS_ALLOWED_ORIGINS is a comma-separated, explicit list. It defaults
    // to FRONTEND_URL, so production keeps exactly one origin. Local
    // development adds the directly published web port (see the `api`
    // service in docker-compose.yml) — still an explicit list, never '*'.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:8080'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
