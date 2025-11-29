<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // 'paths' determines which routes the CORS middleware should run on.
    'paths' => [
        'api/*',                // Covers /api/sales, /api/customers, /api/items, etc.
        'api/get-loan-amount',  // Explicitly covers the loan amount route if it's under /api
        'sales/*',              // Covers specific PUT/DELETE sales routes if they aren't under 'api/*'
        'suppliers/*',
        'customers/*',
        'items/*',
        'get-loan-amount',      // Covers the root version of the loan amount route if /api is not the prefix
    ],

    // Allowed HTTP methods. '*' allows all methods (GET, POST, PUT, DELETE, OPTIONS).
    'allowed_methods' => ['*'], 

    // CRITICAL FIX: Ensure all frontend origins are listed EXACTLY.
    // Since 'supports_credentials' is true, '*' is NOT allowed here.
    'allowed_origins' => [
        // Your development machine variants:
        'http://localhost:5173', 
        'http://127.0.0.1:5173',
        
        // Other development origins you might be using:
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        
        // The backend host itself (often not necessary, but harmless)
        'http://127.0.0.1:8000', 
    ],

    // CRITICAL: Ensure 'X-CSRF-TOKEN' is allowed for POST/PUT/DELETE requests.
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 0,

    // CRITICAL: Must be 'true' if your frontend sends cookies (i.e., you use Laravel Sanctum).
    'supports_credentials' => true, 
];