<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeElectionSummary
{
    public function dashboard(): array
    {
        $groups = DB::table('historical_constituency_index')->whereIn('kind', ['pc', 'ac'])->whereNotNull('state_label')
            ->selectRaw('kind, year, state_label, edition_id, extraction_sha256, COUNT(*) as tables_count, SUM(candidate_count) as candidates_count')
            ->groupBy('kind', 'year', 'state_label', 'edition_id', 'extraction_sha256')->orderBy('edition_id')->orderBy('state_label')->get();
        $version = DB::table('historical_election_reviews')->max('id') ?? 0;

        return Cache::remember('home-election-v2:'.hash('sha256', $groups->toJson().$version), 3600, function () use ($groups): array {
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
            foreach (collect($selected)->groupBy('edition_id') as $edition => $selections) {
                $path = 'election-archive/'.$edition.'/extraction.json';
                if (! $disk->exists($path)) {
                    continue;
                }
                $body = $disk->get($path);
                if (! hash_equals($selections->first()->extraction_sha256, hash('sha256', $body))) {
                    continue;
                }
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $reviews = DB::table('historical_election_reviews')->where('archive', $edition)->orderBy('id')->get()->keyBy('fingerprint');
                foreach ($selections as $selection) {
                    $codes = DB::table('historical_constituency_index')->where('edition_id', $edition)->where('state_label', $selection->state_label)->pluck('record_code')->flip();
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
                    $summary = app(HistoricalElectionAnalytics::class)->summarize($records);
                    $kind = $selection->kind;
                    $year = (int) $selection->year;
                    $point = $annual[$kind][$year] ?? ['year' => $year, 'electors' => 0, 'polled' => 0, 'turnout_count' => 0, 'party_count' => 0, 'tables' => 0, 'candidates' => 0, 'state_elections' => 0, 'parties' => [], 'review' => false];
                    foreach (['electors', 'polled', 'turnout_count', 'party_count', 'tables'] as $metric) {
                        $point[$metric] += $summary[$metric] ?? 0;
                    }
                    foreach ($records as $record) {
                        $point['candidates'] += count(array_filter($record['candidates'] ?? [], fn (array $candidate): bool => ! ($candidate['is_nota'] ?? false) && strtoupper($candidate['party_at_election'] ?? '') !== 'NOTA'));
                    }
                    foreach ($summary['parties'] as $party) {
                        $point['parties'][$party['party']] = ($point['parties'][$party['party']] ?? 0) + $party['votes'];
                    }
                    $point['state_elections']++;
                    $point['review'] = $point['review'] || collect($records)->contains(fn (array $record): bool => ($record['has_warning'] ?? false) || ($record['status'] ?? '') !== 'validated' || ! empty($record['error']));
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

            return $result;
        });
    }

    public function seats(string $kind, string $state): array
    {
        $base = DB::table('historical_constituency_index')->where('kind', $kind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($state))]);
        $year = (clone $base)->max('year');
        $edition = (clone $base)->where('year', $year)->groupBy('edition_id')->orderByRaw('COUNT(*) DESC')->orderByDesc('edition_id')->value('edition_id');

        return (clone $base)->where('edition_id', $edition)->orderBy('constituency_name')->pluck('constituency_name')->unique(fn ($name) => mb_strtolower($name))->map(fn ($name): array => ['name' => Str::title($name), 'url' => route('constituency.overview', ['kind' => $kind, 'state' => ElectionPlaceIdentity::state($state), 'name' => $name])])->values()->all();
    }
}
