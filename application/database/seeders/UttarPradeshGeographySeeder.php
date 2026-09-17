<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UttarPradeshGeographySeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['pc' => 'up-delimitation.pdf', 'district' => 'up-district-gazette-2023.pdf'] as $kind => $file) {
            $path = base_path('../pilot/raw/'.$file);
            abort_unless(is_file($path) && hash_equals($data[$kind.'_sha256'], hash_file('sha256', $path)), 422, 'Official geography archive is missing or changed.');
        }
        $codes = collect($data['pcs'])->flatMap(fn (array $pc): array => $pc['ac_codes'])->sort()->values()->all();
        abort_unless($codes === range(1, 403) && array_column($data['pcs'], 'code') === range(1, 80), 422);
        abort_unless(collect($data['district_rows'])->pluck('code')->sort()->values()->all() === range(1, 403), 422);
        abort_unless(collect($data['district_rows'])->pluck('district')->unique()->count() === 75, 422);
        DB::transaction(function () use ($data): void {
            $acIds = [];
            foreach ($data['district_rows'] as $row) {
                $ids = DB::table('place_identifiers')->where('namespace', 'electoral:IN:UP:ac')->where('version', 'eci-election-2022')->where('code', (string) $row['code'])->pluck('place_id');
                abort_unless($ids->count() === 1, 422, 'Publish all 403 Assembly constituencies before geography import.');
                $place = DB::table('places')->find($ids->first());
                $expected = [306 => 'Domariyaganj', 319 => 'Paniyra'][$row['code']] ?? $row['name'];
                abort_unless($place->type === 'ac' && $place->country_code === 'IN' && $this->normalized($place->name) === $this->normalized($expected), 409, 'AC code and name differ from the dated geography source.');
                $acIds[$row['code']] = $place->id;
            }
            $releases = [];
            foreach (['pc', 'district'] as $kind) {
                $key = 'up-statewide-'.$kind.'-geography';
                DB::table('data_sources')->insertOrIgnore(['key' => $key, 'publisher' => 'Election Commission of India', 'url' => $data[$kind.'_url'], 'reuse_status' => 'research_pilot', 'last_checked_at' => $data['checked_on'], 'created_at' => now(), 'updated_at' => now()]);
                $sourceId = DB::table('data_sources')->where('key', $key)->value('id');
                DB::table('source_releases')->insertOrIgnore(['data_source_id' => $sourceId, 'version_key' => $data[$kind.'_sha256'], 'sha256' => $data[$kind.'_sha256'], 'url' => $data[$kind.'_url'], 'published_on' => $data[$kind.'_source_date'], 'retrieved_at' => $data['checked_on'], 'status' => 'accepted', 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                $releases[$kind] = DB::table('source_releases')->where('data_source_id', $sourceId)->where('version_key', $data[$kind.'_sha256'])->value('id');
            }
            foreach ($data['pcs'] as $pc) {
                $ids = DB::table('place_identifiers')->where('namespace', 'electoral:IN:UP:pc')->where('code', (string) $pc['code'])->pluck('place_id')->unique();
                abort_if($ids->count() > 1, 409, 'Conflicting PC identifiers.');
                $placeId = $this->place('pc', $pc['name'], 'pc-uttar-pradesh-'.$pc['code'].'-'.Str::slug($pc['name']), $ids->first());
                DB::table('place_identifiers')->insertOrIgnore(['namespace' => 'electoral:IN:UP:pc', 'code' => (string) $pc['code'], 'version' => 'delimitation-order-34', 'place_id' => $placeId, 'source_release_id' => $releases['pc']]);
                foreach ($pc['ac_codes'] as $code) {
                    $this->relationship($acIds[$code], $placeId, 'assembly_segment_of', $releases['pc'], 'Table B, PDF page '.$pc['page'].', PC '.$pc['code'], $data['pc_source_date']);
                }
            }
            foreach (collect($data['district_rows'])->groupBy('district') as $name => $rows) {
                $existing = DB::table('places')->where('type', 'district')->where('country_code', 'IN')->where('slug', 'district-'.Str::slug($name))->value('id');
                $placeId = $this->place('district', $name, 'district-uttar-pradesh-'.Str::slug($name), $existing);
                foreach ($rows as $row) {
                    $this->relationship($acIds[$row['code']], $placeId, 'district_directory_lists', $releases['district'], '2023 Gazette table, PDF page '.$row['page'].', AC '.$row['code'], $data['district_source_date']);
                }
            }
        });
    }

    private function normalized(string $name): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(preg_replace('/\s*\((?:SC|ST)\)$/', '', $name)));
    }

    private function place(string $type, string $name, string $slug, ?int $existing): int
    {
        $place = $existing ? DB::table('places')->find($existing) : DB::table('places')->where('slug', $slug)->first();
        if ($place) {
            abort_unless($place->type === $type && $place->country_code === 'IN' && $this->normalized($place->name) === $this->normalized($name), 409, 'Place identity conflict.');

            return $place->id;
        }

        return DB::table('places')->insertGetId(['slug' => $slug, 'name' => $name, 'type' => $type, 'country_code' => 'IN', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function relationship(int $from, int $to, string $type, int $source, string $locator, string $date): void
    {
        abort_if(DB::table('place_relationships')->where('from_place_id', $from)->where('type', $type)->whereNull('valid_to')->where('to_place_id', '!=', $to)->exists(), 409, 'Conflicting relationship requires review.');
        DB::table('place_relationships')->updateOrInsert(['from_place_id' => $from, 'to_place_id' => $to, 'type' => $type, 'source_release_id' => $source], ['source_locator' => $locator, 'reference_date' => $date]);
    }
}
