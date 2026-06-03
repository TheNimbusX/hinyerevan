<?php

require '/var/www/hinyerevan/backend/vendor/autoload.php';
$app = require '/var/www/hinyerevan/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\PhotoFacebookComment::query()
    ->where('photo_id', (int) ($argv[1] ?? 0))
    ->orderBy('id')
    ->get(['facebook_comment_id', 'parent_facebook_comment_id', 'body']);

foreach ($rows as $row) {
    echo $row->facebook_comment_id . '|' . ($row->parent_facebook_comment_id ?? '') . '|' . $row->body . PHP_EOL;
}
echo 'TOTAL=' . $rows->count() . PHP_EOL;
