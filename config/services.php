<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    // Membership base (login endpoint)
    'membership_base' => [
        'login_url' => env('USER_LOGIN_URL', 'https://stg-user.mina-toku.jp/users/login'),
    ],

    'auth' => [
        'site_name' => 'minatoku',
        'api_key' => env('AUTH_API_KEY'),
        'sync_api_key' => env('SYNC_API_KEY'),
        'verify_endpoint' => env('AUTH_VERIFY_ENDPOINT', 'http://host.docker.internal/api/users/auth/verify'),
        'user_organizations_endpoint' => env('AUTH_USER_ORGANIZATIONS_ENDPOINT', 'http://host.docker.internal/api/user_organizations'),
    ],

    'generate_pdf' => [
        'api_url' => env('GENERATE_PDF_API_URL', 'https://dev-documentsys.tstjsp.work/api/v1/generate-pdf'),
        'template_id' => env('GENERATE_PDF_TEMPLATE_ID', '4cbbb4e6-514b-48ff-a8bf-deb3c878a0f6'),
        'quotes_template_id' => env('QUOTE_PREVIEW_TEMPLATE_ID', '069595de-f31f-4019-a691-f1c1d43746a4'),
        'api_key' => env('GENERATE_PDF_API_KEY', ''),
    ],

    'sketch' => [
        'base_url' => env('SKETCH_BASE_URL', 'https://sketch.example.com/edit'),
    ],
];
