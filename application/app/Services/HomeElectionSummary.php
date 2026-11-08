<?php

namespace App\Services;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeElectionSummary
{
    private array $selectionExpiries = [];

    public function dashboard(): array
    {
        return $this->dashboardResult();
    }

    private function dashboardResult(bool $refresh = false, ?string $heldKey = null): array
    {
        $groups = ElectionArchive::publicIndex()->whereIn('kind', ['pc', 'ac'])->whereNotNull('state_label')
            ->selectRaw('kind, year, state_label, edition_id, extraction_sha256, COUNT(*) as tables_count, SUM(candidate_count) as candidates_count')
            ->groupBy('kind', 'year', 'state_label', 'edition_id', 'extraction_sha256')->orderBy('edition_id')->orderBy('state_label')->get();
        $version = DB::table('historical_election_reviews')->max('id') ?? 0;

        $key = 'home-election-v3:'.hash('sha256', $groups->toJson().$version);
        $cached = Cache::get($key);
        if ($cached !== null && ! $refresh) {
            $this->refreshDashboardIfDue($key);

            return $cached;
        }
        $cacheKey = $key;
        $build = function () use ($groups, $cacheKey): array {
            $this->selectionExpiries = [];
            $selected = [];
            foreach ($groups as $group) {
                if ($group->year < 1977 && preg_match('/^[su][0-9]{2}$/i', $group->state_label)) {
                    continue;
                }
                $state = ElectionPlaceIdentity::state($group->state_label);
                $key = $group->kind.':'.$group->year.':'.mb_strtolower($state);
                $prior = $selected[$key] ?? null;
                if (! $prior || [$group->tables_count, $group->candidates_count, $group->edition_id] > [$prior->tables_count, $prior->candidates_count, $prior->edition_id]) {
                    $group->state = $state;
                    $selected[$key] = $group;
                }
            }
            $annual = ['pc' => [], 'ac' => []];
            $stateCounts = ['pc' => [], 'ac' => []];
            $disk = app(ArchiveFiles::class);
            $analysisVersion = $this->analysisVersion();
            foreach (collect($selected)->groupBy('edition_id') as $edition => $selections) {
                foreach ($this->editionRollup((string) $edition, $selections, $disk, $analysisVersion) as $item) {
                    $selection = (object) $item['selection'];
                    $summary = $item['summary'];
                    $kind = $selection->kind;
                    $year = (int) $selection->year;
                    $point = $annual[$kind][$year] ?? ['year' => $year, 'electors' => 0, 'polled' => 0, 'turnout_count' => 0, 'party_count' => 0, 'tables' => 0, 'candidates' => 0, 'state_elections' => 0, 'parties' => [], 'review' => false];
                    foreach (['electors', 'polled', 'turnout_count', 'party_count', 'tables'] as $metric) {
                        $point[$metric] += $summary[$metric] ?? 0;
                    }
                    $point['candidates'] += $item['candidates'];
                    foreach ($summary['parties'] as $party) {
                        $point['parties'][$party['party']] = ($point['parties'][$party['party']] ?? 0) + $party['votes'];
                    }
                    $point['state_elections']++;
                    $point['review'] = $point['review'] || $item['review'];
                    $annual[$kind][$year] = $point;
                    $stateCounts[$kind][mb_strtolower($selection->state)] = ($stateCounts[$kind][mb_strtolower($selection->state)] ?? 0) + 1;
                }
            }
            $result = [];
            foreach ($annual as $kind => $points) {
                ksort($points);
                $partyTotals = [];
                foreach ($points as &$point) {
                    $point['turnout'] = $point['electors'] > 0 ? 100 * $point['polled'] / $point['electors'] : null;
                    if ($point['turnout_count'] === 0) {
                        $point['polled'] = $point['electors'] = null;
                    }
                    foreach ($point['parties'] as $party => $votes) {
                        $partyTotals[$party] = ($partyTotals[$party] ?? 0) + $votes;
                    }
                }
                unset($point);
                arsort($partyTotals);
                $top = array_slice(array_keys(array_filter($partyTotals, fn (int $votes, string $party): bool => ! in_array(strtoupper($party), ['NOTA', 'IND', 'INDEPENDENT']), ARRAY_FILTER_USE_BOTH)), 0, 5);
                foreach ($points as &$point) {
                    $total = array_sum($point['parties']);
                    foreach ($top as $i => $party) {
                        $point['party'.$i] = $total > 0 ? ($point['parties'][$party] ?? 0) : null;
                        $point['party'.$i.'_share'] = $total > 0 ? 100 * $point['party'.$i] / $total : null;
                    }
                }
                unset($point);
                $result[$kind] = ['rows' => array_values($points), 'top_parties' => $top, 'states' => $stateCounts[$kind], 'stats' => [
                    'elections' => $kind === 'pc' ? count($points) : array_sum(array_column($points, 'state_elections')),
                    'parties' => count(array_filter(array_keys($partyTotals), fn ($party): bool => ! in_array(strtoupper($party), ['NOTA', 'IND', 'INDEPENDENT']))),
                    'candidates' => array_sum(array_column($points, 'candidates')), 'results' => array_sum(array_column($points, 'tables'))]];
            }

            $this->flushSelectionExpiries();
            Cache::put($cacheKey.':built', now()->getTimestamp(), 3600);

            return $result;
        };
        if ($refresh && $heldKey === $key) {
            $result = $build();
            Cache::put($key, $result, 3600);

            return $result;
        }
        try {
            return Cache::lock($key.':build', 180)->block(20, fn (): array => Cache::remember($key, 3600, $build));
        } catch (LockTimeoutException) {
            return Cache::remember($key, 3600, $build);
        }
    }

