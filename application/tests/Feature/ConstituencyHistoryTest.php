<?php

namespace Tests\Feature;

use App\Services\ConstituencyHistory;
use App\Services\HistoricalElectionArchive;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function mappedPlace(): object
    {
        $this->seed(PilibhitSeeder::class);
        $place = DB::table('places')->where('slug', 'pc-pilibhit')->first();
        $fixture = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true);
        $sourceId = DB::table('data_sources')->where('key', 'eci-up-delimitation')->value('id');
        $release = DB::table('source_releases')->where('data_source_id', $sourceId)->value('id');
        DB::table('source_releases')->where('id', $release)->update(['sha256' => $fixture['pc_sha256']]);
        DB::table('place_identifiers')->insert(['namespace' => 'electoral:IN:UP:pc', 'code' => '26', 'version' => 'delimitation-order-34', 'place_id' => $place->id, 'source_release_id' => $release]);

        return $place;
    }

    private function mockEditions(array $overrides = []): void
    {
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($overrides): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $archive) use ($overrides): array {
                $year = array_search($archive, ConstituencyHistory::EDITIONS, true);
                $record = array_replace(['code' => 451, 'state_code' => 'S24', 'official_pc_code' => 26, 'constituency_name' => 'PILIBHIT', 'name' => 'Uttar Pradesh / PILIBHIT', 'status' => 'validated', 'winner' => 'Candidate A', 'margin' => 5, 'electors' => 30, 'votes_polled' => 15, 'candidates' => [['candidate_name' => 'Candidate A', 'party_at_election' => 'PARTY A', 'votes' => 10], ['candidate_name' => 'Candidate B', 'party_at_election' => 'PARTY B', 'votes' => 5]]], $overrides[$year] ?? []);

                return [['kind' => 'pc', 'year' => $year, 'source_sha256' => str_repeat('a', 64), 'source_url' => 'https://www.eci.gov.in/statistical-reports', 'records' => [$record]], []];
            });
        });
    }

    public function test_comparison_keeps_unique_years_and_omits_flagged_margins(): void
    {
        $place = $this->mappedPlace();
        $this->mockEditions([2009 => ['status' => 'needs_review', 'error' => 'Summary totals differ']]);
        $data = app(ConstituencyHistory::class)->forPlace($place);
        $this->assertSame([2009, 2014, 2019, 2024], array_column($data['rows'], 'year'));
        $this->assertNull($data['rows'][0]['winner_party']);
        $this->assertSame('PARTY A', $data['rows'][1]['winner_party']);
        $this->get('/india/pc/pilibhit/history')->assertOk()->assertSee('Winning margins over time')->assertSee('Summary totals differ')->assertSee('id="note-2009"', false)->assertDontSee('2009 · 5 votes')->assertSee('2014 · 5 votes');
        $this->assertDatabaseCount('election_contests', 0);
    }

    public function test_mapping_requires_state_code_and_name_and_current_source(): void
    {
        $place = $this->mappedPlace();
        $this->mockEditions([2009 => ['state_code' => 'S01'], 2014 => ['official_pc_code' => 25], 2019 => ['constituency_name' => 'Different constituency']]);
        $data = app(ConstituencyHistory::class)->forPlace($place);
        $this->assertNull($data['rows'][0]['record']);
        $this->assertNull($data['rows'][1]['record']);
        $this->assertNull($data['rows'][2]['record']);
        $this->assertNotNull($data['rows'][3]['record']);
        $release = DB::table('place_identifiers')->where('version', 'delimitation-order-34')->value('source_release_id');
        DB::table('source_releases')->where('id', $release)->update(['sha256' => str_repeat('b', 64)]);
        $this->assertNull(app(ConstituencyHistory::class)->forPlace($place)['mapping']);
        $this->get('/india/pc/pilibhit/history')->assertOk()->assertSee('Historical links need verification');
    }

    public function test_unmapped_places_do_not_gain_historical_links(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/pc/pilibhit/history')->assertOk()->assertSee('Historical links need verification');
        $this->get('/india/pc/nonexistent/history')->assertNotFound();
    }

    public function test_reverse_links_require_a_verified_record_and_mapping(): void
    {
        $this->mappedPlace();
        $this->mockEditions();
        $service = app(ConstituencyHistory::class);
        $record = ['code' => 451, 'state_code' => 'S24', 'official_pc_code' => 26];
        $related = $service->relatedPlace(ConstituencyHistory::EDITIONS[2009], $record);
        $this->assertSame('pilibhit', $related['slug']);
        $this->assertFalse($related['earlier']);
        $this->assertNull($related['previous']);
        $this->assertSame(2014, $related['next']['year']);
        $latest = $service->relatedPlace(ConstituencyHistory::EDITIONS[2024], $record);
        $this->assertSame(2019, $latest['previous']['year']);
        $this->assertNull($latest['next']);
        $this->mockEditions([2014 => ['constituency_name' => 'Unverified name']]);
        $this->assertSame(2019, $service->relatedPlace(ConstituencyHistory::EDITIONS[2009], $record)['next']['year']);
        $this->assertNull($service->relatedPlace(ConstituencyHistory::EDITIONS[2009], array_replace($record, ['state_code' => 'S01'])));
        $this->assertNull($service->relatedPlace(ConstituencyHistory::EDITIONS[2009], array_replace($record, ['code' => 450])));
        $this->assertNull($service->relatedPlace(str_repeat('a', 24), $record));
        DB::table('place_identifiers')->where('version', 'delimitation-order-34')->delete();
        $this->assertNull($service->relatedPlace(ConstituencyHistory::EDITIONS[2009], $record));
    }

    public function test_earlier_pilibhit_records_stay_outside_modern_comparison(): void
    {
        $place = $this->mappedPlace();
        $comparison = app(ConstituencyHistory::class)->forPlace($place);
        $this->assertSame([1951, 1957, 1962, 1967, 1971, 1977, 1980, 1984, 1989, 1991, 1996, 1998, 1999, 2004], array_column($comparison['earlier']['links'], 'year'));
        $this->assertSame([2009, 2014, 2019, 2024], array_column($comparison['rows'], 'year'));
        $this->get($comparison['earlier']['links'][0]['url'])->assertOk()->assertSee('Pilibhit present-day profile')->assertSee('Its boundaries differ')->assertSee(route('elections.compare', ['slug' => 'pilibhit']), false)->assertSee('Next available election: 1957')->assertDontSee('Previous available election:');
        $this->get($comparison['rows'][0]['url'])->assertOk()->assertSee('Pilibhit present-day profile')->assertDontSee('Its boundaries differ')->assertSee('Previous available election: 2004')->assertSee('Next available election: 2014');
        $this->get('/india/pc/pilibhit/history')->assertOk()->assertSee('1951 candidate results')->assertSee('1957 candidate results')->assertSee('1962 candidate results')->assertSee('combined historical constituency')->assertSee('1967 candidate results')->assertSee('1971 candidate results')->assertSee('printed page 867')->assertSee('1977 candidate results')->assertSee('2004 candidate results')->assertSee('2004 numbering:')->assertSee('61 Powayan (SC)')->assertSee('118-Baheri')->assertSee('printed page 475')->assertSee('printed page 29');
        $this->mockEditions();
        $changed = app(ConstituencyHistory::class)->forPlace($place);
        $this->assertSame([], $changed['earlier']['links']);
    }

    public function test_documented_names_are_limited_to_the_checked_source_editions(): void
    {
        $place = $this->mappedPlace();
        $data = [];
        $this->mock(HistoricalElectionArchive::class, function ($mock) use (&$data): void {
            $mock->shouldReceive('load')->andReturnUsing(function () use (&$data): array {
                return [$data, []];
            });
        });
        $mappings = json_decode(file_get_contents(database_path('fixtures/pc-name-mappings.json')), true);
        foreach ($mappings as $mapping) {
            $data = json_decode(file_get_contents(storage_path('app/private/election-archive/'.$mapping['archive'].'/extraction.json')), true);
            $place->name = $mapping['mapped_name'];
            DB::table('place_identifiers')->where('place_id', $place->id)->where('version', 'delimitation-order-34')->update(['code' => (string) $mapping['official_pc_code']]);
            $comparison = app(ConstituencyHistory::class)->forPlace($place);
            $row = collect($comparison['rows'])->firstWhere('year', $mapping['year']);
            $this->assertSame($mapping['source_name'], $row['record']['constituency_name']);
            $this->assertSame($mapping['note'], $row['mapping_note']);
            $this->assertSame($mapping['year'] === 2009, $row['record']['has_warning']);
            $data['source_sha256'] = str_repeat('b', 64);
            $changed = app(ConstituencyHistory::class)->forPlace($place);
            $this->assertNull(collect($changed['rows'])->firstWhere('year', $mapping['year'])['record']);
        }
    }

    public function test_assembly_history_links_matching_codes_and_names_only(): void
    {
        $this->seed(PilibhitSeeder::class);
        $place = DB::table('places')->where('slug', 'ac-puranpur')->first();
        $this->get('/india/ac/puranpur/history')->assertOk()->assertSee('Historical links need verification');
        DB::table('place_identifiers')->insert(['namespace' => 'electoral:IN:UP:ac', 'code' => '129', 'version' => 'eci-election-2022', 'place_id' => $place->id, 'source_release_id' => DB::table('source_releases')->where('status', 'accepted')->value('id')]);
        $comparison = app(ConstituencyHistory::class)->forAssembly($place);
        $this->assertSame([2012, 2017, 2022], array_column($comparison['rows'], 'year'));
        $this->assertCount(3, array_filter(array_column($comparison['rows'], 'record')));
        $this->get($comparison['rows'][0]['url'])->assertOk()->assertSee('Puranpur present-day profile')->assertSee(route('places.show', ['type' => 'ac', 'slug' => 'puranpur']), false)->assertSee(route('elections.compare-assembly', ['slug' => 'puranpur']), false)->assertSee('Next available election: 2017')->assertDontSee('Previous available election:');
        $this->get($comparison['rows'][1]['url'])->assertOk()->assertSee('Previous available election: 2012')->assertSee('Next available election: 2022');
        $this->get($comparison['rows'][2]['url'])->assertOk()->assertSee('Previous available election: 2017')->assertDontSee('Next available election:');
        $this->get('/india/ac/puranpur/history')->assertOk()->assertSee('Official AC 129')->assertSee('2012, 2017 and 2022')->assertSee('Winning margins over time')->assertDontSee('Vellore')->assertSee('elections/assembly');
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $archive): array {
                $data = json_decode(file_get_contents(storage_path('app/private/election-archive/'.$archive.'/extraction.json')), true);
                foreach ($data['records'] as &$record) {
                    if ($record['code'] === 129) {
                        $record['name'] = 'Different constituency';
                    }
                }

                return [$data, []];
            });
        });
        $this->assertSame([null, null, null], array_column(app(ConstituencyHistory::class)->forAssembly($place)['rows'], 'record'));
        $this->assertNull(app(ConstituencyHistory::class)->relatedPlace(ConstituencyHistory::ASSEMBLY_EDITIONS[2012], ['code' => 129]));
        $this->get('/india/ac/missing/history')->assertNotFound();
    }
}
