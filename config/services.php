<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // docs/product/pixelforge-api.md. One API key (and signing secret) per environment.
    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'api_key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        // Seconds a webhook signature timestamp may differ from now (docs/adr/0008).
        'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 10),
        'concurrency_limit' => env('PIXELFORGE_CONCURRENCY_LIMIT', 3),
        'requests_per_minute' => env('PIXELFORGE_REQUESTS_PER_MINUTE', 60),
        'max_submit_attempts' => env('PIXELFORGE_MAX_SUBMIT_ATTEMPTS', 5),
        'concurrency_retry_seconds' => env('PIXELFORGE_CONCURRENCY_RETRY_SECONDS', 5),
        // Seconds since creation after which an unconfirmed submission is failed locally (docs/specs/008).
        'submit_give_up_seconds' => env('PIXELFORGE_SUBMIT_GIVE_UP_SECONDS', 86400),
        // Seconds without change before a queued or processing generation is polled (docs/adr/0011).
        'reconcile_after_seconds' => env('PIXELFORGE_RECONCILE_AFTER_SECONDS', 120),
    ],

];
