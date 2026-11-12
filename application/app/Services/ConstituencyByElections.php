<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ConstituencyByElections
{
    private array $directories = [];

    private array $stateNames = [];

    public function __construct(private ByElectionRecords $sources) {}

    private function state(string $name): string
    {
        return $this->stateNames[$name] ??= match (strtoupper(str_replace('.', '', trim($name)))) {
            'UP' => 'Uttar Pradesh',
            'MP' => 'Madhya Pradesh',
            'WB' => 'West Bengal',
            default => ElectionPlaceIdentity::state($name),
        };
    }

    /** The same category may be printed before the name, after it, or in both places. */
    private function category(string $name): ?string
    {
        preg_match_all('/^\s*\((gen|sc|st)\)|\((gen|sc|st)\)\s*$/iu', $name, $matches, PREG_SET_ORDER);
        $labels = collect($matches)->map(fn ($match) => strtolower($match[1] ?: $match[2]))->unique();

        return $labels->count() > 1 ? 'conflicting' : $labels->first();
    }

    private function identity(string $name): string
    {
        $name = preg_replace('/^\s*\d{1,3}\s*[-–]\s*(?=\p{L})/u', '', $name);
        $name = preg_replace('/^\s*\((gen|sc|st)\)\s*|\s*\((gen|sc|st)\)\s*$/iu', '', $name);
        $name = preg_replace('/\s*[-–]\s*/u', '-', mb_strtolower(trim($name)));

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    private function target(array $entry): ?array
    {
        $kind = $entry['kind'] ?? null;
        $state = $this->state($entry['state'] ?? '');
        $key = $this->identity($entry['constituency'] ?? '');
        if (! in_array($kind, ['pc', 'ac'], true) || $state === '' || $key === '' || empty($entry['year']) || ! RequestSchema::hasTable('historical_constituency_index')) {
            return null;
        }
        $scope = $kind.'|'.$state;
        $this->directories[$scope] ??= collect(Cache::remember('by-election-directory-v2|'.$scope, 600, fn () => ElectionArchive::publicIndex()->where('kind', $kind)
            ->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)])
            ->get(['edition_id', 'year', 'constituency_name'])
            ->groupBy(fn ($row) => $this->identity($row->constituency_name))
            ->map(fn ($rows) => $rows->map(fn ($row) => (array) $row)->values()->all())
            ->all()))->map(fn (array $rows) => collect($rows)->map(fn (array $row) => (object) $row));
        $matches = $this->directories[$scope]->get($key);
        if (! $matches || $matches->duplicates('edition_id')->isNotEmpty()) {
            return null;
        }
        $category = $this->category($entry['constituency']);
        if ($category === 'conflicting') {
            return null;
        }
        if ($category !== null) {
            $exact = $matches->filter(fn ($row) => $this->category($row->constituency_name) === $category);
            if ($exact->isEmpty() && $matches->contains(fn ($row) => $this->category($row->constituency_name) !== null)) {
                return null;
            }
            $matches = $exact->isNotEmpty() ? $exact : $matches;
        }
        $row = $matches->sortBy(fn ($row) => [abs($row->year - $entry['year']), -$row->year, $row->constituency_name])->first();
        if (! $row) {
            return null;
        }

        return ['kind' => $kind, 'state' => $state, 'name' => $row->constituency_name];
    }

    public function url(array $entry): ?string
    {
        $seat = $this->target($entry);

        return $seat ? ConstituencyUrls::url($seat + ['by_election' => $entry['id']]).'#by-election-result' : null;
    }

    public function forSeat(string $kind, string $state, string $name): Collection
    {
        $identity = $this->identity($name);

        return $this->sources->index()->filter(function ($entry) use ($kind, $state, $identity, $name) {
            return ($entry['kind'] ?? null) === $kind && $this->identity($entry['constituency'] ?? '') === $identity
                && $this->state($entry['state'] ?? '') === $state
                && (! $this->category($name) || ! $this->category($entry['constituency']) || $this->category($name) === $this->category($entry['constituency']))
                && $this->target($entry) !== null;
        })->sortBy([['year', 'desc'], ['period', 'asc'], ['id', 'asc']])->values();
    }
}
