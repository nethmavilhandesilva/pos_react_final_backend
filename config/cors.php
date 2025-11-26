<?php

return [
    // config/cors.php

'paths' => ['api/*', 'suppliers/*'], // Ensure your paths cover the endpoint
'allowed_methods' => ['*'], // MUST allow GET and OPTIONS
'allowed_origins' => ['*'], // Or your frontend domain (e.g., 'http://localhost:3000')
'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With'], // CRITICAL: Authorization must be listed!
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => false, // Set to true if you use cookies/session based auth
];
