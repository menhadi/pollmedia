<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class PublicLanguage
{
    private static array $translationSource = [];

    private static array $dictionary = [];

    private static ?array $renderTranslations = null;

    private static array $translationPatterns = [];

    public static function text(?string $text): string
    {
        $text ??= '';

        return app()->getLocale() === 'hi' ? (config('public_translations', [])[$text] ?? $text) : $text;
    }

    public static function translate(string $text): string
    {
        if (filter_var(trim($text), FILTER_VALIDATE_URL) || filter_var(trim($text), FILTER_VALIDATE_EMAIL)) {
            return $text;
        }
        $source = config('public_translations', []);
        if ($source !== self::$translationSource) {
            self::$translationSource = $source;
            if (self::$renderTranslations !== null) {
                self::$renderTranslations = [];
            }
            self::$dictionary = array_filter($source, fn ($value, $key) => is_string($value) && trim($value) !== '' && $key !== '', ARRAY_FILTER_USE_BOTH);
            $keys = array_keys(self::$dictionary);
            usort($keys, fn ($left, $right) => strlen($right) <=> strlen($left));
            self::$translationPatterns = [];
            $parts = [];
            $bytes = 0;
            foreach ($keys as $key) {
                $quoted = preg_quote($key, '~');
                if (strlen($quoted) > 8000) {
                    continue;
                }
                if ($bytes + strlen($quoted) > 8000 && $parts !== []) {
                    self::$translationPatterns[] = '~(?<![\p{L}\p{N}_-])(?:'.implode('|', $parts).')(?![\p{L}\p{N}_-])~u';
                    $parts = [];
                    $bytes = 0;
                }
                $parts[] = $quoted;
                $bytes += strlen($quoted) + 1;
            }
            if ($parts !== []) {
                self::$translationPatterns[] = '~(?<![\p{L}\p{N}_-])(?:'.implode('|', $parts).')(?![\p{L}\p{N}_-])~u';
            }
        }
        if (self::$renderTranslations !== null && array_key_exists($text, self::$renderTranslations)) {
            return self::$renderTranslations[$text];
        }
        $result = self::translateUncached($text);
        if (self::$renderTranslations !== null && strlen($text) <= 1024 && count(self::$renderTranslations) < 4096) {
            self::$renderTranslations[$text] = $result;
        }

        return $result;
    }

    private static function translateUncached(string $text): string
    {
        $dictionary = self::$dictionary;
        if (isset($dictionary[$text])) {
            return $dictionary[$text];
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($text));
        if (isset($dictionary[$normalized])) {
            return preg_replace_callback('/\S(?:.*\S)?/us', fn () => $dictionary[$normalized], $text, 1) ?? $text;
        }
        if (HindiPlaceNames::ambiguousUiToken($text)) {
            return $text;
        }
        if (! app()->bound(HindiPlaceNames::class)) {
            app()->singleton(HindiPlaceNames::class);
        }
        $text = app(HindiPlaceNames::class)->translated($text) ?? $text;
        foreach (self::$translationPatterns as $pattern) {
            $text = preg_replace_callback($pattern, fn ($match) => $dictionary[$match[0]], $text) ?? $text;
        }

        return $text;
    }

    public static function placeText(string $text): string
    {
        if (! app()->bound(HindiPlaceNames::class)) {
            app()->singleton(HindiPlaceNames::class);
        }

        return self::translate(app(HindiPlaceNames::class)->translated($text, true) ?? $text);
    }

    private static function isPlaceNode(\DOMNode $node): bool
    {
        $element = $node->parentNode;
        for ($depth = 0; $element instanceof \DOMElement && $depth < 5; $depth++, $element = $element->parentNode) {
            if ($element->tagName === 'h1' || $element->hasAttribute('data-place-name')) {
                return true;
            }
            if (in_array($element->tagName, ['strong', 'h2', 'h3'], true)) {
                $container = $element->parentNode;
                for ($index = 0; $container instanceof \DOMElement && $index < 4; $index++, $container = $container->parentNode) {
                    if (in_array($container->getAttribute('id'), ['polygon-tooltip', 'place-details'], true) || preg_match('/map-tooltip|maplibregl-popup-content/', $container->getAttribute('class'))) {
                        return true;
                    }
                }
            }
            if ($element->tagName === 'select' && preg_match('/state|district|village|place/i', $element->getAttribute('id').' '.$element->getAttribute('name'))) {
                return true;
            }
            if ($element->tagName === 'a' && preg_match('~/(?:india/(?:districts|villages?|census/places|state)|data/census/records)/~', $element->getAttribute('href'))) {
                return true;
            }
        }

        return false;
    }

    public static function html(string $html): string
    {
        if (app()->getLocale() !== 'hi') {
            return $html;
        }
        $previousTranslations = self::$renderTranslations;
        self::$renderTranslations = [];
        try {
            return self::translateHtml($html);
        } finally {
            self::$renderTranslations = $previousTranslations;
        }
    }

    private static function translateHtml(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//text()[not(ancestor::script or ancestor::style or ancestor::textarea or ancestor::code or ancestor::pre or ancestor::*[@translate="no"] or ancestor::*[@contenteditable] or ancestor::select[@id="colour-metric" or @id="polygon-metric"])]') as $node) {
            $parent = $node->parentNode;
            if ($parent instanceof \DOMElement && $parent->tagName === 'option' && ! $parent->hasAttribute('value')) {
                $parent->setAttribute('value', $parent->textContent);
            }
            $node->nodeValue = self::isPlaceNode($node) ? self::placeText($node->nodeValue) : self::translate($node->nodeValue);
        }
        foreach ($xpath->query('//*[@placeholder or @aria-label or @title or @alt]') as $element) {
            foreach (['placeholder', 'aria-label', 'title', 'alt'] as $attribute) {
                if ($element->hasAttribute($attribute) && $element->getAttribute('translate') !== 'no') {
                    $element->setAttribute($attribute, self::translate($element->getAttribute($attribute)));
                }
            }
        }
        $document->encoding = 'UTF-8';
        $result = $document->saveHTML();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return preg_replace_callback('/&#([0-9]+);/', fn ($match) => (int) $match[1] >= 128 ? mb_chr((int) $match[1], 'UTF-8') : $match[0], str_replace('<?xml encoding="UTF-8">', '', $result ?: $html)) ?? $html;
    }
}
