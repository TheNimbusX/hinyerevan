<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Services\DemoData;
use App\Services\LegacySchema;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SharePreviewController extends Controller
{
    private const WATERMARK_VERSION = 8;

    public function photo(Request $request, int $photo): Response
    {
        $payload = $this->photoPayload($photo);
        abort_unless($payload, 404);

        return response($this->spaShellWithPhotoMeta($payload), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function photoPayload(int $id): ?array
    {
        if (! LegacySchema::photosReady()) {
            $demo = DemoData::findPhoto($id);

            if (! $demo) {
                return null;
            }

            $images = $demo['images'] ?? [];
            $fileId = $this->fileIdFromPath($images['large'] ?? $images['thumb'] ?? null);

            return [
                'id' => (int) ($demo['id'] ?? $id),
                'title' => (string) ($demo['title'] ?? 'HinYerevan'),
                'description' => trim(($demo['year'] ?? '').' — '.($demo['title'] ?? ''), ' —'),
                'file_id' => $fileId ?: 'demo',
            ];
        }

        $photo = Photo::query()->published()->find($id);
        if (! $photo) {
            return null;
        }

        return [
            'id' => $photo->id,
            'title' => (string) $photo->title,
            'description' => trim($photo->year.' — '.$photo->title, ' —'),
            'file_id' => $photo->file_id,
        ];
    }

    private function fileIdFromPath(?string $path): ?string
    {
        if (! $path || ! preg_match('#/file/(?:large|original|thumb)/([^/?]+)#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function spaShellWithPhotoMeta(array $payload): string
    {
        $path = base_path('../frontend/dist/index.html');
        abort_unless(is_file($path), 503, 'SPA shell missing');

        $siteUrl = rtrim((string) config('services.facebook.site_url', config('app.frontend_url', config('app.url'))), '/');
        $pageTitle = $payload['title'].' — HinYerevan.com';
        $description = $payload['description'];
        $pageUrl = $siteUrl.'/photos/'.$payload['id'];
        $imageUrl = $siteUrl.'/api/photos/file/large/'.$payload['file_id'].'?wm='.self::WATERMARK_VERSION;

        $html = file_get_contents($path);

        $html = preg_replace('/<title>[^<]*<\/title>/', '<title>'.e($pageTitle).'</title>', $html, 1);
        $html = preg_replace(
            '/<meta\s+name="description"[^>]*>/',
            '<meta name="description" content="'.e($description).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:type"[^>]*>/',
            '<meta property="og:type" content="article" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:url"[^>]*>/',
            '<meta property="og:url" content="'.e($pageUrl).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:title"[^>]*>/',
            '<meta property="og:title" content="'.e($pageTitle).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:description"[^>]*>/',
            '<meta property="og:description" content="'.e($description).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:image"[^>]*>/',
            '<meta property="og:image" content="'.e($imageUrl).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+property="og:image:alt"[^>]*>/',
            '<meta property="og:image:alt" content="'.e($payload['title']).'" />',
            $html,
            1,
        );
        $html = preg_replace('/<meta\s+property="og:image:width"[^>]*>\s*/', '', $html, 1);
        $html = preg_replace('/<meta\s+property="og:image:height"[^>]*>\s*/', '', $html, 1);
        $html = preg_replace(
            '/<meta\s+name="twitter:title"[^>]*>/',
            '<meta name="twitter:title" content="'.e($pageTitle).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+name="twitter:description"[^>]*>/',
            '<meta name="twitter:description" content="'.e($description).'" />',
            $html,
            1,
        );
        $html = preg_replace(
            '/<meta\s+name="twitter:image"[^>]*>/',
            '<meta name="twitter:image" content="'.e($imageUrl).'" />',
            $html,
            1,
        );

        if (! str_contains($html, 'rel="canonical"')) {
            $html = preg_replace(
                '/<title>[^<]*<\/title>/',
                '$0'."\n    ".'<link rel="canonical" href="'.e($pageUrl).'" />',
                $html,
                1,
            );
        }

        return $html;
    }
}
