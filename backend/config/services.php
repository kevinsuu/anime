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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'jwt' => [
        'secret' => env('JWT_SECRET', 'dev-only-change-me'),
        'ttl_seconds' => (int) env('JWT_TTL_SECONDS', 3600),
        'refresh_ttl_seconds' => (int) env('JWT_REFRESH_TTL_SECONDS', 60 * 60 * 24 * 30),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
    ],

    'dev_auth_bypass' => (bool) env('DEV_AUTH_BYPASS', false),

    'acgsecrets' => [
        'base_url' => rtrim(env('ACGSECRETS_BASE_URL', 'https://acgsecrets.hk'), '/'),
        'user_agent' => env('ACGSECRETS_USER_AGENT', 'anime-tracker/1.0 (+https://github.com/anime-tracker)'),
        'min_delay_ms' => (int) env('ACGSECRETS_MIN_DELAY_MS', 1000),
        'max_delay_ms' => (int) env('ACGSECRETS_MAX_DELAY_MS', 3000),
        'retries' => (int) env('ACGSECRETS_RETRIES', 2),
        'retry_delay_ms' => (int) env('ACGSECRETS_RETRY_DELAY_MS', 1000),
    ],

    'bangumi' => [
        'base_url' => rtrim(env('BANGUMI_API_BASE_URL', 'https://api.bgm.tv'), '/'),
        'user_agent' => env('BANGUMI_USER_AGENT', 'anime-tracker/1.0'),
        'min_delay_ms' => (int) env('BANGUMI_MIN_DELAY_MS', 500),
        'max_delay_ms' => (int) env('BANGUMI_MAX_DELAY_MS', 1000),
        'retries' => (int) env('BANGUMI_RETRIES', 2),
        'retry_delay_ms' => (int) env('BANGUMI_RETRY_DELAY_MS', 1000),
    ],

    'http' => [
        'timeout_seconds' => max(1, (int) env('HTTP_TIMEOUT_SECONDS', 10)),
    ],

    // 個人觀看清單(database/seed/mylist/watched.json)的擁有者;
    // 設定後 anime:import-acgsecrets 會自動把清單標記為此使用者已看過。
    'mylist' => [
        'owner_email' => env('MYLIST_OWNER_EMAIL', ''),
    ],

];
