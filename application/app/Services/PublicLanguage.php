<?php

namespace App\Services;

class PublicLanguage
{
    public static function text(?string $text): string
    {
        $text ??= '';

        return app()->getLocale() === 'hi' ? (config('public_translations', [])[$text] ?? $text) : $text;
    }
}
