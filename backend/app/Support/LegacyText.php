<?php

namespace App\Support;

class LegacyText
{
    public static function decode(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
