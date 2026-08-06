<?php

namespace App\Services;

class CommentBodyFormatter
{
    public static function display(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*p\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace("/\r\n?|\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
