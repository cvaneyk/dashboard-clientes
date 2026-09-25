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

        /*
        | Qué cuenta como "lead" en el informe del cliente. Meta devuelve en
        | 'actions' una lista de tipos de conversión, y cada cuenta mide de una
        | forma: formularios de Lead Ads ('lead') o registros en la web con
        | píxel ('complete_registration' y su variante de píxel).
        |
        | Es una LISTA POR PRIORIDAD, no una suma: se usa el primer tipo que
        | aparezca en la fila. Sumarlos duplicaría, porque Meta devuelve a la
        | vez el agregado ('complete_registration') y el específico del píxel.
        |
        | Cada Cuenta puede sobrescribir esto en su ficha de /admin.
        */
        'tipos_conversion' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'META_TIPOS_CONVERSION',
                'lead,complete_registration,offsite_conversion.fb_pixel_complete_registration,onsite_conversion.lead_grouped'
            ))
        ))),
    ],

    /*
    | Google Ads API. A diferencia de Meta, aquí hay TRES piezas de agencia que
    | son comunes a todos los clientes y viven en el .env del servidor:
    |
    |   - developer_token: se pide desde la cuenta de administrador (MCC) y lo
    |     aprueba Google. Es el mismo para toda la agencia.
    |   - client_id / client_secret: credenciales OAuth de un proyecto de Google
    |     Cloud con la Google Ads API habilitada. En "URIs de redirección
    |     autorizados" debe estar {APP_URL}/conexiones/google/callback
    |   - login_customer_id: la MCC de OliMedia (solo dígitos, sin guiones). Se
    |     manda en la cabecera login-customer-id al leer una cuenta de cliente a
    |     través de la MCC. Cada Cuenta puede sobrescribirlo.
    |
    | Lo único propio de cada cliente es su customer_id (el external_id de la
    | Cuenta) y el refresh token de quien autorizó.
    */
    'google_ads' => [
        'developer_token'   => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id'         => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret'     => env('GOOGLE_ADS_CLIENT_SECRET'),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        // Debe ser una versión viva de la API; Google retira las antiguas.
        'api_version'       => env('GOOGLE_ADS_API_VERSION', 'v21'),
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
