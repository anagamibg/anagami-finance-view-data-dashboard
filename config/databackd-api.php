<?php

/*
 * Limits and switches of the AFV REST API (POST /api/databackd/fin-entries).
 * Read with config('databackd-api.<key>', <default>) - the code works with the defaults when this file is not (yet) in a cached config.
 */

return [

    // Reject plain-HTTP calls when APP_URL is https:// (the token must never travel unencrypted).
    // Set DATABACKD_API_REQUIRE_HTTPS=false only when TLS is terminated in front of the app and X-Forwarded-Proto is not passed on.
    'require_https' => env('DATABACKD_API_REQUIRE_HTTPS', true),

    // Maximum request body in bytes (checked from Content-Length before the body is parsed, and again on the received body).
    'max_body_bytes' => 2 * 1024 * 1024,

    // Maximum records in one request.
    'max_records' => 1000,

    // Maximum number of NEW dimension values (region, segment, product group, cost centre) one request may create.
    'max_new_dimensions' => 200,

    'rate' => [
        // Requests per token per minute (successful authentication).
        'per_token_per_minute' => 60,

        // Failed authentications per client IP per minute; after that the IP gets 429 for the rest of the minute.
        'failed_per_ip_per_minute' => 10,

        // Records per token per hour (a leaked token cannot flood the database).
        'records_per_token_per_hour' => 50000,
    ],

];
