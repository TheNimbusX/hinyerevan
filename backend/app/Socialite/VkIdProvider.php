<?php

namespace App\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use SocialiteProviders\Manager\OAuth2\User;
use SocialiteProviders\VKontakte\Provider as VkontakteProvider;

/**
 * VK ID (id.vk.ru) — OAuth 2.1 + PKCE. Legacy oauth.vk.com without PKCE returns Security Error.
 *
 * @see https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/api-description
 */
class VkIdProvider extends VkontakteProvider
{
    public const IDENTIFIER = 'VKONTAKTE';

    protected $usesPKCE = true;

    protected $scopes = ['email'];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://id.vk.ru/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://id.vk.ru/oauth2/auth';
    }

    protected function getTokenFields($code): array
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
            'code_verifier' => $this->request->session()->pull('code_verifier'),
            'device_id' => (string) $this->request->input('device_id', ''),
            'state' => (string) $this->request->input('state', ''),
        ];

        if ($this->clientSecret !== '') {
            $fields['client_secret'] = $this->clientSecret;
        }

        return $fields;
    }

    protected function getUserByToken($token)
    {
        $accessToken = is_array($token) ? ($token['access_token'] ?? '') : $token;
        $emailFromToken = is_array($token) ? Arr::get($token, 'email') : null;

        $response = $this->getHttpClient()->post('https://id.vk.ru/oauth2/user_info', [
            RequestOptions::FORM_PARAMS => [
                'client_id' => $this->clientId,
                'access_token' => $accessToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $user = is_array($body) ? ($body['user'] ?? $body) : [];

        if ($emailFromToken && empty($user['email'])) {
            $user['email'] = $emailFromToken;
        }

        return $user;
    }

    protected function mapUserToObject(array $user)
    {
        $firstName = Arr::get($user, 'first_name', '');
        $lastName = Arr::get($user, 'last_name', '');

        return (new User)->setRaw($user)->map([
            'id' => (string) Arr::get($user, 'user_id', Arr::get($user, 'id', '')),
            'nickname' => null,
            'name' => trim($firstName.' '.$lastName) ?: null,
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'avatar'),
        ]);
    }
}
