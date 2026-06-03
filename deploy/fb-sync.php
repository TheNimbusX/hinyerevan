<?php

require '/var/www/hinyerevan/backend/vendor/autoload.php';
$app = require '/var/www/hinyerevan/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$photoId = (int) ($argv[1] ?? 0);
$photo = App\Models\Photo::find($photoId);
if (! $photo) {
    echo "photo $photoId not found" . PHP_EOL;
    exit(1);
}

app(App\Services\Facebook\FacebookCommentSyncService::class)->syncForPhoto($photo, true);

$rows = App\Models\PhotoFacebookComment::where('photo_id', $photoId)->get(['author_name', 'author_picture']);
foreach ($rows as $r) {
    echo $r->author_name . ' => ' . ($r->author_picture ?? 'NULL') . PHP_EOL;
}
echo 'TOTAL=' . $rows->count() . PHP_EOL;
