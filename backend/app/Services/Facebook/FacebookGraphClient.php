<?php

namespace App\Services\Facebook;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FacebookGraphClient
{
    private const API_VERSION = 'v19.0';

    public function get(string $path, array $query = []): Response
    {
        $path = ltrim($path, '/');

        return $this->client()->get($this->url($path), $query);
    }

    public function post(string $path, array $form = []): Response
    {
        $path = ltrim($path, '/');

        return $this->client()->asForm()->post($this->url($path), $form);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/' . self::API_VERSION . '/' . $path;
    }

    private function client()
    {
        $client = Http::timeout(25)->acceptJson();
        $proxy = trim((string) config('services.oauth.proxy', ''));

        if ($proxy !== '') {
            $client = $client->withOptions(['proxy' => $proxy]);
        }

        return $client;
    }
}
