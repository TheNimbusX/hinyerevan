<?php

namespace App\Socialite;

use SocialiteProviders\Manager\SocialiteWasCalled;

class VkIdExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('vkontakte', VkIdProvider::class);
    }
}
