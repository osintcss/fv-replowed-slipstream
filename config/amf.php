<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AMF request authentication
    |--------------------------------------------------------------------------
    |
    | Each game launch receives a signed token tied to the authenticated UID.
    | The legacy Flash client already sends this token with every AMF batch.
    |
    */
    'auth_key' => env('AMF_AUTH_KEY'),
    'token_ttl_seconds' => (int) env('AMF_TOKEN_TTL_SECONDS', 36000),

    // Keep malformed or abusive traffic from consuming unbounded parser,
    // database, or response-building resources. These defaults are far above
    // the normal client batch size while still placing a finite ceiling on it.
    'max_request_bytes' => (int) env('AMF_MAX_REQUEST_BYTES', 16 * 1024 * 1024),
    'max_batch_requests' => (int) env('AMF_MAX_BATCH_REQUESTS', 250),
    'ip_rate_limit_per_minute' => (int) env('AMF_IP_RATE_LIMIT_PER_MINUTE', 1200),
    'user_rate_limit_per_minute' => (int) env('AMF_USER_RATE_LIMIT_PER_MINUTE', 600),
];
