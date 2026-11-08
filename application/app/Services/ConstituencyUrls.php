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
        $identity = ['kind' => $parameters['kind'], 'state' => Str::slug($state), 'name' => $nameSlug];

        return route('constituency.named', $identity + array_diff_key($parameters, $identity), $absolute);
    }

    public static function resolve(string $kind, string $state, string $name, ?string $sourceName = null): ?array
    {
        $states = app(self::class)->states ??= Cache::remember('constituency-url-states-v3', 600, fn () => ElectionArchive::publicIndex()->distinct()->pluck('state_label')->map(fn ($value) => ElectionPlaceIdentity::state($value))->unique(fn ($value) => mb_strtolower(trim($value)))->values()->all());
        $matches = array_values(array_filter($states, fn ($value) => Str::slug($value) === $state));
        if (count($matches) !== 1) {
            return null;
        }
        $stateName = $matches[0];
        $matches = self::matches($kind, $stateName, $name);
        if ($sourceName !== null) {
            $matches = array_values(array_filter($matches, fn ($value) => self::identity($value) === self::identity($sourceName)));
        }
        $identities = array_unique(array_map(fn ($value) => self::identity($value), $matches));
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
                $catalogue->slugNames[$key][$nameSlug][] = $name;
                $catalogue->slugNames[$key][$nameSlug.'-'.substr(sha1(self::identity($name)), 0, 12)][] = $name;
            }
        }

        return $catalogue->slugNames[$key][$slug] ?? [];
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

    private static function names(string $kind, string $state): array
    {
        return Cache::remember('constituency-url-names-v2-'.sha1($kind.'|'.$state), 600, fn () => ElectionArchive::publicIndex()->where('kind', $kind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)])->distinct()->pluck('constituency_name')->all());
    }
}
