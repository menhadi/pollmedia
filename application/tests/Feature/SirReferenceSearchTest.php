<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SirReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_shows_verified_pages_and_does_not_invent_revision_years(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/search?q=FULAIYA&kind=sir')->assertOk()->assertSee('J. H. SCHOOL FULAIYA')->assertSee('#page=2')->assertSee('Not verified')->assertSee('Open official source PDF')->assertDontSee('Open ECI PDF');
        $suggestions = $this->getJson('/search?q=FULAIYA')->assertOk()->json('suggestions');
        $this->assertTrue(collect($suggestions)->contains('type', 'SIR reference'));
        $this->get('/search?q=SIR&kind=sir&year=2003')->assertOk()->assertSee('No indexed SIR document matches');
    }

    public function test_historical_editions_are_searchable_and_unaccepted_releases_are_excluded(): void
    {
        $this->seed(PilibhitSeeder::class);
        $source = DB::table('data_sources')->where('key', 'sir-pilibhit')->value('id');
        foreach ([2003, 2026] as $year) {
            DB::table('source_releases')->insert(['data_source_id' => $source, 'version_key' => 'sir-'.$year, 'url' => 'https://www.eci.gov.in/roll-'.$year.'.pdf', 'retrieved_at' => now(), 'status' => 'accepted', 'payload' => json_encode(['revision_year' => $year, 'edition' => 'Final', 'parts' => [['name' => 'Historical Station', 'part' => 1, 'pages' => [4, 5], 'source_url' => 'https://www.eci.gov.in/roll-'.$year.'.pdf']]])]);
        }
        $this->get('/search?q=Historical&kind=sir&year=2003')->assertOk()->assertSee('roll-2003.pdf#page=4')->assertDontSee('roll-2026.pdf#page=4')->assertSee('Open ECI PDF');
        DB::table('source_releases')->where('version_key', 'sir-2003')->update(['status' => 'held']);
        $this->get('/search?q=Historical&kind=sir&year=2003')->assertOk()->assertSee('No indexed SIR document matches');
    }

    public function test_sir_pagination_is_independent_of_place_pagination(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/search?q=SIR&kind=sir&page=2')->assertOk()->assertSee('J. H. SCHOOL FULAIYA');
        $this->get('/search?q=SIR&kind=sir&sir_page=2')->assertOk()->assertDontSee('J. H. SCHOOL FULAIYA')->assertSee('No indexed SIR document matches');
        $this->get('/search?q=SIR&sir_page=0')->assertSessionHasErrors('sir_page');
    }
}
