<?php

namespace Database\Seeders;

use App\Services\ElectionResults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PilibhitElectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fixtures = json_decode(file_get_contents(database_path('fixtures/pilibhit-elections.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $data) {
            app(ElectionResults::class)->validate($data);
        }
        DB::transaction(function () use ($fixtures): void {
            $place = DB::table('places')->where('slug', 'pc-pilibhit')->value('id');
            foreach ($fixtures as $data) {
                $key = 'eci-pilibhit-pc-'.$data['year'];
                DB::table('data_sources')->updateOrInsert(['key' => $key], ['publisher' => 'Election Commission of India', 'url' => $data['url'], 'reuse_status' => 'research_pilot', 'last_checked_at' => $data['checked_on']]);
                $source = DB::table('data_sources')->where('key', $key)->value('id');
                DB::table('source_releases')->insertOrIgnore(['data_source_id' => $source, 'version_key' => $data['sha256'], 'sha256' => $data['sha256'], 'url' => $data['url'], 'retrieved_at' => $data['checked_on'], 'status' => 'accepted', 'payload' => json_encode($data)]);
                $release = DB::table('source_releases')->where('data_source_id', $source)->where('version_key', $data['sha256'])->value('id');
                if (DB::table('election_contests')->where('place_id', $place)->where('source_release_id', $release)->exists()) {
                    continue;
                }
                $existing = DB::table('election_contests')->where('place_id', $place)->where('year', $data['year'])->exists();
                $contest = DB::table('election_contests')->insertGetId(['place_id' => $place, 'source_release_id' => $release, 'year' => $data['year'], 'election_type' => 'lok_sabha_general', 'source_locator' => $data['source_locator'], 'electors' => $data['electors'], 'votes_polled' => $data['votes_polled'], 'valid_candidate_votes' => $data['valid_candidate_votes'], 'active' => ! $existing]);
                foreach ($data['candidates'] as $row) {
                    DB::table('election_candidate_results')->insert(array_merge($row, ['election_contest_id' => $contest]));
                }
            }
        });
    }
}
