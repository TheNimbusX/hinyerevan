<?php

/**
 * socialiteproviders/apple 5.5.x declares refreshToken(): ResponseInterface but
 * laravel/socialite 5.x expects refreshToken($refreshToken) without a return
 * type — patch the vendor file after every composer install.
 */
$path = dirname(__DIR__) . '/vendor/socialiteproviders/apple/Provider.php';

if (! is_file($path)) {
    exit(0);
}

$content = file_get_contents($path);
$fixed = str_replace(
    'public function refreshToken(string $refreshToken): ResponseInterface',
    'public function refreshToken($refreshToken)',
    $content,
);

if ($content !== $fixed) {
    file_put_contents($path, $fixed);
    fwrite(STDERR, "Patched socialiteproviders/apple Provider::refreshToken signature.\n");
}
