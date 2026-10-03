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
        $suggestions = $this->getJson('/search?q=Pilibhit')->assertOk()->assertJsonStructure(['suggestions' => [['label', 'type', 'url']]])->json('suggestions');
        $this->assertCount(1, collect($suggestions)->filter(fn (array $row): bool => $row['label'] === 'Pilibhit' && $row['type'] === 'Lok Sabha'));
        $this->assertCount(1, collect($suggestions)->filter(fn (array $row): bool => $row['label'] === 'Pilibhit' && $row['type'] === 'Assembly (AC)'));
        $this->assertStringNotContainsString('Place profile', json_encode($suggestions));
        $this->getJson('/search?q=P')->assertOk()->assertExactJson(['suggestions' => []]);
        $this->get('/search?q=Pilibhit')->assertOk()->assertSee('Lok Sabha')->assertSee('Assembly (AC)')->assertSee('Pilibhit Test Village')->assertSee('/india/village/123456-pilibhit-test-village', false)->assertDontSee('PC · 2024');
        $this->get('/search?q=Pilibhit&kind=pc')->assertOk()->assertSee('Places')->assertDontSee('Pilibhit Test Village')->assertDontSee('/india/ac/pilibhit', false);
        $this->get('/search?q=123456&kind=village')->assertOk()->assertSee('Pilibhit Test Village')->assertDontSee('matching records');
        $this->get('/search?q=NoSuchPlace')->assertOk()->assertSee('No matching pages yet');
        $this->get('/search')->assertOk()->assertSee('Enter at least two characters')->assertSee('brand-mark')->assertSee('pollmedia-favicon.png');
    }

    public function test_earlier_only_matches_follow_pc_district_and_ac_profiles(): void
    {
        $this->seed(PilibhitSeeder::class);
        foreach ([[1962, 'Pilibhit Old', 'a'], [2024, 'Different Seat', 'b']] as [$year, $name, $id]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => 26, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Uttar Pradesh', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $matches = $this->getJson('/search?q=Pilibhit')->assertOk()->json('suggestions');
        $this->assertSame(['Lok Sabha', 'District', 'Assembly (AC)', 'Archive · Lok Sabha'], array_slice(array_column($matches, 'type'), 0, 4));
        $this->assertSame('Uttar Pradesh · 1962–1962', $matches[3]['period']);
        $this->assertSame('Pilibhit Old', $matches[3]['label']);
        $this->get('/search?q=Pilibhit')->assertSee('Pilibhit Old')->assertDontSee('Place profile');
    }
}
