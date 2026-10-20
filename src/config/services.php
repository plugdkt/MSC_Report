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

    // ระบบยืนยันตัวตนกลาง MEDSCI ACC (คณะวิทยาศาสตร์การแพทย์)
    'medsci_sso' => [
        'base_url' => rtrim((string) env('MEDSCI_SSO_BASE_URL', 'https://www.medsci.up.ac.th/msc_acc'), '/'),
        'client_id' => env('MEDSCI_SSO_CLIENT_ID'),
        'client_secret' => env('MEDSCI_SSO_CLIENT_SECRET'),
        'verify_ssl' => (bool) env('MEDSCI_SSO_VERIFY_SSL', true),
        'timeout' => 15,
    ],

];
