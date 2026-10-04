<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CivicExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_dropdown_groups_navigation_without_changing_source_names_or_boundaries(): void
    {
        $first = $this->fixture(1901);
        $second = $this->fixture(1901);
        DB::table('census_editions')->where('id', $second['edition'])->update(['scope' => 'Different historical boundaries']);
        DB::table('census_catalogue_rows')->where('id', $second['STATE'])->update(['name' => 'STATE ALPHA @@']);
        $this->get(route('civic.index', ['year' => 1901]))->assertOk()
            ->assertViewHas('stateOptions', fn ($rows) => $rows->count() === 1 && $rows->first()->display_name === 'State Alpha');
        $this->assertDatabaseHas('census_catalogue_rows', ['id' => $second['STATE'], 'name' => 'STATE ALPHA @@']);
        $this->assertDatabaseHas('census_editions', ['id' => $second['edition'], 'scope' => 'Different historical boundaries']);
        $this->get(route('civic.place', ['record' => $first['STATE']]))->assertOk()
            ->assertViewHas('districtOptions', fn ($rows) => $rows->every(fn ($row) => $row->edition_id === $first['edition']));
    }

    private function fixture(int $year = 2011, string $status = 'published'): array
    {
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Civic fixture', 'url' => 'https://censusindia.gov.in/example.xlsx', 'format' => 'xlsx', 'record_key' => 'code', 'options' => '{}']);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => 'https://censusindia.gov.in/example.xlsx', 'origin' => 'url', 'status' => 'needs_review', 'created_at' => now()]);
        $edition = DB::table('census_editions')->insertGetId(['import_run_id' => $run, 'source_key' => 'india-basic-'.$year.'-total', 'name' => 'Civic fixture '.$year, 'year' => $year, 'status' => $status,
            'sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/example.xlsx', 'landing_url' => 'https://censusindia.gov.in/catalog', 'scope' => 'Source-era test scope', 'fields' => '["TOT_P","No_HH"]', 'row_count' => 6, 'retrieved_at' => now()]);
        $ids = [];
        foreach ([['STATE', 'State Alpha', '000', '00000', '000000', 'Total'], ['DISTRICT', 'District Alpha', '151', '00000', '000000', 'Total'],
            ['SUB-DISTRICT', 'Tehsil Alpha', '151', '00123', '000000', 'Total'], ['VILLAGE', 'Village Alpha', '151', '00123', '123456', 'Rural'],
            ['TOWN', 'Town Alpha', '151', '00123', '800001', 'Urban'], ['SUB-DISTRICT', 'Wrong district child', '152', '00123', '000000', 'Total']] as $i => [$level, $name, $district, $sub, $town, $residence]) {
            $geo = $year === 2001 ? ['TAHSIL' => $sub, 'TOWN_VILL' => $town, 'WARD' => '0000'] : ['Subdistt' => $sub, 'Town/Village' => $town, 'Ward' => '0000'];
            $ids[$level] = DB::table('census_catalogue_rows')->insertGetId(['edition_id' => $edition, 'record_key' => hash('sha256', (string) $i), 'state_code' => '09', 'district_code' => $district,
                'level' => $level, 'residence' => $residence, 'name' => $name, 'geography' => json_encode($geo), 'values' => '{"TOT_P":0,"No_HH":null}', 'flags' => '["Example review note"]', 'source_row' => $i + 1]);
            if ($name === 'Tehsil Alpha') {
                $ids['tehsil'] = $ids[$level];
            }
        }

        return [...$ids, 'edition' => $edition];
    }

    public function test_district_history_loads_published_retrospective_rows_before_local_navigation(): void
    {
        $current = $this->fixture();
        $historical = $this->fixture(1901);
        DB::table('census_editions')->where('id', $historical['edition'])->update(['source_key' => 'census-a02-example-1901']);
        DB::table('census_catalogue_rows')->where('id', $historical['DISTRICT'])->update(['geography' => json_encode(['year' => 1901, 'boundary_basis' => 'Retrospective 2011 boundaries']), 'values' => '{"TOT_P":1200,"TOT_M":700,"TOT_F":500}']);
        $this->get(route('civic.place', ['record' => $current['DISTRICT']]))->assertOk()->assertSeeInOrder(['People through the years', 'Year-wise Census figures', 'Block / subdistrict'])->assertSee('Explore related places')->assertViewHas('censusSeries', fn ($series) => array_column($series['rows'], 'year') === [1901, 2011]);
        DB::table('census_editions')->where('id', $historical['edition'])->update(['status' => 'draft']);
        $this->get(route('civic.place', ['record' => $current['DISTRICT']]))->assertOk()->assertViewHas('censusSeries', fn ($series) => array_column($series['rows'], 'year') === [2011]);
    }

    public function test_header_and_explorer_lead_from_state_to_district_and_tehsil(): void
    {
        $ids = $this->fixture();
        $this->get('/india/census/explore')->assertOk()->assertSee('Choose a state')->assertSee('State Alpha')
            ->assertDontSee('Wrong district child')->assertSee(route('civic.index'), false);
        $this->get('/india/census/places/'.$ids['STATE'])->assertOk()->assertSee('District Alpha')->assertDontSee('Tehsil Alpha');
        $this->get('/india/census/places/'.$ids['DISTRICT'])->assertOk()->assertSee('Tehsil Alpha')->assertDontSee('Wrong district child')
            ->assertSee('State Alpha')->assertSee('Example review note')->assertSee('Map of District Alpha')->assertSee('block-navigation')->assertDontSee('Verified AC/PC associations are not available');
    }

    public function test_villages_and_towns_preserve_group_and_missing_values(): void
    {
        $ids = $this->fixture();
        $this->get('/india/census/places/'.$ids['tehsil'].'?residence=Rural')->assertOk()->assertSee('Village Alpha')->assertSee('village-navigation')->assertDontSee('Town Alpha');
        $this->get('/india/census/places/'.$ids['tehsil'].'?residence=Urban')->assertOk()->assertSee('Town Alpha')->assertDontSee('Village Alpha');
        $this->get('/india/census/places/'.$ids['VILLAGE'].'?group=households')->assertOk()->assertSee('Not reported')->assertSee('Tehsil Alpha')
            ->assertViewHas('records', fn ($rows) => count($rows) === 1 && json_decode($rows[0]->values, true)['TOT_P'] === 0);
    }

    public function test_years_do_not_silently_join_codes_and_unpublished_rows_are_private(): void
    {
        $current = $this->fixture();
        $older = $this->fixture(2001);
        $draft = $this->fixture(1991, 'draft');
        $this->get('/india/census/explore?year=2001')->assertOk()->assertViewHas('year', 2001);
        $this->get('/india/census/places/'.$current['DISTRICT'].'?year=2001')->assertStatus(422);
        $this->get('/india/census/places/'.$current['DISTRICT'].'?edition='.$older['edition'])->assertNotFound();
        $this->get('/india/census/places/'.$draft['DISTRICT'])->assertNotFound();
        $this->get('/india/census/places/'.$older['tehsil'].'?residence=Rural')->assertOk()->assertSee('Village Alpha');
        $this->get('/india/census/places/999999')->assertNotFound();
        $this->get('/india/census/explore?year=1901')->assertOk()->assertSee('No lower-level records');
    }

    public function test_related_constituencies_require_an_accepted_identifier_and_unexpired_source_link(): void
    {
        $ids = $this->fixture();
        $this->seed(PilibhitSeeder::class);
        $district = DB::table('places')->where('slug', 'district-pilibhit')->value('id');
        $this->get('/india/census/places/'.$ids['DISTRICT'])->assertOk()->assertSee('Barkhera')
            ->assertSee('Open available village profiles')->assertViewHas('linked', fn ($items) => $items->pluck('place.id')->unique()->count() === $items->count());
        DB::table('place_relationships')->where(fn ($q) => $q->where('from_place_id', $district)->orWhere('to_place_id', $district))->update(['valid_to' => '2000-01-01']);
        $this->get('/india/census/places/'.$ids['DISTRICT'])->assertOk()->assertDontSee('Barkhera')
            ->assertDontSee('AC, PC & other areas');
        DB::table('place_identifiers')->where('namespace', 'census:district:IN:UP')->delete();
        $this->get('/india/census/places/'.$ids['DISTRICT'])->assertOk()->assertViewHas('matchedPlace', null);
    }

    public function test_state_navigation_opens_its_published_record_or_explains_missing_coverage(): void
    {
        $ids = $this->fixture();
        $this->get('/india/census/explore?state=state-alpha')->assertRedirect(route('civic.place', ['record' => $ids['STATE']]));
        $this->get('/india/census/explore?state=missing-state')->assertOk()->assertSee('No matching state record');
    }
}
