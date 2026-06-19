<?php
/**
 * Build a 1200x630 Open Graph share image (Facebook recommended size).
 * Usage: php deploy/generate-og-share.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/frontend/public/Logo2026.png';
$target = $root . '/frontend/public/og-share.jpg';

if (! is_file($source)) {
    fwrite(STDERR, "Logo not found: {$source}\n");
    exit(1);
}

$blob = file_get_contents($source);
if ($blob === false) {
    fwrite(STDERR, "Could not read logo\n");
    exit(1);
}

$logo = @imagecreatefromstring($blob);
if ($logo === false) {
    fwrite(STDERR, "Could not decode logo image\n");
    exit(1);
}

$canvasW = 1200;
$canvasH = 630;
$canvas = imagecreatetruecolor($canvasW, $canvasH);
$white = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $white);

$logoW = imagesx($logo);
$logoH = imagesy($logo);
$maxW = (int) round($canvasW * 0.72);
$maxH = (int) round($canvasH * 0.78);
$scale = min($maxW / max(1, $logoW), $maxH / max(1, $logoH));
$dstW = max(1, (int) round($logoW * $scale));
$dstH = max(1, (int) round($logoH * $scale));
$dstX = (int) round(($canvasW - $dstW) / 2);
$dstY = (int) round(($canvasH - $dstH) / 2);

imagecopyresampled($canvas, $logo, $dstX, $dstY, 0, 0, $dstW, $dstH, $logoW, $logoH);
imagedestroy($logo);

if (! imagejpeg($canvas, $target, 92)) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}
imagedestroy($canvas);

echo "Wrote {$target} (" . filesize($target) . " bytes)\n";
