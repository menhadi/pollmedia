<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionBatch;
use App\Services\StateElectionPublication;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalElectionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_2017_validated_results_are_added_without_changing_2022_or_publishing_unverified_seats(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        Bus::fake();
        Storage::fake('local');
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $batch = app(ElectionBatch::class);
        $publication = app(StateElectionPublication::class);
        $recent = $batch->create($user->id);
        $batch->process($recent);
        $publication->publish($recent, $user->id);
        $current = DB::table('election_contests')->where('year', 2022)->get()->toJson();
        $offices = DB::table('office_assignments')->get()->toJson();
        $id = $batch->create($user->id, 2017);
        $this->assertNotSame($recent, $id);
        $this->assertSame($id, $batch->create($user->id, 2017));
        $batch->process($id);
        $this->assertDatabaseHas('election_import_batches', ['id' => $id, 'year' => 2017, 'status' => 'needs_attention', 'ready_count' => 382, 'invalid_count' => 21]);
        $this->get('/admin/imports/election-batches/'.$id)->assertOk()->assertSee('Publish 382 validated constituencies');
        $publication->publish($id, $user->id);
        $publication->publish($id, $user->id);
        $this->assertSame(382, DB::table('election_contests')->where('year', 2017)->count());
        $this->assertSame(21, DB::table('election_import_batch_rows')->where('batch_id', $id)->whereNull('published_contest_id')->count());
        $this->assertSame($current, DB::table('election_contests')->where('year', 2022)->get()->toJson());
        $this->assertSame($offices, DB::table('office_assignments')->get()->toJson());
        $this->assertDatabaseCount('places', 406);
        $this->get('/india/ac/puranpur?year=2017')->assertOk()->assertSee('BABU RAM PASWAN')->assertSee('39,242')->assertSee('2017, 2022')->assertSee('year=2017');
        $this->get('/india/ac/puranpur')->assertOk()->assertSee('Full candidate results · 2022');
        $this->get('/india/ac/uttar-pradesh-141-dhaurahra')->assertOk()->assertSee('2017 results withheld')->assertSee('omit this constituency');
        $this->get('/india/ac/uttar-pradesh-141-dhaurahra?year=2017')->assertNotFound();
        $this->get('/india/ac/uttar-pradesh-87-agra-cantt?year=2017')->assertNotFound();
        $this->get('/india/ac/uttar-pradesh-58-dhaulana?year=2017')->assertOk();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/india/ac/puranpur?year=2017')->assertDontSee('/india/ac/uttar-pradesh-141-dhaurahra?year=2017');
        $data = json_decode(DB::table('election_import_batch_rows')->where('batch_id', $id)->where('code', 1)->value('payload'), true);
        $this->assertSame(336576, $data['electors']);
        $this->assertSame(252563, $data['votes_polled']);
        $this->assertSame(252559, array_sum(array_column($data['candidates'], 'votes')));
        $data = json_decode(DB::table('election_import_batch_rows')->where('batch_id', $id)->where('code', 30)->value('payload'), true);
        $this->assertSame(540, $data['unretrieved_evm_votes']);
        $this->assertSame(592, $data['votes_polled'] - array_sum(array_column($data['candidates'], 'votes')));
    }
}
