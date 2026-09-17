<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_status_keeps_accepted_edition_and_filters_publishers(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 20)->startOfDay());
        $this->seed(PilibhitSeeder::class);
        $source = DB::table('data_sources')->where('key', 'lgd-pilibhit-electoral')->first();
        $release = DB::table('source_releases')->where('data_source_id', $source->id)->first();
        DB::table('source_releases')->insert(['data_source_id' => $source->id, 'version_key' => 'pending-test', 'url' => $source->url, 'retrieved_at' => '2026-09-19', 'status' => 'pending', 'payload' => json_encode(['private_test_value' => 'never-render-payload'])]);
        $this->get('/sources')->assertOk()->assertSee('Updates are currently manual.')
            ->assertSee('4 days since import')->assertSee('other edition(s) not accepted')->assertDontSee('never-render-payload')
            ->assertViewHas('sources', fn ($sources) => $sources->firstWhere('key', 'lgd-pilibhit-electoral')->release->id === $release->id);
        $this->get('/sources?publisher='.urlencode($source->publisher))->assertOk()
            ->assertViewHas('sources', fn ($sources) => $sources->every(fn ($item) => $item->publisher === $source->publisher));
        $this->get('/sources?publisher=unknown')->assertSessionHasErrors('publisher');
        DB::table('source_releases')->where('data_source_id', $source->id)->update(['status' => 'pending']);
        $this->get('/sources')->assertOk()->assertSee('No accepted edition is available for display.');
        $this->travelBack();
    }

    public function test_empty_source_registry_is_explicit(): void
    {
        $this->get('/sources')->assertOk()->assertSee('0 sources')->assertSee('No sources have been imported.');
    }
}
