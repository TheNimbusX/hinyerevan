<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public const DEFAULT_SOURCE = 'hy';

    private const TARGETS = ['ru', 'en'];

    /** MyMemory free tier is ~500 words; skip huge HTML blobs to avoid timeouts. */
    private const MAX_TRANSLATE_CHARS = 480;

    public function isEnabled(): bool
    {
        return (bool) config('services.translate.enabled', false);
    }

    public function targetLanguage(?string $lang): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $lang = strtolower(trim((string) $lang));

        if ($lang === '' || $lang === self::DEFAULT_SOURCE) {
            return null;
        }

        return in_array($lang, self::TARGETS, true) ? $lang : null;
    }

    public function translate(?string $text, ?string $targetLang, bool $html = false): ?string
    {
        if ($text === null || trim($text) === '' || ! $targetLang) {
            return $text;
        }

        if (mb_strlen($text) > self::MAX_TRANSLATE_CHARS) {
            return $text;
        }

        $results = $this->translateMany([$text], $targetLang, $html);

        return $results[0] ?? $text;
    }

    /**
     * @param  list<string|null>  $texts
     * @return list<string|null>
     */
    public function translateMany(array $texts, ?string $targetLang, bool $html = false): array
    {
        if (! $targetLang) {
            return $texts;
        }

        $normalized = [];
        $slots = [];

        foreach ($texts as $index => $text) {
            if ($text === null || trim($text) === '') {
                $normalized[$index] = $text;

                continue;
            }

            $slots[] = $index;
            $normalized[$index] = $text;
        }

        if ($slots === []) {
            return $texts;
        }

        foreach ($slots as $index) {
            $normalized[$index] = $this->translateOne((string) $normalized[$index], $targetLang, $html);
        }

        return $normalized;
    }

    /**
     * @param  iterable<int|string, array<string, mixed>|object>  $items
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public function translateItems(iterable $items, array $keys, ?string $targetLang, bool $html = false): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = is_array($item) ? $item : (array) $item;
        }

        if (! $targetLang || $rows === []) {
            return $rows;
        }

        $texts = [];
        $map = [];

        foreach ($rows as $rowIndex => $row) {
            foreach ($keys as $key) {
                if (! empty($row[$key]) && is_string($row[$key])) {
                    $map[] = [$rowIndex, $key];
                    $texts[] = $row[$key];
                }
            }
        }

        if ($texts === []) {
            return $rows;
        }

        $translated = $this->translateMany($texts, $targetLang, $html);

        foreach ($map as $textIndex => [$rowIndex, $key]) {
            $rows[$rowIndex][$key] = $translated[$textIndex] ?? $rows[$rowIndex][$key];
        }

        return $rows;
    }

    private function translateOne(string $text, string $targetLang, bool $html): string
    {
        $source = (string) config('services.translate.source', self::DEFAULT_SOURCE);
        $cacheKey = 'translate:v2:' . md5(json_encode([
            'driver' => config('services.translate.driver'),
            'source' => $source,
            'target' => $targetLang,
            'html' => $html,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($text, $source, $targetLang, $html) {
            $driver = (string) config('services.translate.driver', 'mymemory');

            if ($driver === 'libretranslate') {
                $translated = $this->viaLibreTranslate($text, $source, $targetLang, $html);
                if ($translated !== null) {
                    return $translated;
                }
            }

            return $this->viaMyMemory($text, $source, $targetLang) ?? $text;
        });
    }

    private function viaLibreTranslate(string $text, string $source, string $target, bool $html): ?string
    {
        $baseUrl = rtrim((string) config('services.translate.libretranslate_url', 'https://libretranslate.com'), '/');
        $apiKey = (string) config('services.translate.libretranslate_key');

        try {
            $payload = [
                'q' => $text,
                'source' => $source,
                'target' => $target,
                'format' => $html ? 'html' : 'text',
            ];

            if ($apiKey !== '') {
                $payload['api_key'] = $apiKey;
            }

            $response = Http::timeout(15)
                ->retry(1, 250)
                ->acceptJson()
                ->post("{$baseUrl}/translate", $payload);

            if (! $response->successful()) {
                Log::warning('LibreTranslate error', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $translated = $response->json('translatedText');

            return is_string($translated) && $translated !== '' ? $translated : null;
        } catch (\Throwable $exception) {
            Log::warning('LibreTranslate failed', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    private function viaMyMemory(string $text, string $source, string $target): ?string
    {
        try {
            $response = Http::timeout(12)
                ->retry(1, 200)
                ->get('https://api.mymemory.translated.net/get', [
                    'q' => $text,
                    'langpair' => "{$source}|{$target}",
                    'de' => 'a@b.c',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $translated = $response->json('responseData.translatedText');
            $status = (int) $response->json('responseStatus', 0);

            if ($status !== 200 || ! is_string($translated) || $translated === '') {
                return null;
            }

            if (strtoupper($translated) === strtoupper($text)) {
                return $translated;
            }

            return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } catch (\Throwable $exception) {
            Log::warning('MyMemory translate failed', ['message' => $exception->getMessage()]);

            return null;
        }
    }
}
