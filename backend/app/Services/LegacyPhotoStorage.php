<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LegacyPhotoStorage
{
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

        $key = md5($sourcePath . '|' . filemtime($sourcePath) . '|' . filemtime($watermark));
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

        // The legacy mark (templates/white.png) is ~90x96 px and is stamped at a
        // fixed size in the bottom-right corner of the display variant, so it is
        // larger (relative to width) on narrow portraits than on wide photos.
        // Size ours off the long edge so it always matches/covers that footprint,
        // scaling up on big originals and down on small images.
        $maxEdge = max($width, $height);
        $targetW = max(72, min((int) round($maxEdge * 0.135), 300));
        $targetH = max(1, (int) round($markH * ($targetW / $markW)));

        $resized = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $mark, 0, 0, 0, 0, $targetW, $targetH, $markW, $markH);
        imagedestroy($mark);

        // The brand logo ships on a solid white background. Drop near-white
        // pixels to transparent so only the mark itself is stamped on the photo.
        $this->makeWhiteTransparent($resized, $targetW, $targetH);

        // Place the mark on a soft rounded white plate in the bottom-right
        // corner. Legacy photos carry an old watermark burned into that exact
        // corner, and the semi-opaque plate masks it so only our mark shows.
        $pad = max(4, (int) round($targetW * 0.06));
        $panelW = $targetW + 2 * $pad;
        $panelH = $targetH + 2 * $pad;
        // Hug the bottom-right corner with a slightly smaller margin than the
        // legacy mark used (~8px on an 800px photo) so the plate fully overlaps
        // and never lets the old mark peek out at the edges.
        $margin = max(3, min((int) round($maxEdge * 0.008), 12));
        $panelX = $width - $panelW - $margin;
        $panelY = $height - $panelH - $margin;
        $dstX = $panelX + $pad;
        $dstY = $panelY + $pad;

        if ($type === IMAGETYPE_PNG) {
            imagealphablending($base, true);
            imagesavealpha($base, true);
        }

        $plate = imagecreatetruecolor($panelW, $panelH);
        imagealphablending($plate, false);
        imagesavealpha($plate, true);
        imagefill($plate, 0, 0, imagecolorallocatealpha($plate, 0, 0, 0, 127));
        imagealphablending($plate, true);
        $radius = max(6, (int) round(min($panelW, $panelH) * 0.18));
        $this->fillRoundedRect($plate, 0, 0, $panelW, $panelH, $radius, imagecolorallocate($plate, 255, 255, 255));
        $this->copyMergeWithAlpha($base, $plate, $panelX, $panelY, $panelW, $panelH, 85);
        imagedestroy($plate);

        $this->copyMergeWithAlpha($base, $resized, $dstX, $dstY, $targetW, $targetH, 92);
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

    /** Fill a rounded rectangle on an alpha-enabled image. */
    private function fillRoundedRect($img, int $x, int $y, int $w, int $h, int $radius, int $color): void
    {
        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));
        $x2 = $x + $w - 1;
        $y2 = $y + $h - 1;

        imagefilledrectangle($img, $x + $radius, $y, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x, $y + $radius, $x2, $y2 - $radius, $color);

        $d = $radius * 2;
        if ($d > 0) {
            imagefilledellipse($img, $x + $radius, $y + $radius, $d, $d, $color);
            imagefilledellipse($img, $x2 - $radius, $y + $radius, $d, $d, $color);
            imagefilledellipse($img, $x + $radius, $y2 - $radius, $d, $d, $color);
            imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $d, $d, $color);
        }
    }

    /**
     * Turn the solid white background of the brand logo into transparency so the
     * watermark reads as the mark only. Near-white pixels become fully
     * transparent; light edge pixels fade out for a clean anti-aliased outline.
     */
    private function makeWhiteTransparent($img, int $w, int $h): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha === 0x7F) {
                    continue;
                }

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $min = min($r, $g, $b);

                if ($min >= 236) {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, 127));
                } elseif ($min >= 200) {
                    $fade = (int) round((($min - 200) / 36) * 127);
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $fade))));
                }
            }
        }
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
        $original = $this->absolutePath('original', $fileId);
        $large = $this->absolutePath('large', $fileId);
        $thumb = $this->absolutePath('thumb', $fileId);

        File::ensureDirectoryExists(dirname($original));
        File::ensureDirectoryExists(dirname($large));
        File::ensureDirectoryExists(dirname($thumb));

        $file->move(dirname($original), basename($original));
        $this->resize($original, $large, 800, 800);
        $this->resize($original, $thumb, 192, 192, true);

        $this->burnUploadWatermark($original);
        $this->burnUploadWatermark($large);
        $this->burnUploadWatermark($thumb);

        return $fileId;
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
            if ($proxy !== '') {
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
