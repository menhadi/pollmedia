<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistoricalElectionReview
{
    public function fingerprint(array $record, string $sourceHash): string
    {
        return hash('sha256', $sourceHash.json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function apply(string $archive, array $record, string $sourceHash): array
    {
        $fingerprint = $this->fingerprint($record, $sourceHash);
        $review = DB::table('historical_election_reviews')->where('archive', $archive)->where('code', $record['code'])->where('fingerprint', $fingerprint)->latest('id')->first();

        return $this->applyReviewedRecord($archive, $record, $sourceHash, $review, $fingerprint);
    }

    public function applyBatch(string $archive, array $records, string $sourceHash): array
    {
        if ($records === []) {
            return [];
        }
        $reviews = [];
        foreach (DB::table('historical_election_reviews')->where('archive', $archive)->latest('id')->get() as $review) {
            if ($review->code === null) {
                continue;
            }
            $key = (int) $review->code.':'.$review->fingerprint;
            $reviews[$key] ??= $review;
        }
        $result = [];
        foreach ($records as $record) {
            if (! is_int($record['code']) || $record['code'] < -2147483648 || $record['code'] > 2147483647) {
                $result[$record['code']] = $this->apply($archive, $record, $sourceHash);

                continue;
            }
            $fingerprint = $this->fingerprint($record, $sourceHash);
            $review = $reviews[$record['code'].':'.$fingerprint] ?? null;
            $result[$record['code']] = $this->applyReviewedRecord($archive, $record, $sourceHash, $review === null ? null : clone $review, $fingerprint);
        }

        return $result;
    }

    private function applyReviewedRecord(string $archive, array $record, string $sourceHash, ?object $review, string $fingerprint): array
    {
        $effective = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $record;
        unset($effective['display_data_note']);
        if ($archive === '349e04305ee4652986f79497'
            && $sourceHash === '8c3e317c3922f4fe2a4e5ce7a7264dbffcfdd3cd547432283996d84dfa7244b9'
            && $this->fingerprint($effective, $sourceHash) === '16201fb0fbe5984ebbb0e6a1817adcaf4a3718eb3b5dd47738611b4c9b8a6857') {
            $effective['display_data_note'] = 'The official detailed report and summary both record 388,507 voters. The preserved summary polled total of 388,508 includes one explicitly reported test vote. This is a difference in definitions, not a disagreement in voter counts. Candidate votes including NOTA total 384,893; the remaining 3,614 comprise 1,007 CU votes not counted and 2,607 postal deductions excluding NOTA.';
        }

        $effective['review_fingerprint'] = $fingerprint;
        $effective['review_archive'] = $archive;
        $effective['review_id'] = $review?->id ?? 0;
        $effective['review'] = $review;
        $effective['has_warning'] = ! $review && $record['status'] !== 'validated';

        foreach ($effective['candidates'] ?? [] as $index => $candidate) {
            unset($effective['candidates'][$index]['display_name']);
        }

        // Assembly Secretariat biography (2024), p23, confirms the same member's
        // Serlui 2018-2023 term. Keep the ECI name/source_values verbatim.
        if ($archive === '52d4a7bf9d99af68b8a0b9bb'
            && $sourceHash === 'ad94423972f2d307226c16b702f16836affb30d2b2a076739ebe342909433eb7'
            && ($effective['code'] ?? null) === 6 && ($effective['name'] ?? null) === 'Serlui (ST)'
            && ($effective['state_name'] ?? null) === 'Mizoram' && ($effective['detail_page'] ?? null) === 2) {
            $rawName = "LALRINSANGA RALTE ''''''''''''''''E''''''''''''''''";
            foreach ($effective['candidates'] as &$candidate) {
                if (($candidate['candidate_name'] ?? null) === $rawName
                    && ($candidate['source_values'][1] ?? null) === $rawName
                    && ($candidate['party_at_election'] ?? null) === 'MNF'
                    && ($candidate['general_votes'] ?? null) === 6050
                    && ($candidate['postal_votes'] ?? null) === 78 && ($candidate['votes'] ?? null) === 6128) {
                    $candidate['display_name'] = 'LALRINSANGA RALTE';
                }
            }
            unset($candidate);
        }

        return $effective;
    }

    public function save(string $archive, array $original, string $sourceHash, array $input, int $userId): void
    {
        DB::transaction(function () use ($archive, $original, $sourceHash, $input, $userId): void {
            $current = $this->apply($archive, $original, $sourceHash);
            if (! hash_equals($current['review_fingerprint'], $input['fingerprint']) || (int) $input['review_id'] !== (int) $current['review_id']) {
                throw ValidationException::withMessages(['review' => 'This record changed. Reload it before reviewing.']);
            }
            $record = $current;
            foreach ($record['candidates'] ?? [] as $index => $candidate) {
                unset($record['candidates'][$index]['display_name']);
            }
            unset($record['display_data_note'], $record['review'], $record['review_fingerprint'], $record['review_archive'], $record['review_id'], $record['has_warning']);
            if ($input['action'] === 'correct') {
                foreach (['name', 'electors', 'votes_polled', 'valid_candidate_votes'] as $key) {
                    $record[$key] = $key === 'name' ? $input[$key] : (int) $input[$key];
                }
                $record['candidates'] = array_map(function (array $row, int $index): array {
                    foreach (['votes', 'general_votes', 'postal_votes'] as $key) {
                        $row[$key] = isset($row[$key]) ? (int) $row[$key] : null;
                    }
                    $row['source_row'] = $index + 1;
                    $row['is_nota'] = strtoupper(trim($row['party_at_election'])) === 'NOTA';
                    if ($row['general_votes'] !== null && $row['postal_votes'] !== null && $row['votes'] !== $row['general_votes'] + $row['postal_votes']) {
                        throw ValidationException::withMessages(['candidates' => 'Candidate vote components must equal the total.']);
                    }

                    return $row;
                }, array_values($input['candidates']), range(0, count($input['candidates']) - 1));
                $contestingCandidates = collect($record['candidates'])->reject(fn (array $row): bool => $row['is_nota']);
                if (in_array(null, array_column($record['candidates'], 'votes'), true) || $contestingCandidates->sum('votes') !== $record['valid_candidate_votes'] || collect($record['candidates'])->sum('votes') > $record['votes_polled'] || $record['votes_polled'] > $record['electors']) {
                    throw ValidationException::withMessages(['totals' => 'Corrected candidate, valid-vote, polled-vote and elector totals must reconcile. Use acceptance for a documented unresolved source difference.']);
                }
                unset($record['winner'], $record['margin']);
                if (($record['number_of_seats'] ?? 1) === 1 && $contestingCandidates->count() > 1) {
                    $ranked = $contestingCandidates->sortByDesc('votes')->values();
                    if ($ranked[0]['votes'] > $ranked[1]['votes']) {
                        $record['winner'] = $ranked[0]['candidate_name'];
                        $record['margin'] = $ranked[0]['votes'] - $ranked[1]['votes'];
                    }
                }
            }
            $record['status'] = $input['action'] === 'correct' ? 'corrected' : 'accepted';
            DB::table('historical_election_reviews')->insert(['archive' => $archive, 'code' => $original['code'], 'fingerprint' => $current['review_fingerprint'], 'action' => $input['action'], 'reason' => $input['reason'], 'reference_url' => $input['reference_url'] ?? null, 'record' => json_encode($record, JSON_THROW_ON_ERROR), 'reviewed_by' => $userId, 'created_at' => now()]);
        });
    }
}
