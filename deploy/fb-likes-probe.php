<?php
require __DIR__ . '/../backend/vendor/autoload.php';
$app = require __DIR__ . '/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Favorite;
use App\Models\Photo;

$id = (int) ($argv[1] ?? 11180);

echo "fav_rows=" . Favorite::where('photo_id', $id)->count() . PHP_EOL;
foreach (Favorite::where('photo_id', $id)->get(['user_unique', 'photo_id']) as $f) {
    echo "  user_unique=" . $f->user_unique . PHP_EOL;
}

$p = Photo::withCount('favorites as lc')->find($id);
echo "withCount=" . $p->lc . PHP_EOL;
echo "legacy_likes_count=" . (int) $p->legacy_likes_count . PHP_EOL;
echo "facebook_likes=" . (int) $p->facebook_likes . PHP_EOL;
