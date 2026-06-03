<?php

require '/var/www/hinyerevan/backend/vendor/autoload.php';
$app = require '/var/www/hinyerevan/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$g = app(App\Services\Facebook\FacebookGraphClient::class);
$id = trim((string) config('services.facebook.page_id'));
$tok = trim((string) config('services.facebook.page_access_token'));

echo "page_id=$id token_len=" . strlen($tok) . PHP_EOL;

$r = $g->get($id, ['fields' => 'name,followers_count,fan_count,link', 'access_token' => $tok]);
echo 'STATS ' . $r->status() . ': ' . $r->body() . PHP_EOL;

$postId = (int) ($argv[1] ?? 0);
if ($postId > 0) {
    $photo = App\Models\Photo::find($postId);
    if ($photo && $photo->facebook_post_id) {
        $cr = $g->get($photo->facebook_post_id . '/comments', [
            'fields' => 'id,message,from{name,picture}',
            'filter' => 'stream',
            'limit' => 3,
            'access_token' => $tok,
        ]);
        echo 'COMMENTS ' . $cr->status() . ': ' . substr($cr->body(), 0, 1500) . PHP_EOL;
    } else {
        echo 'no facebook_post_id for photo ' . $postId . PHP_EOL;
    }
}

$sample = App\Models\PhotoFacebookComment::query()->whereNotNull('author_picture')->first();
echo 'DB_SAMPLE_PIC=' . ($sample->author_picture ?? 'NONE') . PHP_EOL;

$appId = (string) (config('services.facebook.app_id') ?: config('services.facebook.client_id'));
$secret = (string) (config('services.facebook.app_secret') ?: config('services.facebook.client_secret'));
echo 'CONFIG_APP_ID=' . $appId . PHP_EOL;

if ($appId && $secret) {
    $dbg = $g->get('debug_token', [
        'input_token' => $tok,
        'access_token' => $appId . '|' . $secret,
    ]);
    echo 'DEBUG_TOKEN ' . $dbg->status() . ': ' . $dbg->body() . PHP_EOL;
}

