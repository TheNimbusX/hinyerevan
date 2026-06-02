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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET'),
    ],

    /*
    | Social login (OAuth) via Laravel Socialite. A provider only appears on the
    | sign-in screen when its client id + secret are configured. The redirect URI
    | must match exactly what is registered in each provider's developer console.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/facebook/callback'),
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'page_url' => env('FACEBOOK_PAGE_URL', 'https://www.facebook.com/HinYerevanCom/'),
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        'app_id' => env('FACEBOOK_APP_ID', env('FACEBOOK_CLIENT_ID')),
        'site_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://127.0.0.1:8000')), '/'),
    ],

    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => env('YANDEX_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/yandex/callback'),
    ],

    'vkontakte' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/vkontakte/callback'),
    ],

    'odnoklassniki' => [
        'client_id' => env('OK_CLIENT_ID'),
        'client_secret' => env('OK_CLIENT_SECRET'),
        // Odnoklassniki signs API calls with the application public key.
        'client_public' => env('OK_CLIENT_PUBLIC'),
        'redirect' => env('OK_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/odnoklassniki/callback'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI', rtrim(env('OAUTH_REDIRECT_BASE', 'http://127.0.0.1:8000'), '/') . '/api/auth/social/instagram/callback'),
    ],

    // uLogin social-login aggregator. `host` overrides the domain passed to
    // ulogin.ru/token.php (leave empty to derive it from the request origin).
    'ulogin' => [
        'host' => env('ULOGIN_HOST', ''),
        // e.g. http://user:pass@host:port or socks5://host:port — used only if set.
        'proxy' => env('ULOGIN_PROXY', ''),
    ],

    // Outbound proxy for OAuth token exchange + reCAPTCHA verification. Useful in
    // dev when the backend network blocks a provider (e.g. Google) but a VPN/proxy
    // is available. Format: http://host:port or socks5://host:port. Empty = direct.
    'oauth' => [
        'proxy' => env('OAUTH_PROXY', ''),
    ],

    /*
    | translate.driver: mymemory (free, no key) | libretranslate (self-host or libretranslate.com)
    */
    'translate' => [
        'enabled' => env('TRANSLATE_ENABLED', false),
        'driver' => env('TRANSLATE_DRIVER', 'mymemory'),
        'source' => env('TRANSLATE_SOURCE', 'hy'),
        'max_api_calls_per_request' => (int) env('TRANSLATE_MAX_API_CALLS', 24),
        'parallel_batch' => (int) env('TRANSLATE_PARALLEL_BATCH', 12),
        'libretranslate_url' => env('LIBRETRANSLATE_URL', 'https://libretranslate.com'),
        'libretranslate_key' => env('LIBRETRANSLATE_API_KEY'),
    ],

];
