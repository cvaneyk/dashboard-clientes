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

    /*
    | Meta (Facebook) Marketing API. App en developers.facebook.com con el
    | producto "Marketing API". Solo hace falta configurarla para el método
    | OAuth; el token de System User se pega por cuenta desde /admin.
    | En la app, "Valid OAuth Redirect URIs" debe incluir:
    |   {APP_URL}/conexiones/meta/callback
    */
    'meta' => [
        'app_id'      => env('META_APP_ID'),
        'app_secret'  => env('META_APP_SECRET'),
        'api_version' => env('META_API_VERSION', 'v23.0'),
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

];
