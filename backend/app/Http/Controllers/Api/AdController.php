<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdController extends Controller
{
    private const PUBLIC_CACHE_KEY = 'ads:public:v1';

    private const ENABLED_KEY = 'ads_enabled';

    // The sidebar fits this many banners.
    private const MAX_PUBLIC = 4;

    private const IMAGE_MAX_WIDTH = 800;

    public function index()
    {
        if (! $this->ready()) {
            return ['enabled' => false, 'banners' => []];
        }

        return Cache::remember(self::PUBLIC_CACHE_KEY, now()->addMinutes(10), function () {
            $enabled = SiteSetting::getValue(self::ENABLED_KEY, '0') === '1';
            if (! $enabled) {
                return ['enabled' => false, 'banners' => []];
            }

            $banners = AdBanner::query()
                ->where('active', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->limit(self::MAX_PUBLIC)
                ->get()
                ->map(fn (AdBanner $banner) => $this->serialize($banner, false))
                ->all();

            return ['enabled' => $banners !== [], 'banners' => $banners];
        });
    }

    public function image(AdBanner $banner)
    {
        $path = storage_path('app/' . $banner->image_path);
        abort_unless(is_file($path), 404);

        return Response::file($path, [
            'Content-Type' => mime_content_type($path) ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    // Counter only: a raw update so updated_at (and the image URL version) stays put.
    public function click(AdBanner $banner)
    {
        if ($banner->active) {
            AdBanner::query()->whereKey($banner->id)->update([
                'clicks' => DB::raw('clicks + 1'),
                'last_clicked_at' => now(),
            ]);
        }

        return response()->noContent();
    }

    public function adminIndex()
    {
        abort_unless($this->ready(), 503, 'Run migrations first.');

        return [
            'enabled' => SiteSetting::getValue(self::ENABLED_KEY, '0') === '1',
            'max_public' => self::MAX_PUBLIC,
            'banners' => AdBanner::query()->orderBy('sort')->orderBy('id')->get()
                ->map(fn (AdBanner $banner) => $this->serialize($banner, true))
                ->all(),
        ];
    }

    public function setEnabled(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        SiteSetting::setValue(self::ENABLED_KEY, filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        $this->flush();

        return $this->adminIndex();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);

        $banner = new AdBanner([
            'title' => $data['title'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'active' => filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sort' => (int) AdBanner::query()->max('sort') + 1,
            'image_path' => '',
        ]);
        $banner->image_path = $this->storeImage($request->file('image'));
        $banner->save();
        $this->flush();

        return $this->serialize($banner, true);
    }

    public function update(Request $request, AdBanner $banner)
    {
        $data = $this->validated($request, false);

        foreach (['title', 'link_url'] as $field) {
            if ($request->has($field)) {
                $banner->{$field} = $data[$field] ?? null;
            }
        }
        if ($request->has('active')) {
            $banner->active = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->hasFile('image')) {
            $old = $banner->image_path;
            $banner->image_path = $this->storeImage($request->file('image'));
            $this->deleteImage($old);
        }

        $banner->save();
        $this->flush();

        return $this->serialize($banner->fresh(), true);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach (array_values($data['ids']) as $position => $id) {
            AdBanner::query()->whereKey($id)->update(['sort' => $position]);
        }
        $this->flush();

        return $this->adminIndex();
    }

    public function destroy(AdBanner $banner)
    {
        $this->deleteImage($banner->image_path);
        $banner->delete();
        $this->flush();

        return response()->noContent();
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'link_url' => ['nullable', 'url', 'regex:#^https?://#i', 'max:512'],
            'active' => ['nullable', 'boolean'],
            'image' => [$creating ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir = storage_path('app/ads');
        File::ensureDirectoryExists($dir);

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = Str::random(24) . '.' . $ext;
        $target = $dir . DIRECTORY_SEPARATOR . $name;

        // GIFs keep their animation; others are capped in width.
        if ($ext === 'gif' || ! $this->downscale($file->getRealPath(), $target)) {
            $file->move($dir, $name);
        }

        return 'ads/' . $name;
    }

    private function downscale(string $source, string $target): bool
    {
        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }

        [$width, $height, $type] = $info;
        if ($width <= self::IMAGE_MAX_WIDTH) {
            return false;
        }

        $image = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            default => false,
        };
        if (! $image) {
            return false;
        }

        $newHeight = (int) round($height * self::IMAGE_MAX_WIDTH / $width);
        $canvas = imagecreatetruecolor(self::IMAGE_MAX_WIDTH, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, self::IMAGE_MAX_WIDTH, $newHeight, $width, $height);

        $ok = match ($type) {
            IMAGETYPE_PNG => imagepng($canvas, $target, 7),
            IMAGETYPE_WEBP => imagewebp($canvas, $target, 85),
            default => imagejpeg($canvas, $target, 85),
        };
        imagedestroy($canvas);
        imagedestroy($image);

        return $ok && is_file($target);
    }

    private function deleteImage(?string $relative): void
    {
        if (! $relative || ! str_starts_with($relative, 'ads/')) {
            return;
        }
        $path = storage_path('app/' . $relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function serialize(AdBanner $banner, bool $admin): array
    {
        $row = [
            'id' => $banner->id,
            'title' => $banner->title,
            'link_url' => $banner->link_url,
            'image_url' => $banner->imageUrl(),
        ];

        if ($admin) {
            $row['active'] = (bool) $banner->active;
            $row['sort'] = (int) $banner->sort;
            $row['clicks'] = (int) ($banner->clicks ?? 0);
            $row['last_clicked_at'] = $banner->last_clicked_at?->toISOString();
            $row['created_at'] = $banner->created_at?->toISOString();
            $row['updated_at'] = $banner->updated_at?->toISOString();
        }

        return $row;
    }

    private function flush(): void
    {
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }

    private function ready(): bool
    {
        return Schema::hasTable('ad_banners') && Schema::hasTable('site_settings');
    }
}
