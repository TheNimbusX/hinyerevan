<?php

namespace App\Socialite;

use SocialiteProviders\Manager\SocialiteWasCalled;

class YandexExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('yandex', YandexProvider::class);
    }
}
