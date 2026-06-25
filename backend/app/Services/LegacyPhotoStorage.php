<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LegacyPhotoStorage
{
    /** Legacy site used a fixed ~90px logo in the bottom-right corner. */
    private const WATERMARK_LOGO_WIDTH = 90;

    /** Tight white margin around logo content inside the badge (ratio of content size). */
    private const WATERMARK_BADGE_PADDING_RATIO = 0.045;

    /** Minimal corner radius on the white badge (ratio of badge size). */
    private const WATERMARK_BADGE_RADIUS_RATIO = 0.035;

    /** Blur zone that fully covers the old burn-in (~90px wide, ~110px tall with www line). */
    private const WATERMARK_BLUR_WIDTH = 108;

    private const WATERMARK_BLUR_HEIGHT = 116;

    public function absolutePath(string $variant, string $fileId): string
    {
        $paths = config('hinyerevan.photo_paths');
        $relative = $paths[$variant] ?? $paths['large'];

        return rtrim(config('hinyerevan.legacy_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative)
            . DIRECTORY_SEPARATOR
            . basename($fileId);
    }

    public function exists(string $variant, string $fileId): bool
    {
        return File::exists($this->absolutePath($variant, $fileId));
    }

    /**
     * Return a path to a cached copy of $sourcePath with the site watermark
     * burned into the bottom-right corner. Returns null when the source is not
     * a raster image or the watermark asset is unavailable, in which case the
     * caller should serve the original file untouched.
     */
    public function watermarkedPath(string $sourcePath): ?string
    {
        if (! is_file($sourcePath)) {
            return null;
        }

        $watermark = $this->watermarkAssetPath();
        if ($watermark === null) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            // Not a raster image (e.g. the demo SVG fallback) — leave it alone.
            return null;
        }

        $cacheDir = storage_path('app/watermarked');
        File::ensureDirectoryExists($cacheDir);

        $key = md5($sourcePath . '|' . filemtime($sourcePath) . '|' . filemtime($watermark) . '|badge-v8');
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $key;

        if (is_file($cachePath) && filesize($cachePath) > 0) {
            return $cachePath;
        }

        return $this->renderWatermark($sourcePath, $watermark, $info, $cachePath);
    }

    /**
     * Burn the upload watermark (the new site logo) directly into $path.
     * Used for freshly uploaded photos so they permanently carry the new
     * watermark, the same way legacy photos already have one baked in.
     */
    public function burnUploadWatermark(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $watermark = config('hinyerevan.watermark_upload');
        if (! $watermark || ! is_file($watermark)) {
            return;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }

        $tmp = $path . '.wm';
        $rendered = $this->renderWatermark($path, $watermark, $info, $tmp);
        if ($rendered !== null && is_file($rendered)) {
            @rename($rendered, $path);
        } else {
            @unlink($tmp);
        }
    }

    private function watermarkAssetPath(): ?string
    {
        // Prefer the new brand watermark that ships with the app, so every served
        // photo carries the current logo. The legacy white.png is often absent on
        // production, which is why photos used to show no mark at all.
        $upload = config('hinyerevan.watermark_upload');
        if ($upload && is_file($upload)) {
            return $upload;
        }

        $configured = config('hinyerevan.watermark');
        if (! $configured) {
            return null;
        }

        if (is_file($configured)) {
            return $configured;
        }

        $resolved = rtrim(config('hinyerevan.legacy_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);

        return is_file($resolved) ? $resolved : null;
    }

    private function renderWatermark(string $sourcePath, string $watermarkPath, array $info, string $cachePath): ?string
    {
        [$width, $height, $type] = $info;

        $create = match ($type) {
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            default => 'imagecreatefromjpeg',
        };

        $base = @$create($sourcePath);
        if (! $base) {
            return null;
        }

        // The watermark asset may not actually be a PNG (e.g. a JPEG saved with a
        // .png name), so detect its real type instead of assuming PNG — otherwise
        // imagecreatefrompng() silently fails and no mark is ever stamped.
        $markInfo = @getimagesize($watermarkPath);
        $markCreate = match ($markInfo[2] ?? IMAGETYPE_PNG) {
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            default => 'imagecreatefromjpeg',
        };

        $mark = @$markCreate($watermarkPath);
        if (! $mark) {
            imagedestroy($base);

            return null;
        }

        imagealphablending($mark, true);
        imagesavealpha($mark, true);

        $markW = imagesx($mark);
        $markH = imagesy($mark);

        $badge = $this->buildWatermarkBadge($mark, $markW, $markH);
        imagedestroy($mark);

        $badgeW = imagesx($badge);
        $badgeH = imagesy($badge);
        $targetW = self::WATERMARK_LOGO_WIDTH;
        $targetH = max(1, (int) round($badgeH * ($targetW / max(1, $badgeW))));

        $resized = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagealphablending($resized, true);
        imagecopyresampled($resized, $badge, 0, 0, 0, 0, $targetW, $targetH, $badgeW, $badgeH);
        imagedestroy($badge);

        $maskW = min(self::WATERMARK_BLUR_WIDTH, $width);
        $maskH = min(self::WATERMARK_BLUR_HEIGHT, $height);
        $maskX = $width - $maskW;
        $maskY = $height - $maskH;
        $dstX = $width - min($targetW, $width);
        $dstY = $height - min($targetH, $height);

        if ($type === IMAGETYPE_PNG) {
            imagealphablending($base, true);
            imagesavealpha($base, true);
        }

        $this->blurLegacyCornerMark($base, $maskX, $maskY, $maskW, $maskH);
        $this->copyMergeWithAlpha($base, $resized, $dstX, $dstY, $targetW, $targetH, 96);
        imagedestroy($resized);

        $tmp = $cachePath . '.tmp';
        $ok = match ($type) {
            IMAGETYPE_PNG => imagepng($base, $tmp),
            IMAGETYPE_WEBP => imagewebp($base, $tmp),
            IMAGETYPE_GIF => imagegif($base, $tmp),
            default => imagejpeg($base, $tmp, 88),
        };
        imagedestroy($base);

        if (! $ok) {
            @unlink($tmp);

            return null;
        }

        @rename($tmp, $cachePath);

        return is_file($cachePath) ? $cachePath : null;
    }

    /**
     * Blur the corner patch so legacy burn-in text disappears under the new logo.
     * Samples extra bleed around the patch, then downscale-blur for a strong smear.
     */
    private function blurLegacyCornerMark(\GdImage $base, int $x, int $y, int $w, int $h): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $imgW = imagesx($base);
        $imgH = imagesy($base);

        // Extra context lets blur smear high-contrast legacy text edges.
        $bleed = 16;
        $grabX = max(0, $x - $bleed);
        $grabY = max(0, $y - $bleed);
        $grabW = min($imgW - $grabX, $w + ($x - $grabX) + $bleed);
        $grabH = min($imgH - $grabY, $h + ($y - $grabY) + $bleed);

        $grab = imagecreatetruecolor($grabW, $grabH);
        imagecopy($grab, $base, 0, 0, $grabX, $grabY, $grabW, $grabH);
        $this->heavyBlurImage($grab, $grabW, $grabH);

        $offX = $x - $grabX;
        $offY = $y - $grabY;

        $core = min($w, $h) * 0.78;
        $fade = min($w, $h) * 0.34;

        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $weight = $this->cornerRadialBlendWeight($px, $py, $w, $h, $core, $fade);
                if ($weight <= 0) {
                    continue;
                }

                $blurred = imagecolorat($grab, $offX + $px, $offY + $py);
                $br = ($blurred >> 16) & 0xFF;
                $bg = ($blurred >> 8) & 0xFF;
                $bb = $blurred & 0xFF;

                $orig = imagecolorat($base, $x + $px, $y + $py);
                $or = ($orig >> 16) & 0xFF;
                $og = ($orig >> 8) & 0xFF;
                $ob = $orig & 0xFF;

                $r = (int) round($br * $weight + $or * (1 - $weight));
                $g = (int) round($bg * $weight + $og * (1 - $weight));
                $b = (int) round($bb * $weight + $ob * (1 - $weight));

                imagesetpixel($base, $x + $px, $y + $py, imagecolorallocate($base, $r, $g, $b));
            }
        }

        imagedestroy($grab);
    }

    /** Strong blur via downscale-upscale plus stacked Gaussian passes. */
    private function heavyBlurImage(\GdImage $img, int $w, int $h): void
    {
        $smallW = max(1, (int) round($w / 5));
        $smallH = max(1, (int) round($h / 5));
        $small = imagecreatetruecolor($smallW, $smallH);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $smallW, $smallH, $w, $h);

        for ($i = 0; $i < 5; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagecopyresampled($img, $small, 0, 0, 0, 0, $w, $h, $smallW, $smallH);
        imagedestroy($small);

        for ($i = 0; $i < 14; $i++) {
            imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
        }
    }

    /** Soft radial mask anchored at the bottom-right of the blur patch. */
    private function cornerRadialBlendWeight(int $px, int $py, int $w, int $h, float $core, float $fade): float
    {
        $dx = ($w - 1 - $px) + 0.5;
        $dy = ($h - 1 - $py) + 0.5;
        $dist = sqrt(($dx * $dx) + ($dy * $dy));

        if ($dist <= $core) {
            return 1.0;
        }

        if ($dist >= $core + $fade) {
            return 0.0;
        }

        $t = ($dist - $core) / max(1.0, $fade);

        // Smoothstep for a gentler transition than linear fade.
        return 1.0 - ($t * $t * (3 - 2 * $t));
    }

    /**
     * Build a white rounded-square badge from Logo2026: tight padding, minimal radius.
     */
    private function buildWatermarkBadge(\GdImage $source, int $srcW, int $srcH): \GdImage
    {
        [$minX, $minY, $maxX, $maxY] = $this->logoContentBounds($source, $srcW, $srcH);
        $contentW = max(1, $maxX - $minX + 1);
        $contentH = max(1, $maxY - $minY + 1);

        $padding = max(2, (int) round(min($contentW, $contentH) * self::WATERMARK_BADGE_PADDING_RATIO));
        $badgeW = $contentW + ($padding * 2);
        $badgeH = $contentH + ($padding * 2);
        $radius = max(2, (int) round(min($badgeW, $badgeH) * self::WATERMARK_BADGE_RADIUS_RATIO));

        $badge = imagecreatetruecolor($badgeW, $badgeH);
        imagealphablending($badge, false);
        imagesavealpha($badge, true);
        $transparent = imagecolorallocatealpha($badge, 0, 0, 0, 127);
        imagefill($badge, 0, 0, $transparent);

        $white = imagecolorallocate($badge, 255, 255, 255);
        for ($y = 0; $y < $badgeH; $y++) {
            for ($x = 0; $x < $badgeW; $x++) {
                if ($this->pointInsideRoundedRect($x + 0.5, $y + 0.5, $badgeW, $badgeH, $radius)) {
                    imagesetpixel($badge, $x, $y, $white);
                }
            }
        }

        imagealphablending($badge, true);
        imagecopy(
            $badge,
            $source,
            $padding,
            $padding,
            $minX,
            $minY,
            $contentW,
            $contentH,
        );

        return $badge;
    }

    /** @return array{0:int,1:int,2:int,3:int} minX, minY, maxX, maxY */
    private function logoContentBounds(\GdImage $source, int $w, int $h): array
    {
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($source, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                if ($r >= 248 && $g >= 248 && $b >= 248) {
                    continue;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            return [0, 0, $w - 1, $h - 1];
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    private function pointInsideRoundedRect(float $x, float $y, int $w, int $h, int $radius): bool
    {
        $r = min($radius, (int) floor(min($w, $h) / 2));
        if ($r <= 0) {
            return $x >= 0 && $x < $w && $y >= 0 && $y < $h;
        }

        if ($x >= $r && $x < ($w - $r)) {
            return $y >= 0 && $y < $h;
        }

        if ($y >= $r && $y < ($h - $r)) {
            return $x >= 0 && $x < $w;
        }

        $cx = $x < $r ? $r : ($w - $r);
        $cy = $y < $r ? $r : ($h - $r);
        $dx = $x - $cx;
        $dy = $y - $cy;

        return ($dx * $dx) + ($dy * $dy) <= ($r * $r);
    }

    /**
     * Composite a transparent PNG ($src) onto $dst at a given opacity while
     * honouring the source's per-pixel alpha. GD's imagecopymerge() ignores
     * alpha channels, so we blend through an intermediate buffer.
     */
    private function copyMergeWithAlpha($dst, $src, int $dstX, int $dstY, int $srcW, int $srcH, int $pct): void
    {
        $cut = imagecreatetruecolor($srcW, $srcH);
        imagecopy($cut, $dst, 0, 0, $dstX, $dstY, $srcW, $srcH);
        imagecopy($cut, $src, 0, 0, 0, 0, $srcW, $srcH);
        imagecopymerge($dst, $cut, $dstX, $dstY, 0, 0, $srcW, $srcH, $pct);
        imagedestroy($cut);
    }

    public function storeUpload(UploadedFile $file, string $salt): string
    {
        $fileId = md5(microtime(true) . Str::random(24) . $salt);

        $this->writeUploadVariants($file->getRealPath() ?: $file->getPathname(), $fileId, moveSource: function (string $original) use ($file) {
            $file->move(dirname($original), basename($original));
        });

        return $fileId;
    }

    /** Overwrite original/large/thumb for an existing legacy file id. */
    public function replaceUpload(UploadedFile $file, string $fileId): void
    {
        $source = $file->getRealPath() ?: $file->getPathname();
        $this->writeUploadVariants($source, $fileId, moveSource: function (string $original) use ($file, $source) {
            File::copy($source, $original);
        });
    }

    /** Overwrite variants from an image already on disk (e.g. a fetched YouTube thumbnail). */
    public function replaceImageFile(string $sourcePath, string $fileId): void
    {
        $this->writeUploadVariants($sourcePath, $fileId, moveSource: function (string $original) use ($sourcePath) {
            File::copy($sourcePath, $original);
        });
    }

    /**
     * Fetch a YouTube preview and store it as photo variants.
     * When $existingFileId is set, overwrite that id instead of minting a new one.
     */
    public function storeYoutubeThumbnail(string $videoUrl, string $salt, ?string $existingFileId = null): string
    {
        $videoId = $this->extractYoutubeId($videoUrl);
        abort_if($videoId === null, 422, 'Could not read the YouTube video id.');

        $candidates = [
            "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg",
            "https://img.youtube.com/vi/{$videoId}/sddefault.jpg",
            "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
        ];

        $binary = null;
        foreach ($candidates as $url) {
            try {
                $response = Http::timeout(15)->get($url);
                if ($response->ok() && strlen($response->body()) > 2000) {
                    $binary = $response->body();
                    break;
                }
            } catch (\Throwable) {
                // try the next candidate
            }
        }

        abort_if($binary === null, 422, 'Could not fetch the video thumbnail.');

        $tmp = tempnam(sys_get_temp_dir(), 'ythumb_') . '.jpg';
        file_put_contents($tmp, $binary);

        try {
            if ($existingFileId) {
                $this->replaceImageFile($tmp, $existingFileId);

                return $existingFileId;
            }

            return $this->storeImageFile($tmp, $salt);
        } finally {
            @unlink($tmp);
        }
    }

    private function extractYoutubeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([\w\-]{6,})#i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function writeUploadVariants(string $sourcePath, string $fileId, callable $moveSource): void
    {
        $original = $this->absolutePath('original', $fileId);
        $large = $this->absolutePath('large', $fileId);
        $thumb = $this->absolutePath('thumb', $fileId);

        File::ensureDirectoryExists(dirname($original));
        File::ensureDirectoryExists(dirname($large));
        File::ensureDirectoryExists(dirname($thumb));

        $moveSource($original);
        $this->resize($original, $large, 800, 800);
        $this->resize($original, $thumb, 192, 192, true);

        $this->burnUploadWatermark($original);
        $this->burnUploadWatermark($large);
        $this->burnUploadWatermark($thumb);
    }

    /**
     * Store an image that already lives on disk (e.g. a downloaded video thumbnail)
     * and generate the same variants as a normal upload.
     */
    public function storeImageFile(string $sourcePath, string $salt): string
    {
        $fileId = md5(microtime(true) . Str::random(24) . $salt);
        $original = $this->absolutePath('original', $fileId);
        $large = $this->absolutePath('large', $fileId);
        $thumb = $this->absolutePath('thumb', $fileId);

        File::ensureDirectoryExists(dirname($original));
        File::ensureDirectoryExists(dirname($large));
        File::ensureDirectoryExists(dirname($thumb));

        File::copy($sourcePath, $original);
        $this->resize($original, $large, 800, 800);
        $this->resize($original, $thumb, 192, 192, true);

        $this->burnUploadWatermark($original);
        $this->burnUploadWatermark($large);
        $this->burnUploadWatermark($thumb);

        return $fileId;
    }

    /** Target edge length for legacy user avatars (Retina-safe up to ~256px CSS). */
    public const USER_AVATAR_TARGET = 512;

    /**
     * Serve path for user avatars: upscale/re-encode tiny legacy files from photos/users.
     */
    public function userAvatarDisplayPath(string $sourcePath, int $targetSize = self::USER_AVATAR_TARGET): string
    {
        if (! is_file($sourcePath)) {
            return $sourcePath;
        }

        if (! $this->shouldEnhanceUserAvatar($sourcePath)) {
            return $sourcePath;
        }

        $targetSize = max(128, min(768, $targetSize));
        $cacheDir = storage_path('app/cache/user-avatars');
        File::ensureDirectoryExists($cacheDir);
        $cacheKey = md5($sourcePath . ':' . filemtime($sourcePath) . ':' . filesize($sourcePath) . ':' . $targetSize . ':v2');
        $cached = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.jpg';

        if (is_file($cached) && filemtime($cached) >= filemtime($sourcePath)) {
            return $cached;
        }

        if ($this->writeEnhancedUserAvatar($sourcePath, $cached, $targetSize)) {
            return $cached;
        }

        return $sourcePath;
    }

    public function shouldEnhanceUserAvatar(string $sourcePath): bool
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $max = max($width, $height);
        $bytes = (int) filesize($sourcePath);

        if ($max < self::USER_AVATAR_TARGET) {
            return true;
        }

        // Heavily compressed legacy JPEGs (common in old DB uploads).
        return $max < 640 && $bytes > 0 && $bytes < 28_000;
    }

    /**
     * Overwrite a legacy avatar file on disk with an enhanced square JPEG (irreversible).
     */
    public function persistEnhancedUserAvatar(string $sourcePath, int $targetSize = self::USER_AVATAR_TARGET): bool
    {
        if (! is_file($sourcePath) || ! $this->shouldEnhanceUserAvatar($sourcePath)) {
            return false;
        }

        $tmp = $sourcePath . '.enhance.' . getmypid() . '.jpg';
        if (! $this->writeEnhancedUserAvatar($sourcePath, $tmp, $targetSize)) {
            @unlink($tmp);

            return false;
        }

        $ok = @rename($tmp, $sourcePath);
        if (! $ok) {
            $ok = @copy($tmp, $sourcePath);
            @unlink($tmp);
        }

        if ($ok) {
            @touch($sourcePath);
        }

        return $ok;
    }

    public function purgeUserAvatarCache(): void
    {
        $cacheDir = storage_path('app/cache/user-avatars');
        if (is_dir($cacheDir)) {
            File::cleanDirectory($cacheDir);
        }
    }

    private function writeEnhancedUserAvatar(string $source, string $target, int $targetSize): bool
    {
        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }

        $image = $this->loadRasterImage($source, (int) $info[2]);
        if ($image === null) {
            return false;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $max = max($width, $height);
        $scale = $max < $targetSize ? $targetSize / $max : 1.0;

        $targetRatio = 1.0;
        $sourceRatio = $width / max(1, $height);

        if ($sourceRatio > $targetRatio) {
            $cropH = $height;
            $cropW = (int) round($height * $targetRatio);
            $srcX = (int) round(($width - $cropW) / 2);
            $srcY = 0;
        } else {
            $cropW = $width;
            $cropH = (int) round($width / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($height - $cropH) / 2);
        }

        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            $srcX,
            $srcY,
            $targetSize,
            $targetSize,
            $cropW,
            $cropH,
        );

        if ($scale > 1.35 && function_exists('imagefilter')) {
            @imagefilter($canvas, IMG_FILTER_CONTRAST, -8);
            @imagefilter($canvas, IMG_FILTER_SMOOTH, -2);
        }

        File::ensureDirectoryExists(dirname($target));
        $ok = imagejpeg($canvas, $target, 92);
        imagedestroy($canvas);
        imagedestroy($image);

        return $ok && is_file($target) && filesize($target) > 0;
    }

    private function loadRasterImage(string $source, int $type): ?\GdImage
    {
        $loader = match ($type) {
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            default => 'imagecreatefromjpeg',
        };

        if (! function_exists($loader)) {
            return null;
        }

        $image = @$loader($source);

        return $image instanceof \GdImage ? $image : null;
    }

    public function storeUserPhoto(UploadedFile $file, string $salt): string
    {
        $fileId = md5('user' . microtime(true) . Str::random(24) . $salt);
        $target = $this->absolutePath('users', $fileId);

        File::ensureDirectoryExists(dirname($target));
        $tmp = $file->getRealPath() ?: $file->getPathname();
        $this->resize($tmp, $target, 512, 512, true);

        return $fileId;
    }

    /**
     * Download a remote OAuth avatar and store it under photos/users (legacy layout).
     */
    public function storeUserPhotoFromUrl(string $url, string $salt): ?string
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $client = Http::timeout(12);
            $proxy = trim((string) config('services.oauth.proxy', ''));
            // The OAuth proxy exists for Yandex; routing Facebook/Graph avatar URLs
            // through it breaks the download, so fetch those hosts directly.
            $isFacebook = (bool) preg_match('/(facebook\.com|fbcdn\.net|fbsbx\.com)/i', $url);
            if ($proxy !== '' && ! $isFacebook) {
                $client = $client->withOptions(['proxy' => $proxy]);
            }

            $response = $client->get($url);
            if (! $response->ok()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) < 200) {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'oauthavatar_');
            if ($tmp === false) {
                return null;
            }

            file_put_contents($tmp, $body);

            if (@getimagesize($tmp) === false) {
                @unlink($tmp);

                return null;
            }

            $fileId = md5('user' . microtime(true) . Str::random(24) . $salt);
            $target = $this->absolutePath('users', $fileId);
            File::ensureDirectoryExists(dirname($target));
            $this->resize($tmp, $target, 512, 512, true);
            @unlink($tmp);

            return $fileId;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Re-fetch a Facebook avatar at most this often (their lookaside URLs rotate, pics rarely change). */
    private const FB_AVATAR_TTL_DAYS = 7;

    /**
     * Download and locally cache a Facebook commenter avatar so it survives the
     * expiry baked into platform-lookaside URLs. Keyed by the stable FB user id,
     * so repeated syncs reuse one file. Returns the local file id (served via
     * /api/photos/file/users/{id}) or null on failure.
     */
    public function storeFacebookAvatar(string $url, string $facebookUserId): ?string
    {
        $url = trim($url);
        $facebookUserId = trim($facebookUserId);
        if ($url === '' || $facebookUserId === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $fileId = 'fb' . md5($facebookUserId);
        $target = $this->absolutePath('users', $fileId);

        // Reuse a recent copy instead of re-downloading on every sync.
        if (is_file($target) && filesize($target) > 0
            && (time() - filemtime($target)) < self::FB_AVATAR_TTL_DAYS * 86400) {
            return $fileId;
        }

        try {
            // Direct fetch — the OAuth proxy is for Yandex and must not touch fbsbx.com.
            $response = Http::timeout(12)->get($url);
            if (! $response->ok()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) < 200) {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'fbavatar_');
            if ($tmp === false) {
                return null;
            }

            file_put_contents($tmp, $body);
            if (@getimagesize($tmp) === false) {
                @unlink($tmp);

                return null;
            }

            File::ensureDirectoryExists(dirname($target));
            $this->resize($tmp, $target, 256, 256, true);
            @unlink($tmp);

            return is_file($target) && filesize($target) > 0 ? $fileId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resize(string $source, string $target, int $maxWidth, int $maxHeight, bool $crop = false): void
    {
        [$width, $height, $type] = getimagesize($source);
        $create = match ($type) {
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => 'imagecreatefromjpeg',
        };
        $save = match ($type) {
            IMAGETYPE_PNG => 'imagepng',
            IMAGETYPE_WEBP => 'imagewebp',
            default => 'imagejpeg',
        };

        $image = $create($source);
        $targetRatio = $maxWidth / $maxHeight;
        $sourceRatio = $width / $height;

        if ($crop) {
            if ($sourceRatio > $targetRatio) {
                $newHeight = $height;
                $newWidth = (int) round($height * $targetRatio);
                $srcX = (int) round(($width - $newWidth) / 2);
                $srcY = 0;
            } else {
                $newWidth = $width;
                $newHeight = (int) round($width / $targetRatio);
                $srcX = 0;
                $srcY = (int) round(($height - $newHeight) / 2);
            }

            $canvas = imagecreatetruecolor($maxWidth, $maxHeight);
            imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, $maxWidth, $maxHeight, $newWidth, $newHeight);
        } else {
            $scale = min($maxWidth / $width, $maxHeight / $height, 1);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        }

        File::ensureDirectoryExists(dirname($target));
        if ($save === 'imagejpeg') {
            imagejpeg($canvas, $target, 90);
        } else {
            $save($canvas, $target);
        }
        imagedestroy($canvas);
        imagedestroy($image);
    }
}
