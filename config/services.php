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

    'dereu' => [
        'base_url' => env('DEREU_BASE_URL', 'https://api.dereu.noderail.io/api/v1'),
        'platform_key' => env('DEREU_PLATFORM_KEY'),
        'webhook_secret' => env('DEREU_WEBHOOK_SECRET'),
        'external_id' => env('DEREU_EXTERNAL_ID'),
        'connect' => [
            'url' => env('DEREU_CONNECT_URL', 'https://connect.dereu.io/connect'),
            'signing_secret' => env('DEREU_CONNECT_SECRET'),
            'key_prefix' => env('DEREU_CONNECT_PREFIX'),
        ],
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
