<?php
require __DIR__ . '/../backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../backend/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$s = app('App\Services\LegacyPhotoStorage');
$ref = new ReflectionClass($s);
$m = $ref->getMethod('buildWatermarkBadge');
$m->setAccessible(true);

$logoPath = __DIR__ . '/../backend/resources/watermark/logo.png';
[$srcW, $srcH, $type] = getimagesize($logoPath);
$source = match ($type) {
    IMAGETYPE_PNG => imagecreatefrompng($logoPath),
    default => imagecreatefromjpeg($logoPath),
};

$badge = $m->invoke($s, $source, $srcW, $srcH);
$out = '/tmp/wm-test.png';
imagepng($badge, $out);
imagedestroy($badge);
imagedestroy($source);

[$w, $h] = getimagesize($out);
echo "OK {$w}x{$h} " . filesize($out) . " bytes\n";