    private function editionRollup(string $edition, Collection $selections, ArchiveFiles $disk, string $analysisVersion): array
    {
        $path = 'election-archive/'.$edition.'/extraction.json';
        if (! $disk->exists($path)) {
            return [];
        }
        $body = null;
        $digest = $disk->verifiedJsonDigest($path);
        if ($digest === null) {
            $body = $disk->get($path);
            $digest = hash('sha256', $body);
        }
        if (! hash_equals($selections->first()->extraction_sha256, $digest)) {
            return [];
        }
        $reviews = DB::table('historical_election_reviews')->where('archive', $edition)->orderBy('id')->get()->keyBy('fingerprint');
        $index = ElectionArchive::publicIndex()->where('edition_id', $edition)
            ->get(['state_label', 'record_code'])->sortBy(['state_label', 'record_code'])->values();
        $fingerprint = hash('sha256', serialize([$digest, $selections->all(), $reviews->all(), $index->all(), $analysisVersion, hash_file('sha256', __FILE__)]));
        $key = 'home-edition-rollup-v1:'.hash('sha256', $edition);
        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint && is_array($cached['items'] ?? null)) {
            if (($store = Cache::getStore()) instanceof DatabaseStore) {
                $this->selectionExpiries[] = $store->getPrefix().$key;
                if (count($this->selectionExpiries) >= 250) {
                    $this->flushSelectionExpiries();
                }
            } else {
                Cache::put($key, $cached, 2592000);
            }

            return $cached['items'];
        }
        $body ??= $disk->get($path);
        if (! hash_equals($digest, hash('sha256', $body))) {
            return [];
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $items = [];
        foreach ($selections as $selection) {
            $codes = $index->filter(fn (object $row): bool => $row->state_label === $selection->state_label)->pluck('record_code')->flip();
            $records = [];
            foreach ($data['records'] ?? [] as $record) {
                if (! $codes->has($record['code'] ?? -1)) {
                    continue;
                }
                $review = $reviews->get(app(HistoricalElectionReview::class)->fingerprint($record, $data['source_sha256']));
                $record = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $record;
                if ($review) {
                    $record['has_warning'] = false;
                }
                $records[] = $record;
            }
            $candidates = 0;
            foreach ($records as $record) {
                $candidates += count(array_filter($record['candidates'] ?? [], fn (array $candidate): bool => ! ($candidate['is_nota'] ?? false) && strtoupper($candidate['party_at_election'] ?? '') !== 'NOTA'));
            }
            $items[] = ['selection' => (array) $selection, 'summary' => $this->summarizeRecords($edition, $selection, $records, $analysisVersion),
                'candidates' => $candidates, 'review' => collect($records)->contains(fn (array $record): bool => ($record['has_warning'] ?? false) || ($record['status'] ?? '') !== 'validated' || ! empty($record['error']))];
        }
        Cache::put($key, ['fingerprint' => $fingerprint, 'items' => $items], 2592000);

        return $items;
    }

