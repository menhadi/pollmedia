<?php

namespace App\Services;

use Illuminate\Support\Str;

class SirNameSearch
{
    /**
     * Search aliases are machine transliterations, never official display names.
     */
    public static function latin(string $name): string
    {
        $latin = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name)
            : Str::transliterate($name);

        return trim(preg_replace('/[^a-z0-9\s]/', '', mb_strtolower($latin ?: '')));
    }

    /**
     * A consonant key tolerates common vowel spellings and Hindi schwa deletion.
     */
    public static function key(string $latin): string
    {
        $value = preg_replace('/[^a-z]/', '', strtolower($latin));
        $value = preg_replace('/([a-z])\1+/', '$1', $value);
        $value = str_replace(['ch', 'sh', 'ph', 'kh', 'gh', 'th', 'dh', 'bh', 'jh', 'w'], ['c', 's', 'f', 'k', 'g', 't', 'd', 'b', 'j', 'v'], $value);

        return preg_replace('/[aeiou]/', '', $value);
    }

    public static function pattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
    }
}
