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
    | Google Ads API. Piezas de agencia, comunes a todos sus clientes (en el
    | .env o, con agencias, en /admin → Mi agencia):
    |
    |   - client_id / client_secret: credenciales OAuth de un proyecto de Google
    |     Cloud con la Google Ads API habilitada y acceso Explorer o superior
    |     (desde el 9/9/2026 el nivel de acceso va en el proyecto; ya no hay
    |     developer token). En "URIs de redirección autorizados" debe estar
    |     {APP_URL}/conexiones/google/callback
    |   - login_customer_id: la MCC de la agencia (solo dígitos, sin guiones). Se
    |     manda en la cabecera login-customer-id solo en las conexiones por MCC
    |     (método `mcc`); cada conexión puede sobrescribirlo.
    |
    | Lo único propio de cada cliente es su customer_id (el external_id de la
    | Cuenta) y el refresh token de quien autorizó.
    */
    'google_ads' => [
        'client_id'         => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret'     => env('GOOGLE_ADS_CLIENT_SECRET'),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        // Debe ser una versión viva de la API; Google retira las antiguas.
        'api_version'       => env('GOOGLE_ADS_API_VERSION', 'v25'),
    ],

    /*
    | LinkedIn Ads (Marketing Developer Platform).
    |
    | Requiere una app en developer.linkedin.com asociada a una página de
    | empresa Y la aprobación del programa Marketing Developer Platform: sin
    | ella LinkedIn no concede los permisos r_ads / r_ads_reporting y la
    | conexión no puede leer nada.
    |
    | En "Authorized redirect URLs" de la app:
    |   {APP_URL}/conexiones/linkedin/callback
    |
    | api_version es la cabecera LinkedIn-Version, con formato AAAAMM. LinkedIn
    | retira versiones cada pocos meses; si las llamadas empiezan a fallar por
    | versión, se cambia aquí sin tocar código.
    */
    'linkedin_ads' => [
        'client_id'     => env('LINKEDIN_ADS_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_ADS_CLIENT_SECRET'),
        // LinkedIn mantiene activas ~12 meses de versiones y retira las viejas,
        // así que este valor CADUCA. Si empieza a fallar con "Requested version
        // ... is not active", se sube por .env sin tocar código.
        'api_version'   => env('LINKEDIN_ADS_API_VERSION', '202608'),

        /*
        | Permisos que se piden al autorizar. Tienen que coincidir EXACTAMENTE
        | con los que la app tenga concedidos (pestaña Auth → "OAuth 2.0
        | scopes"); si no, LinkedIn responde "The requested permission scope is
        | not valid" y no deja ni empezar.
        |
        | Configurable para poder ajustarlos sin desplegar código el día que
        | LinkedIn conceda un juego distinto al aprobar los productos.
        */
        'scopes' => env('LINKEDIN_ADS_SCOPES', 'r_ads r_ads_reporting'),

        /*
        | Qué cuenta como lead, por prioridad (mismo criterio que en Meta: se
        | usa el primer campo con valor, nunca la suma).
        |   oneClickLeads              → formularios de Lead Gen de LinkedIn
        |   externalWebsiteConversions → conversiones en la web (Insight Tag)
        | Cada Cuenta puede sobrescribirlo en su ficha.
        */
        'tipos_conversion' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LINKEDIN_ADS_TIPOS_CONVERSION', 'oneClickLeads,externalWebsiteConversions'))
        ))),
    ],

    /*
    | Spotify Ads API (v3).
    |
    | App en developer.spotify.com marcando "Ads API", con
    |   {APP_URL}/conexiones/spotify/callback
    | en "Redirect URIs". ADEMÁS hay que aceptar los términos de la Ads API con
    | el client ID en adsmanager.spotify.com/api-terms (tarda hasta una hora):
    | sin eso el OAuth funciona pero todas las llamadas a la API fallan.
    |
    | Lo único por cliente es el UUID de su cuenta publicitaria (external_id)
    | y el refresh token de quien autoriza.
    */
    'spotify_ads' => [
        'client_id'     => env('SPOTIFY_ADS_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_ADS_CLIENT_SECRET'),

        /*
        | Qué cuenta como lead, por prioridad (mismo criterio que en Meta: se
        | usa el primer campo con valor, nunca la suma). Son campos del informe
        | de Spotify: LEADS, SIGN_UPS, PURCHASES, CUSTOM_EVENT_1… Requieren el
        | píxel de Spotify en la web. Cada Cuenta puede sobrescribirlo.
        */
        'tipos_conversion' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SPOTIFY_ADS_TIPOS_CONVERSION', 'LEADS,SIGN_UPS'))
        ))),
    ],

    /*
    | OpenAI Ads (Advertiser API) no necesita nada aquí: cada cuenta
    | publicitaria genera su propia clave en Ads Manager → Settings y se pega
    | en la ficha de la Cuenta en /admin.
    */

    /*
    | Informes semanales por email. Destinatarios del resumen interno (todas las
    | cuentas, con gasto real y margen), separados por coma. Si se deja vacío,
    | se manda a todos los usuarios admin.
    */
    'informes' => [
        'admin' => env('INFORME_ADMIN_EMAIL'),
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
