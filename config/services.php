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

    'landing' => [
        'webhook_url' => env('LANDING_WEBHOOK_URL'),
        'sms_webhook_url' => env('LANDING_SMS_WEBHOOK_URL'),
        'sms_verify_ssl' => env('LANDING_SMS_VERIFY_SSL', true),
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

    'kaman' => [
        'ssl_verify' => filter_var(env('KAMAN_SSL_VERIFY', 'false'), FILTER_VALIDATE_BOOLEAN),
        /** API host TLD: dev → {name}.kaman.dev, rest → {name}.kaman.rest */
        'api_tld' => env('KAMAN_API_TLD', 'dev'),
    ],

    'haat' => [
        'cdn_base_url' => rtrim((string) env('HAAT_CDN_BASE_URL', ''), '/'),
        'menu_api_url' => env('HAAT_MENU_API_URL'),
        'ssl_verify' => filter_var(env('HAAT_SSL_VERIFY', env('KAMAN_SSL_VERIFY', 'false')), FILTER_VALIDATE_BOOLEAN),
    ],

    'kaman_agents' => [
        'base_url' => env('KAMAN_AGENTS_BASE_URL', 'https://kamanagents-production.up.railway.app'),
        'username' => env('KAMAN_AGENTS_USERNAME', 'yamen'),
        'password' => env('KAMAN_AGENTS_PASSWORD', 'yamen123'),
        'ssl_verify' => filter_var(env('KAMAN_AGENTS_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
        'lead_source' => env('KAMAN_AGENTS_LEAD_SOURCE', 'whatsapp'),
        'lead_idle_minutes' => (int) env('KAMAN_AGENTS_LEAD_IDLE_MINUTES', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'mowahada_api_key' => env('OPENAI_MOWAHADA_API_KEY'),
        'dima_api_key' => env('OPENAI_DIMA_API_KEY'),
        'aswatkum_api_key' => env('OPENAI_ASWATKUM_API_KEY'),
        'verify_ssl' => filter_var(
            env('OPENAI_VERIFY_SSL', env('OPENAI_SSL_VERIFY', env('APP_ENV') === 'production' ? 'true' : 'false')),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

];
