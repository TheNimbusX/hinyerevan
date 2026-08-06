<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        SocialiteWasCalled::class => [
            \App\Socialite\YandexExtendSocialite::class . '@handle',
            \App\Socialite\VkIdExtendSocialite::class . '@handle',
            \SocialiteProviders\Odnoklassniki\OdnoklassnikiExtendSocialite::class . '@handle',
            \SocialiteProviders\Instagram\InstagramExtendSocialite::class . '@handle',
        ],
    ];

    public function boot(): void
    {
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
