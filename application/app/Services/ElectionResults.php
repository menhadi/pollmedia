<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ElectionResults
{
    public function validate(array $data): void
    {
        $rows = collect($data['candidates']);
        if ($rows->count() < 2 || $rows->pluck('source_row')->unique()->count() !== $rows->count()
            || $rows->where('is_nota', true)->count() > 1) {
            throw new InvalidArgumentException('Incomplete or duplicated candidate rows.');
        }
        foreach ($rows as $row) {
            if ($row['votes'] < 0 || $row['general_votes'] < 0 || $row['postal_votes'] < 0
                || $row['votes'] !== $row['general_votes'] + $row['postal_votes']) {
                throw new InvalidArgumentException('Candidate vote components do not reconcile.');
            }
        }
        if ($data['electors'] <= 0 || $data['votes_polled'] <= 0 || $data['votes_polled'] > $data['electors']
            || $rows->where('is_nota', false)->sum('votes') !== $data['valid_candidate_votes']
            || $rows->sum('votes') > $data['votes_polled']) {
            throw new InvalidArgumentException('Constituency vote totals do not reconcile.');
        }
    }

    public function forPlace(int $placeId): Collection
    {
        return DB::table('election_contests as e')->join('source_releases as s', 's.id', '=', 'e.source_release_id')
            ->where('e.place_id', $placeId)->where('e.active', true)->where('s.status', 'accepted')
            ->orderByDesc('e.year')->select('e.*', 's.url', 's.retrieved_at', 's.sha256', 's.payload')->get()
            ->map(function (object $contest): object {
                $contest->rows = DB::table('election_candidate_results')->where('election_contest_id', $contest->id)
                    ->orderByDesc('votes')->orderBy('source_row')->get();
                $candidates = $contest->rows->where('is_nota', false)->values();
                $contest->winner = $candidates[0];
                $contest->runner = $candidates[1];
                $contest->margin = $contest->winner->votes - $contest->runner->votes;
                $contest->margin_pp = 100 * $contest->margin / $contest->votes_polled;
                $contest->counted_votes = $contest->rows->sum('votes');
                $contest->turnout = 100 * $contest->counted_votes / $contest->electors;
                $contest->totals_url = json_decode($contest->payload, true)['totals_url'] ?? null;
                $contest->nota = $contest->rows->where('is_nota', true)->sum('votes');
                $contest->unallocated = $contest->votes_polled - $contest->rows->sum('votes');

                return $contest;
            });
    }
}