    private function refreshDashboardIfDue(string $key): void
    {
        $built = Cache::get($key.':built');
        if (is_int($built) && now()->getTimestamp() - $built < 3000) {
            return;
        }
        $lock = Cache::lock($key.':build', 180);
        if (! $lock->get()) {
            return;
        }
        \Illuminate\Support\defer(function () use ($key, $lock): void {
            try {
                $built = Cache::get($key.':built');
                if (is_int($built) && now()->getTimestamp() - $built < 3000) {
                    return;
                }
                $this->dashboardResult(true, $key);
            } finally {
                $lock->release();
            }
        }, name: 'pollmedia-home-summary:'.$key);
    }

    private function analysisVersion(): string
    {
        $fingerprints = [];
        foreach ([
            app_path('Services/HistoricalElectionAnalytics.php'),
            database_path('fixtures/official-successful-candidates.json'),
            database_path('fixtures/official-uncontested-results.json'),
        ] as $path) {
            $fingerprints[] = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : null;
        }

        return hash('sha256', serialize($fingerprints));
    }

    private function summarizeRecords(string $edition, object $selection, array $records, string $analysisVersion): array
    {
        $key = 'home-selection-analysis-v1:'.hash('sha256', serialize([
            $edition, $selection->kind, $selection->year, $selection->state_label,
        ]));
        $fingerprint = hash('sha256', $analysisVersion.serialize($records));
        $cached = Cache::get($key);
        if (! is_array($cached) || ($cached['fingerprint'] ?? null) !== $fingerprint
            || ! is_array($cached['summary'] ?? null)) {
            $cached = ['fingerprint' => $fingerprint, 'summary' => app(HistoricalElectionAnalytics::class)->summarize($records)];
            Cache::put($key, $cached, 2592000);
        } elseif (($store = Cache::getStore()) instanceof DatabaseStore) {
            $this->selectionExpiries[] = $store->getPrefix().$key;
            if (count($this->selectionExpiries) >= 250) {
                $this->flushSelectionExpiries();
            }
        } else {
            Cache::put($key, $cached, 2592000);
        }

        return $cached['summary'];
    }

    private function flushSelectionExpiries(): void
    {
        if ($this->selectionExpiries === []) {
            return;
        }
        $store = Cache::getStore();
        if (! $store instanceof DatabaseStore) {
            $this->selectionExpiries = [];

            return;
        }
        $table = config('cache.stores.'.config('cache.default').'.table', 'cache');
        $store->getConnection()->table($table)->whereIn('key', $this->selectionExpiries)
            ->update(['expiration' => now()->getTimestamp() + 2592000]);
        $this->selectionExpiries = [];
    }

    public function seats(string $kind, string $state): array
    {
        $base = ElectionArchive::publicIndex()->where('kind', $kind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($state))]);
        $year = (clone $base)->max('year');
        $edition = (clone $base)->where('year', $year)->groupBy('edition_id')->orderByRaw('COUNT(*) DESC')->orderByDesc('edition_id')->value('edition_id');

        return (clone $base)->where('edition_id', $edition)->orderBy('constituency_name')->pluck('constituency_name')->unique(fn ($name) => mb_strtolower($name))->map(fn ($name): array => ['name' => Str::title($name), 'url' => ConstituencyUrls::url(['kind' => $kind, 'state' => ElectionPlaceIdentity::state($state), 'name' => $name])])->values()->all();
    }
}
