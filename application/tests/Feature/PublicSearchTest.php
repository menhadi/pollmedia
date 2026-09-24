<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_combines_typed_places_historical_results_and_villages(): void
    {
        $this->seed(PilibhitSeeder::class);
        DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat('a', 24), 'record_code' => 26, 'kind' => 'pc', 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'Pilibhit', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 10, 'extraction_sha256' => str_repeat('b', 64)]);
        $source = DB::table('data_sources')->where('key', 'census-pilibhit-villages-2011')->value('id');
        DB::table('source_releases')->insert(['data_source_id' => $source, 'version_key' => 'test', 'url' => 'https://censusindia.gov.in', 'retrieved_at' => now(), 'status' => 'accepted', 'payload' => json_encode(['villages' => [['code' => '123456', 'name' => 'Pilibhit Test Village']]])]);
        $this->get('/search?q=Pilibhit')->assertOk()->assertSee('Parliament (PC)')->assertSee('Assembly (AC)')->assertSee('Pilibhit Test Village')->assertSee('/india/village/123456-pilibhit-test-village', false)->assertSee('2024');
        $this->get('/search?q=Pilibhit&kind=pc')->assertOk()->assertSee('Election results')->assertDontSee('Pilibhit Test Village')->assertDontSee('/india/ac/pilibhit', false);
        $this->get('/search?q=123456&kind=village')->assertOk()->assertSee('Pilibhit Test Village')->assertDontSee('matching records');
        $this->get('/search?q=NoSuchPlace')->assertOk()->assertSee('No matching pages yet');
        $this->get('/search')->assertOk()->assertSee('Enter at least two characters');
    }
}
