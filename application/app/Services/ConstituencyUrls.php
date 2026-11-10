<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConstituencyUrls
{
    private array $slugNames = [];

    private ?array $states = null;

    public static function url(array $parameters, bool $absolute = true): string
    {
        $state = ElectionPlaceIdentity::state($parameters['state']);
        $nameSlug = Str::slug($parameters['name']);
        $matches = self::matches($parameters['kind'], $state, $nameSlug);
        if (count(array_unique(array_map(self::identity(...), $matches))) > 1) {
            $nameSlug .= '-'.substr(sha1(self::identity($parameters['name'])), 0, 12);
        }
        $identity = ['kind' => $parameters['kind'], 'state' => self::slug($state), 'name' => $nameSlug];

        return route('constituency.named', $identity + array_diff_key($parameters, $identity), $absolute);
    }

    /** Slugs must treat "&" as "and", matching the state slugs used across the site. */
    private static function slug(string $value): string
    {
        return Str::slug(str_ireplace('&', 'and', $value));
    }

    public static function resolve(string $kind, string $state, string $name, ?string $sourceName = null): ?array
    {
        $states = app(self::class)->states ??= Cache::remember('constituency-url-states-v4', 600, fn () => ElectionArchive::publicIndex()->distinct()->pluck('state_label')->map(fn ($value) => ElectionPlaceIdentity::state($value))->unique(fn ($value) => mb_strtolower(trim($value)))->values()->all());
        $matches = array_values(array_filter($states, fn ($value) => self::slug($value) === $state || Str::slug($value) === $state));
        if ($matches === []) {
            return null;
        }
        $stateName = $matches[0];
        $matches = self::matches($kind, $stateName, $name);
        if ($sourceName !== null) {
            $matches = array_values(array_filter($matches, fn ($value) => self::identity($value) === self::identity($sourceName)));
        }
        $identities = array_unique(array_map(fn ($value) => self::looseIdentity($value), $matches));
        if (count($identities) !== 1) {
            return null;
        }

        return ['kind' => $kind, 'state' => $stateName, 'name' => $matches[0]];
    }

    private static function matches(string $kind, string $state, string $slug): array
    {
        $catalogue = app(self::class);
        $key = $kind.'|'.$state;
        if (! isset($catalogue->slugNames[$key])) {
            $catalogue->slugNames[$key] = [];
            foreach (self::names($kind, $state) as $name) {
                $nameSlug = Str::slug($name);
                foreach (array_unique([$nameSlug, self::slug($name), self::looseIdentity($name)]) as $variant) {
                    if ($variant === '') {
                        continue;
                    }
                    $catalogue->slugNames[$key][$variant][] = $name;
                }
                $catalogue->slugNames[$key][$nameSlug.'-'.substr(sha1(self::identity($name)), 0, 12)][] = $name;
            }
        }

        $loose = self::looseIdentity($slug);
        $found = $catalogue->slugNames[$key][$slug] ?? $catalogue->slugNames[$key][$loose] ?? [];
        if ($found === [] && $loose !== '') {
            // Slugs such as "prathipadusc" come from names written without a space before "(SC)".
            $trimmed = preg_replace('/(sc|st)$/', '', $loose);
            $found = $trimmed !== $loose ? ($catalogue->slugNames[$key][$trimmed] ?? []) : [];
        }

        return $found;
    }

    public static function legacyPaths(string $kind, string $state, string $name, array $parameters = []): array
    {
        $identity = self::resolve($kind, $state, $name);
        if ($identity === null) {
            return [];
        }
        $filters = [];
        foreach (['edition', 'code'] as $filter) {
            if (array_key_exists($filter, $parameters)) {
                $filters[$filter] = $parameters[$filter];
            }
        }

        return array_values(array_unique(array_map(fn (string $sourceName): string => route('constituency.overview', [
            'kind' => $kind, 'state' => $identity['state'], 'name' => $sourceName,
        ] + $filters, false), self::matches($kind, $identity['state'], $name))));
    }

    private static function identity(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** Compare names ignoring punctuation, spacing, "and"/"&" and SC/ST markers such as "North-East" vs "NORTH EAST" or "(SC)" vs "(S.C)". */
    private static function looseIdentity(string $name): string
    {
        $value = mb_strtolower(trim($name));
        do {
            $previous = $value;
            $value = preg_replace('/\(\s*s\.?\s*c\.?\s*\)|\(\s*s\.?\s*t\.?\s*\)|\bs\.?\s*c\.?\s*$|\bs\.?\s*t\.?\s*$/u', ' ', $value);
        } while ($value !== $previous);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/(^|\s)and(\s|$)/', ' ', $value);

        return preg_replace('/\s+/', '', $value);
    }

    private static function names(string $kind, string $state): array
    {
        return Cache::remember('constituency-url-names-v3-'.sha1($kind.'|'.$state), 600, function () use ($kind, $state): array {
            $normalised = "REPLACE(".ElectionPlaceIdentity::stateSql().", ' & ', ' and ')";
            $target = str_replace(' & ', ' and ', mb_strtolower($state));

            return ElectionArchive::publicIndex()->where('kind', $kind)->whereRaw($normalised.' = ?', [$target])->distinct()->pluck('constituency_name')->all();
        });
    }
}
