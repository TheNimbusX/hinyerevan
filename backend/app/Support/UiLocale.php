<?php

namespace App\Support;

use Illuminate\Http\Request;

class UiLocale
{
    public const SUPPORTED = ['hy', 'ru', 'en'];

    public static function fromRequest(Request $request): string
    {
        $lang = strtolower(trim((string) ($request->input('lang') ?? $request->query('lang') ?? '')));

        return in_array($lang, self::SUPPORTED, true) ? $lang : 'hy';
    }

    public static function apply(Request $request): string
    {
        $lang = self::fromRequest($request);
        app()->setLocale($lang);

        return $lang;
    }
}
