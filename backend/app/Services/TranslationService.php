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

        $unique = [];
        foreach ($slots as $index) {
            $unique[(string) $normalized[$index]] = true;
        }

        $translatedBySource = $this->resolveTranslations(array_keys($unique), $targetLang, $html);

        foreach ($slots as $index) {
            $source = (string) $normalized[$index];
            $normalized[$index] = $translatedBySource[$source] ?? $source;
        }

        return $normalized;
    }

    public function translateHtml(?string $html, ?string $targetLang): ?string
    {
        if ($html === null || trim($html) === '' || ! $targetLang) {
            return $html;
        }

        $plainLength = mb_strlen(trim(strip_tags($html)));
        if ($plainLength === 0) {
            return $html;
        }

        if ($plainLength <= self::MAX_TRANSLATE_CHARS) {
            return $this->translate(strip_tags($html), $targetLang) ?? $html;
        }

        $segments = [];
        $marked = preg_replace_callback('/>([^<]+)</u', function (array $matches) use (&$segments) {
            $text = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($text === '') {
                return $matches[0];
            }

            $index = count($segments);
            $segments[] = $text;

            return '>__HY_TR_' . $index . '__<';
        }, $html);

        if (! is_string($marked) || $segments === []) {
            return $html;
        }

        $translated = $this->translateMany($segments, $targetLang);

        foreach ($translated as $index => $text) {
            $safe = htmlspecialchars((string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $marked = str_replace('__HY_TR_' . $index . '__', $safe, $marked);
        }

        return $marked;
    }

    /**
     * Short plain-text excerpt for list previews (news cards).
     */
    public function translateExcerpt(?string $html, ?string $targetLang, int $maxChars = 220): ?string
    {
        if ($html === null || trim($html) === '' || ! $targetLang) {
            return $html;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) > $maxChars) {
            $plain = mb_substr($plain, 0, $maxChars) . '…';
        }

        return $this->translate($plain, $targetLang) ?? $plain;
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

    /**
     * @param  list<string>  $sources
     * @return array<string, string>
     */
    private function resolveTranslations(array $sources, string $targetLang, bool $html): array
    {
        $resolved = [];
        $pending = [];

        foreach ($sources as $source) {
            $cached = Cache::get($this->cacheKey($source, $targetLang, $html));
            if (is_string($cached) && $cached !== '') {
                $resolved[$source] = $cached;
            } else {
                $pending[] = $source;
            }
        }

        if ($pending === []) {
            return $resolved;
        }

        $maxCalls = max(1, (int) config('services.translate.max_api_calls_per_request', 24));
        if (count($pending) > $maxCalls) {
            Log::info('Translation budget exceeded, leaving some strings untranslated', [
                'pending' => count($pending),
                'max' => $maxCalls,
                'target' => $targetLang,
            ]);
            $pending = array_slice($pending, 0, $maxCalls);
        }

        $fetched = $this->fetchInParallel($pending, $targetLang, $html);

        foreach ($fetched as $source => $translation) {
            Cache::put($this->cacheKey($source, $targetLang, $html), $translation, now()->addDays(30));
            $resolved[$source] = $translation;
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, string>
     */
    private function fetchInParallel(array $texts, string $targetLang, bool $html): array
    {
        $driver = (string) config('services.translate.driver', 'mymemory');
        $batchSize = max(1, (int) config('services.translate.parallel_batch', 12));
        $source = (string) config('services.translate.source', self::DEFAULT_SOURCE);
        $resolved = [];

        foreach (array_chunk($texts, $batchSize) as $chunk) {
            if ($driver === 'libretranslate') {
                foreach ($chunk as $text) {
                    $resolved[$text] = $this->viaLibreTranslate($text, $source, $targetLang, $html) ?? $text;
                }

                continue;
            }

            $responses = Http::pool(function ($pool) use ($chunk, $source, $targetLang) {
                foreach ($chunk as $index => $text) {
                    $pool->as((string) $index)
                        ->timeout(6)
                        ->when(config('services.oauth.proxy'), fn ($client) => $client->withOptions([
                            'proxy' => config('services.oauth.proxy'),
                        ]))
                        ->get('https://api.mymemory.translated.net/get', [
                            'q' => $text,
                            'langpair' => "{$source}|{$targetLang}",
                            'de' => 'a@b.c',
                        ]);
                }
            });

            foreach ($chunk as $index => $text) {
                $response = $responses[(string) $index] ?? null;
                $resolved[$text] = $this->parseMyMemoryResponse($response, $text);
            }
        }

        return $resolved;
    }

    private function parseMyMemoryResponse(mixed $response, string $fallback): string
    {
        try {
            if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
                return $fallback;
            }

            $translated = $response->json('responseData.translatedText');
            $status = (int) $response->json('responseStatus', 0);

            if ($status !== 200 || ! is_string($translated) || $translated === '') {
                return $fallback;
            }

            return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } catch (\Throwable $exception) {
            Log::warning('MyMemory translate failed', ['message' => $exception->getMessage()]);

            return $fallback;
        }
    }

    private function cacheKey(string $text, string $targetLang, bool $html): string
    {
        return 'translate:v3:' . md5(json_encode([
            'driver' => config('services.translate.driver'),
            'source' => config('services.translate.source', self::DEFAULT_SOURCE),
            'target' => $targetLang,
            'html' => $html,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE));
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

            $response = Http::timeout(8)
                ->acceptJson()
                ->when(config('services.oauth.proxy'), fn ($client) => $client->withOptions([
                    'proxy' => config('services.oauth.proxy'),
                ]))
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
}
