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
        $effective = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $record;
        $effective['review_fingerprint'] = $fingerprint;
        $effective['review_id'] = $review?->id ?? 0;
        $effective['review'] = $review;
        $effective['has_warning'] = ! $review && $record['status'] !== 'validated';

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
            unset($record['review'], $record['review_fingerprint'], $record['review_id'], $record['has_warning']);
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
