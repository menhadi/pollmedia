<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StateElectionPublication
{
    public function publish(string $id, int $user): void
    {
        abort_unless(DB::table('users')->where('id', $user)->where('is_admin', true)->exists(), 403);
        $batch = DB::table('election_import_batches')->find($id);
        abort_unless($batch && in_array($batch->status, ['ready', 'needs_attention'], true) && $batch->ready_count > 0 && $batch->ready_count + $batch->invalid_count === 403, 422, 'A complete processed batch with validated rows is required.');
        if ($batch->published_at) {
            return;
        }
        abort_unless($batch->adapter === 'eci-up-'.$batch->year.'-v1' && in_array($batch->year, [2012, 2017, 2022], true) && $batch->state === 'Uttar Pradesh', 422);
        $service = app(ElectionBatch::class);
        $reference = $service->reference($batch->year);
        abort_unless($batch->detail_sha256 === $reference['sha256'] && $batch->summary_sha256 === $reference['totals_sha256'] && $batch->source_url === $reference['url'], 422, 'The batch must match the verified official edition.');
        $service->verifyFiles($batch);
        $extracted = $service->extract($batch);
        abort_unless(array_column($extracted, 'code') === range(1, 403), 422);
        DB::transaction(function () use ($id, $user, $extracted, $service, $reference): void {
            $batch = DB::table('election_import_batches')->where('id', $id)->lockForUpdate()->first();
            if ($batch->published_at) {
                return;
            }
            abort_unless(in_array($batch->status, ['ready', 'needs_attention'], true), 409);
            $rows = DB::table('election_import_batch_rows')->where('batch_id', $id)->orderBy('code')->lockForUpdate()->get();
            abort_unless($rows->pluck('code')->all() === range(1, 403), 422);
            abort_unless($rows->where('status', 'ready')->count() === $batch->ready_count && $rows->where('status', 'invalid')->count() === $batch->invalid_count, 422);
            foreach ($rows as $index => $row) {
                if ($row->status === 'invalid') {
                    abort_unless(isset($extracted[$index]['error']) && $row->error === $extracted[$index]['error'], 422, 'Withheld row validation changed.');

                    continue;
                }
                $data = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                abort_unless($row->status === 'ready' && ! isset($extracted[$index]['error']) && $data === $extracted[$index]['payload'] && $row->name === $data['name'], 422, 'Staged rows differ from the archived report. Re-extract before publication.');
                app(ElectionPublication::class)->validate($data);
                $identifiers = DB::table('place_identifiers')->where('namespace', 'electoral:IN:UP:ac')->where('code', (string) $row->code)->pluck('place_id')->unique();
                abort_if($identifiers->count() > 1, 409, 'Conflicting constituency identifiers require review.');
                $place = $identifiers->isNotEmpty() ? DB::table('places')->find($identifiers->first()) : null;
                if ($place) {
                    abort_unless($place->type === 'ac' && $place->country_code === 'IN' && (Str::slug($place->name) === Str::slug($row->name) || (($reference['name_variants'][$row->code]['source'] ?? null) === $row->name && ($reference['name_variants'][$row->code]['current'] ?? null) === $place->name)), 409, 'Existing constituency identity differs from the report.');
                    $placeId = $place->id;
                } else {
                    abort_unless($batch->year === 2022, 422, 'Historical editions require an existing verified constituency identity.');
                    $slug = 'ac-uttar-pradesh-'.$row->code.'-'.Str::slug($row->name);
                    abort_if(DB::table('places')->where('slug', $slug)->exists(), 409, 'An unverified page already uses this URL.');
                    $placeId = DB::table('places')->insertGetId(['slug' => $slug, 'name' => $row->name, 'type' => 'ac', 'country_code' => 'IN', 'created_at' => now(), 'updated_at' => now()]);
                }
                $current = DB::table('election_contests')->where('place_id', $placeId)->where('year', $batch->year)->where('election_type', 'vidhan_sabha_general')->where('active', true)->get();
                abort_if($current->count() > 1, 409, 'Multiple active editions require review.');
                if ($current->isNotEmpty()) {
                    $contest = $current->first();
                    $source = DB::table('source_releases')->find($contest->source_release_id);
                    $old = json_decode($source->payload, true, 512, JSON_THROW_ON_ERROR);
                    abort_unless($source->status === 'accepted' && ($old['code'] ?? null) === $row->code, 409, 'Existing edition identity needs review.');
                    foreach (['candidates', 'electors', 'votes_polled', 'valid_candidate_votes'] as $field) {
                        abort_unless(($old[$field] ?? null) === $data[$field], 409, 'Existing results differ. Use an individual publication review.');
                    }
                    $release = $source->id;
                    $contestId = $contest->id;
                } else {
                    $data = array_merge($data, ['url' => $batch->source_url, 'totals_url' => $batch->source_url, 'sha256' => $batch->detail_sha256, 'totals_sha256' => $batch->summary_sha256, 'checked_on' => substr($batch->created_at, 0, 10), 'publication_mapping' => 'eci-up-ac-'.$batch->year, 'batch_id' => $batch->id]);
                    $key = 'eci-up-ac-'.$row->code.'-'.$batch->year;
                    DB::table('data_sources')->insertOrIgnore(['key' => $key, 'publisher' => 'Election Commission of India', 'url' => $batch->source_url, 'reuse_status' => 'research_pilot', 'last_checked_at' => $batch->created_at, 'created_at' => now(), 'updated_at' => now()]);
                    $sourceId = DB::table('data_sources')->where('key', $key)->value('id');
                    $release = DB::table('source_releases')->insertGetId(['data_source_id' => $sourceId, 'version_key' => 'state-batch-'.$batch->id, 'sha256' => $batch->detail_sha256, 'url' => $batch->source_url, 'retrieved_at' => $batch->created_at, 'status' => 'accepted', 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    $contestId = DB::table('election_contests')->insertGetId(['place_id' => $placeId, 'source_release_id' => $release, 'year' => $batch->year, 'election_type' => 'vidhan_sabha_general', 'source_locator' => $data['source_locator'], 'electors' => $data['electors'], 'votes_polled' => $data['votes_polled'], 'valid_candidate_votes' => $data['valid_candidate_votes'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($data['candidates'] as $candidate) {
                        DB::table('election_candidate_results')->insert(array_merge($candidate, ['election_contest_id' => $contestId]));
                    }
                }
                DB::table('place_identifiers')->insertOrIgnore(['namespace' => 'electoral:IN:UP:ac', 'code' => (string) $row->code, 'version' => 'eci-election-'.$batch->year, 'place_id' => $placeId, 'source_release_id' => $release]);
                DB::table('election_import_batch_rows')->where('id', $row->id)->update(['place_id' => $placeId, 'published_contest_id' => $contestId]);
            }
            $service->verifyFiles($batch);
            DB::table('election_import_batches')->where('id', $id)->update(['published_by' => $user, 'published_at' => now(), 'updated_at' => now()]);
        });
    }
}
