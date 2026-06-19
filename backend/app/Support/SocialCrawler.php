<?php

namespace App\Support;

use Illuminate\Http\Request;

class SocialCrawler
{
    private const PATTERN = '/facebookexternalhit|Facebot|Twitterbot|LinkedInBot|TelegramBot|WhatsApp|Slackbot|Discordbot|vkShare|Viber|Pinterestbot|Googlebot|bingbot|Applebot|Embedly|SkypeUriPreview/i';

    public static function matches(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        return $agent !== '' && preg_match(self::PATTERN, $agent) === 1;
    }
}
