<?php

require '/var/www/hinyerevan/backend/vendor/autoload.php';
$app = require '/var/www/hinyerevan/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$g = app(App\Services\Facebook\FacebookGraphClient::class);
$appId = (string) (config('services.facebook.app_id') ?: config('services.facebook.client_id'));
$secret = (string) (config('services.facebook.app_secret') ?: config('services.facebook.client_secret'));
$pageId = trim((string) config('services.facebook.page_id'));
$userToken = trim((string) getenv('FBUSER'));

if (! $appId || ! $secret || ! $userToken) {
    fwrite(STDERR, "missing appId/secret/userToken\n");
    exit(1);
}

// 1) short-lived user token -> long-lived user token
$ex = $g->get('oauth/access_token', [
    'grant_type' => 'fb_exchange_token',
    'client_id' => $appId,
    'client_secret' => $secret,
    'fb_exchange_token' => $userToken,
]);
if (! $ex->ok()) {
    echo 'EXCHANGE_FAIL ' . $ex->status() . ': ' . $ex->body() . PHP_EOL;
    exit(1);
}
$longUser = (string) $ex->json('access_token');
echo 'LONG_USER_OK len=' . strlen($longUser) . PHP_EOL;

// 2) long-lived user token -> page token (inherits long life / no expiry for admins)
$acc = $g->get('me/accounts', [
    'fields' => 'id,name,access_token',
    'access_token' => $longUser,
]);
if (! $acc->ok()) {
    echo 'ACCOUNTS_FAIL ' . $acc->status() . ': ' . $acc->body() . PHP_EOL;
    exit(1);
}

$pageToken = null;
foreach ($acc->json('data') ?? [] as $row) {
    if ((string) ($row['id'] ?? '') === $pageId) {
        $pageToken = (string) ($row['access_token'] ?? '');
    }
}

if (! $pageToken) {
    echo 'PAGE_NOT_FOUND in accounts' . PHP_EOL;
    exit(1);
}

// 3) verify lifetime of the resulting page token
$dbg = $g->get('debug_token', [
    'input_token' => $pageToken,
    'access_token' => $appId . '|' . $secret,
]);
echo 'DEBUG ' . $dbg->status() . ': ' . $dbg->body() . PHP_EOL;
echo 'PAGE_TOKEN=' . $pageToken . PHP_EOL;
