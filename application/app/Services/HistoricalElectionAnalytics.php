<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HistoricalElectionAnalytics
{
    public function forState(string $state, string $kind): array
    {
        $entries = [];
        if ($kind === 'ac') {
            $catalogue = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($catalogue['entries'] as $entry) {
                if ($entry['state'] === $state) {
                    $entries[$entry['url']] = $entry['label'].' '.$state;
                }
            }
        }
        if ($kind === 'pc' || $state === 'Uttar Pradesh') {
            foreach (app(ElectionArchive::class)->catalogue()[$kind] as [$label, $url]) {
                $entries[$url] = $label;
            }
        }
        $result = [];
        $disk = app(ArchiveFiles::class);
        foreach ($entries as $url => $label) {
            $id = substr(hash('sha256', $url), 0, 24);
            $path = 'election-archive/'.$id.'/extraction.json';
            if (! $disk->exists($path)) {
                continue;
            }
            $body = $disk->get($path);
            $reviewVersion = DB::table('historical_election_reviews')->where('archive', $id)->max('id') ?? 0;
            $key = 'election-analysis-v1:'.hash('sha256', $body.$state.$kind.$reviewVersion);
            $summary = Cache::remember($key, 900, function () use ($body, $url, $label, $state, $kind, $id): ?array {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (($data['source_url'] ?? '') !== $url || ($data['kind'] ?? '') !== $kind || ($data['year'] ?? 0) !== (int) substr($label, 0, 4)) {
                    return null;
                }
                $reviews = DB::table('historical_election_reviews')->where('archive', $id)->orderBy('id')->get()->keyBy('fingerprint');
                $records = [];
                $sourceState = null;
                foreach ($data['records'] as $record) {
                    $recordState = $record['state_name'] ?? $record['state_code'] ?? ($kind === 'ac' ? $state : '');
                    if (mb_strtolower($recordState) !== mb_strtolower($state)) {
                        continue;
                    }
                    $sourceState ??= $recordState;
                    $fingerprint = app(HistoricalElectionReview::class)->fingerprint($record, $data['source_sha256']);
                    $review = $reviews->get($fingerprint);
                    $record = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $record;
                    $record['has_warning'] = ! $review && ($record['status'] ?? '') !== 'validated';
                    $records[] = $record;
                }
                if ($records === []) {
                    return null;
                }

                return $this->summarize($records) + ['id' => $id, 'year' => $data['year'], 'label' => $label, 'source_url' => $url, 'state' => $sourceState];
            });
            if ($summary) {
                $result[] = $summary;
            }
        }
        usort($result, fn (array $a, array $b): int => [$b['year'], $b['id']] <=> [$a['year'], $a['id']]);

        return $result;
    }

    public function summarize(array $records): array
    {
        $electors = $polled = $turnoutCount = $partyCount = $voteTotal = 0;
        $margins = $parties = $marginPercentages = [];
        $identities = array_count_values(array_map(fn (array $r): string => (string) ($r['official_pc_code'] ?? $r['official_ac_code'] ?? $r['code']), $records));
        foreach ($records as $record) {
            $identity = (string) ($record['official_pc_code'] ?? $record['official_ac_code'] ?? $record['code']);
            if (($record['has_warning'] ?? (($record['status'] ?? '') !== 'validated')) || ($record['number_of_seats'] ?? 1) !== 1 || $identities[$identity] !== 1) {
                continue;
            }
            if ($this->count($record['electors'] ?? null) && $record['electors'] > 0 && $this->count($record['votes_polled'] ?? null) && $record['votes_polled'] <= $record['electors']) {
                $electors += $record['electors'];
                $polled += $record['votes_polled'];
                $turnoutCount++;
            }
            $candidates = $record['candidates'] ?? [];
            if ($candidates === [] || collect($candidates)->contains(fn (array $c): bool => ! $this->count($c['votes'] ?? null) || trim($c['party_at_election'] ?? '') === '')) {
                continue;
            }
            $total = array_sum(array_column($candidates, 'votes'));
            if ($total <= 0 || ($this->count($record['votes_polled'] ?? null) && $total > $record['votes_polled'])) {
                continue;
            }
            $partyCount++;
            $voteTotal += $total;
            foreach ($candidates as $candidate) {
                $party = ($candidate['is_nota'] ?? false) ? 'NOTA' : trim($candidate['party_at_election']);
                $parties[$party] = ($parties[$party] ?? 0) + $candidate['votes'];
            }
            $ranked = collect($candidates)->reject(fn (array $c): bool => ($c['is_nota'] ?? false) || strtoupper(trim($c['party_at_election'])) === 'NOTA')->sortByDesc('votes')->values();
            if ($ranked->count() >= 2 && $ranked[0]['votes'] > $ranked[1]['votes']) {
                $margin = $ranked[0]['votes'] - $ranked[1]['votes'];
                $margins[] = $margin;
                $marginPercentages[] = 100 * $margin / $total;
            }
        }
        arsort($parties);
        $partyRows = [];
        foreach ($parties as $party => $votes) {
            $partyRows[] = ['party' => (string) $party, 'votes' => $votes, 'share' => 100 * $votes / $voteTotal];
        }

        return ['tables' => count($records), 'turnout_count' => $turnoutCount, 'electors' => $turnoutCount ? $electors : null,
            'polled' => $turnoutCount ? $polled : null, 'turnout' => $electors ? 100 * $polled / $electors : null,
            'party_count' => $partyCount, 'parties' => $partyRows, 'margin_count' => count($margins),
            'margin' => $margins ? array_sum($margins) / count($margins) : null,
            'margin_percent' => $marginPercentages ? array_sum($marginPercentages) / count($marginPercentages) : null];
    }

    private function count(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
