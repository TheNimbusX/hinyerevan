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
    /** @var array<int, string> */
    protected $scopes = ['login:email', 'login:info'];

    protected $scopeSeparator = ' ';

    protected function getUserByToken($token)
    {
        $last = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
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
                $last = $e;
                if ($attempt < 3) {
                    usleep(500000 * $attempt);
                }
            }
        }

        throw new ConnectException(
            'login.yandex.ru unreachable from server (set OAUTH_PROXY). '.$last->getMessage(),
            $last->getRequest(),
            $last->getPrevious(),
            $last->getHandlerContext(),
        );
    }
}
