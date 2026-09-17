<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PilibhitPopulationHistorySeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('fixtures/pilibhit-population-history.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($data): void {
            DB::table('data_sources')->insertOrIgnore(['key' => 'census-pilibhit-population-history', 'publisher' => 'Census of India', 'url' => $data['landing'], 'reuse_status' => 'research_pilot', 'last_checked_at' => $data['checked_on'], 'created_at' => now(), 'updated_at' => now()]);
            $source = DB::table('data_sources')->where('key', 'census-pilibhit-population-history')->value('id');
            DB::table('source_releases')->insertOrIgnore(['data_source_id' => $source, 'version_key' => $data['sha256'], 'sha256' => $data['sha256'], 'url' => $data['landing'], 'retrieved_at' => $data['checked_on'], 'status' => 'accepted', 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
