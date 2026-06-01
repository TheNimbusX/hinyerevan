<?php

namespace App\Socialite;

use GuzzleHttp\Exception\ConnectException;
use SocialiteProviders\Yandex\Provider as BaseProvider;

/**
 * Yandex profile is only available at login.yandex.ru — often blocked from VPS/datacenter IPs.
 * Use OAUTH_PROXY in .env when this host is unreachable from the server.
 */
class YandexProvider extends BaseProvider
{
    protected function getUserByToken($token)
    {
        try {
            $response = $this->getHttpClient()->get(
                'https://login.yandex.ru/info',
                [
                    'query' => ['format' => 'json'],
                    'headers' => [
                        'Authorization' => 'OAuth '.$token,
                    ],
                ],
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (ConnectException $e) {
            throw new ConnectException(
                'login.yandex.ru unreachable from server (set OAUTH_PROXY). '.$e->getMessage(),
                $e->getRequest(),
                $e->getPrevious(),
                $e->getHandlerContext(),
            );
        }
    }
}
