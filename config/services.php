<?php

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

    'whatsapp' => [
        'live_enabled' => env('WHATSAPP_LIVE_ENABLED', false),
        'graph_version' => env('WHATSAPP_GRAPH_API_VERSION', env('WHATSAPP_GRAPH_VERSION', 'v26.0')),
        'business_account_id' => env('WHATSAPP_BUSINESS_ID', env('WHATSAPP_BUSINESS_ACCOUNT_ID')),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN', env('PERMANENT_WHATSAPP_ACCESS_TOKEN')),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN')),
    ],

    'payments' => [
        'driver' => env('PAYMENT_DRIVER', 'ecofy'),
        'live_enabled' => env('PAYMENTS_LIVE_ENABLED', false),
    ],

    'ecofy' => [
        'base_url' => env('ECOFY_BASE_URL', 'https://ecofy.areno.co.tz'),
        'api_key' => env('ECOFY_API_KEY'),
        'merchant_id' => env('ECOFY_MERCHANT_ID'),
        'webhook_secret' => env('ECOFY_WEBHOOK_SECRET'),
        'webhook_url' => env('ECOFY_WEBHOOK_URL', env('APP_URL').'/api/v1/webhooks/payments/ecofy'),
        'payout_channel' => env('ECOFY_PAYOUT_CHANNEL', 'MPESA'),
    ],

    'nextsms' => [
        'enabled' => env('NEXTSMS_ENABLED', false),
        'base_url' => env('NEXTSMS_BASE_URL', 'https://messaging-service.co.tz'),
        'username' => env('NEXTSMS_USERNAME'),
        'password' => env('NEXTSMS_PASSWORD'),
        'sender_id' => env('NEXTSMS_SENDER_ID', 'Kijumbe'),
    ],

    'selcom' => [
        'base_url' => env('SELCOM_BASE_URL'),
        'api_key' => env('SELCOM_API_KEY'),
        'api_secret' => env('SELCOM_API_SECRET'),
        'vendor' => env('SELCOM_VENDOR'),
        'float_pin' => env('SELCOM_FLOAT_PIN'),
        'c2b_token' => env('SELCOM_C2B_TOKEN'),
        'initiator_msisdn' => env('SELCOM_INITIATOR_MSISDN'),
        'wallet_cashin_utility_code' => env('SELCOM_WALLET_CASHIN_UTILITY_CODE', 'CASHIN'),
    ],

];
