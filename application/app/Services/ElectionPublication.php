<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class ElectionPublication
{
    public function contests(): Collection
    {
        return DB::table('election_contests as e')->join('places as p', 'p.id', '=', 'e.place_id')->where('e.active', true)
            ->select('e.*', 'p.name', 'p.type', 'p.slug')->orderBy('p.type')->orderBy('p.name')->orderByDesc('e.year')->get()
            ->filter(fn ($contest) => $this->supported($contest));
    }

    private function supported(object $contest): bool
    {
        return ($contest->slug === 'pc-pilibhit' && $contest->election_type === 'lok_sabha_general' && in_array($contest->year, [2019, 2024], true))
            || (in_array($contest->slug, ['ac-baheri', 'ac-pilibhit', 'ac-barkhera', 'ac-puranpur', 'ac-bisalpur'], true) && $contest->election_type === 'vidhan_sabha_general' && $contest->year === 2022);
    }

    public function stage(int $contestId, string $detail, ?string $totals, int $user): string
    {
        $contest = $this->contests()->firstWhere('id', $contestId);
        abort_unless($contest, 422, 'Choose a supported current election edition.');
        $source = DB::table('source_releases')->find($contest->source_release_id);
        $old = json_decode($source->payload, true);
        $scope = ['type' => $contest->type, 'year' => $contest->year, 'code' => $old['code'] ?? 26, 'name' => substr($contest->slug, 3)];
        $needsTotals = $contest->type === 'ac' || $contest->year === 2019;
        abort_if($needsTotals && ! $totals, 422, 'This edition needs its matching summary report.');
        $id = (string) Str::ulid();
        $extension = $contest->type === 'ac' ? 'xlsx' : 'pdf';
        $detailPath = 'election-imports/'.$id.'/detailed.'.$extension;
        $totalsPath = $needsTotals ? 'election-imports/'.$id.'/summary.'.$extension : null;
        foreach ([$detailPath => $detail, ...($totalsPath ? [$totalsPath => $totals] : [])] as $path => $input) {
            abort_if(filesize($input) > 50_000_000, 422, 'Each election report must be under 50 MB.');
            $stream = fopen($input, 'rb');
            try {
                abort_unless(Storage::disk('local')->put($path, $stream), 500, 'Report could not be archived.');
            } finally {
                fclose($stream);
            }
        }
        $data = $this->extract(Storage::disk('local')->path($detailPath), $totalsPath ? Storage::disk('local')->path($totalsPath) : null, $scope);
        abort_unless(($data['year'] ?? null) === $scope['year'] && ($data['code'] ?? null) === $scope['code'], 422, 'Extracted election scope does not match the selected edition.');
        $payload = array_replace($old, $data, ['checked_on' => now()->toDateString(), 'publication_mapping' => 'eci-pilot-v1']);
        if (isset($payload['polled_basis'])) {
            $payload['polled_basis'] = 'All votes polled use EVM votes plus postal votes counted in the summary report. Recorded-vote participation uses candidate and NOTA votes.';
        }
        $this->validate($payload);
        DB::table('election_publications')->insert(['id' => $id, 'base_contest_id' => $contestId, 'detail_path' => $detailPath, 'totals_path' => $totalsPath, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_by' => $user, 'created_at' => now()]);

        return $id;
    }

    public function extract(string $detail, ?string $totals, array $scope): array
    {
        $process = new Process([config('imports.python'), base_path('../pilot/extract_election_import.py'), $detail, $totals ?? '']);
        $process->setInput(json_encode($scope));
        $process->setTimeout(90);
        $process->run();
        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($data) || isset($data['error'])) {
            throw ValidationException::withMessages(['detail' => $data['error'] ?? 'Election extraction failed. Check the report pair and supported edition.']);
        }

        return $data;
    }

    public function validate(array $data): void
    {
        $errors = [];
        foreach (['electors', 'votes_polled', 'valid_candidate_votes'] as $field) {
            if (! isset($data[$field]) || ! is_int($data[$field]) || $data[$field] < 1 || $data[$field] > 999999999) {
                $errors[] = 'Invalid '.$field.'.';
            }
        }
        $rows = $data['candidates'] ?? [];
        if (count($rows) < 3 || count($rows) > 100 || count(array_filter($rows, fn ($row) => ($row['is_nota'] ?? null) === true)) !== (($data['year'] ?? null) === 2012 ? 0 : 1)) {
            $errors[] = 'A complete candidate table with the expected NOTA coverage for this edition is required.';
        }
        foreach ($rows as $row) {
            foreach (['source_row', 'general_votes', 'postal_votes', 'votes'] as $field) {
                if (! isset($row[$field]) || ! is_int($row[$field]) || $row[$field] < 0 || $row[$field] > 999999999) {
                    $errors[] = 'Invalid candidate numeric field.';
                }
            }
            foreach (['candidate_name', 'party_at_election'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '' || strlen($row[$field]) > 255 || strip_tags($row[$field]) !== $row[$field]) {
                    $errors[] = 'Invalid candidate name or party.';
                }
            }
            if (! is_bool($row['is_nota'] ?? null) || (($row['party_at_election'] ?? '') === 'NOTA') !== ($row['is_nota'] ?? null)) {
                $errors[] = 'Invalid NOTA classification.';
            }
        }
        if (! $errors) {
            $ranks = array_column($rows, 'source_row');
            sort($ranks);
            if ($ranks !== range(1, count($rows))) {
                $errors[] = 'Candidate ranks are incomplete or duplicated.';
            }
            try {
                app(ElectionResults::class)->validate($data);
            } catch (InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
            $votes = collect($rows)->where('is_nota', false)->sortByDesc('votes')->values();
            if ($votes[0]['votes'] === $votes[1]['votes']) {
                $errors[] = 'A tied result needs the official winner decision before publication.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['report' => implode(' ', array_unique($errors))]);
        }
    }

    public function preview(string $id): array
    {
        $draft = DB::table('election_publications')->find($id);
        abort_unless($draft, 404);
        $base = DB::table('election_contests')->find($draft->base_contest_id);
        $current = DB::table('election_contests')->where('place_id', $base->place_id)->where('year', $base->year)->where('election_type', $base->election_type)->where('active', true)->first();
        $source = DB::table('source_releases')->find($base->source_release_id);
        $old = json_decode($source->payload, true);
        $data = json_decode($draft->payload, true);
        $place = DB::table('places')->find($base->place_id);
        $changes = [];
        foreach (['electors', 'votes_polled', 'valid_candidate_votes'] as $field) {
            if ($old[$field] !== $data[$field]) {
                $changes[] = ['label' => str_replace('_', ' ', $field), 'before' => $old[$field], 'after' => $data[$field]];
            }
        }
        $before = collect($old['candidates'])->keyBy('source_row');
        $after = collect($data['candidates'])->keyBy('source_row');
        foreach ($before->keys()->merge($after->keys())->unique()->sort() as $rank) {
            foreach (['candidate_name', 'party_at_election', 'general_votes', 'postal_votes', 'votes', 'is_nota'] as $field) {
                $a = $before[$rank][$field] ?? null;
                $b = $after[$rank][$field] ?? null;
                if ($a !== $b) {
                    $changes[] = ['label' => 'Row '.$rank.' / '.str_replace('_', ' ', $field), 'before' => $a, 'after' => $b];
                }
            }
        }
        $metrics = [];
        foreach (['Current at draft creation' => $old, 'Imported' => $data] as $label => $values) {
            $ranked = collect($values['candidates'])->where('is_nota', false)->sortByDesc('votes')->values();
            $metrics[$label] = ['winner' => $ranked[0]['candidate_name'], 'party' => $ranked[0]['party_at_election'], 'margin' => $ranked[0]['votes'] - $ranked[1]['votes'], 'turnout' => 100 * $values['votes_polled'] / $values['electors'], 'participation' => 100 * array_sum(array_column($values['candidates'], 'votes')) / $values['electors']];
        }

        return compact('draft', 'base', 'current', 'old', 'data', 'place', 'changes', 'metrics');
    }

    public function publish(string $id, int $user): void
    {
        DB::transaction(function () use ($id, $user): void {
            $draft = DB::table('election_publications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($draft && $draft->status === 'needs_review', 409);
            $base = DB::table('election_contests')->where('id', $draft->base_contest_id)->lockForUpdate()->first();
            abort_unless($base->active, 409, 'Election edition changed. Create a fresh preview.');
            $preview = $this->preview($id);
            $data = $preview['data'];
            $this->validate($data);
            foreach ([$draft->detail_path => $data['sha256'], ...($draft->totals_path ? [$draft->totals_path => $data['totals_sha256']] : [])] as $path => $hash) {
                abort_unless(Storage::disk('local')->exists($path) && hash_equals($hash, hash_file('sha256', Storage::disk('local')->path($path))), 422, 'Archived report integrity check failed.');
            }
            if (! $preview['changes']) {
                DB::table('election_publications')->where('id', $id)->update(['status' => 'verified', 'published_by' => $user, 'published_at' => now()]);

                return;
            }
            $contest = $this->append($base, $data, $draft->created_at);
            DB::table('election_publications')->where('id', $id)->update(['status' => 'published', 'published_contest_id' => $contest, 'published_by' => $user, 'published_at' => now()]);
        });
    }

    public function restore(string $id, int $user): void
    {
        DB::transaction(function () use ($id, $user): void {
            $draft = DB::table('election_publications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($draft && $draft->status === 'published', 409);
            $published = DB::table('election_contests')->where('id', $draft->published_contest_id)->lockForUpdate()->first();
            abort_unless($published->active, 409, 'A newer edition is active; this publication cannot be rolled back.');
            $base = DB::table('election_contests')->find($draft->base_contest_id);
            $source = DB::table('source_releases')->find($base->source_release_id);
            $contest = $this->append($published, json_decode($source->payload, true), $source->retrieved_at);
            DB::table('election_publications')->where('id', $id)->update(['status' => 'rolled_back', 'restored_contest_id' => $contest, 'restored_by' => $user, 'restored_at' => now()]);
        });
    }

    private function append(object $base, array $data, string $retrieved): int
    {
        $source = DB::table('source_releases')->find($base->source_release_id);
        $release = DB::table('source_releases')->insertGetId(['data_source_id' => $source->data_source_id, 'version_key' => 'election-publication-'.Str::ulid(), 'sha256' => $data['sha256'], 'url' => $data['url'], 'retrieved_at' => $retrieved, 'status' => 'accepted', 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('election_contests')->where('id', $base->id)->update(['active' => false]);
        $contest = DB::table('election_contests')->insertGetId(['place_id' => $base->place_id, 'source_release_id' => $release, 'year' => $base->year, 'election_type' => $base->election_type, 'source_locator' => $data['source_locator'], 'electors' => $data['electors'], 'votes_polled' => $data['votes_polled'], 'valid_candidate_votes' => $data['valid_candidate_votes'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($data['candidates'] as $row) {
            DB::table('election_candidate_results')->insert(array_merge($row, ['election_contest_id' => $contest]));
        }

        return $contest;
    }
}
