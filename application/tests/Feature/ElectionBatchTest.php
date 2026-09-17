<?php

namespace Tests\Feature;

use App\Jobs\ProcessElectionBatch;
use App\Models\User;
use App\Services\ElectionBatch;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ElectionBatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        Bus::fake();
        Storage::fake('local');

        return $user;
    }

    public function test_official_state_batch_is_deduplicated_validated_and_kept_out_of_public_results(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $user = $this->admin();
        $before = DB::table('election_contests')->get()->toJson();
        $offices = DB::table('office_assignments')->get()->toJson();
        $service = app(ElectionBatch::class);
        $id = $service->create($user->id);
        $this->assertSame($id, $service->create($user->id));
        $this->assertDatabaseCount('election_import_batches', 1);
        Bus::assertDispatchedTimes(ProcessElectionBatch::class, 1);
        $this->assertDatabaseHas('election_import_batches', ['id' => $id, 'status' => 'queued']);
        $this->get('/admin/imports/election-batches/'.$id)->assertOk()->assertSee('queued');
        $service->process($id);
        $this->assertDatabaseCount('election_import_batch_rows', 403);
        $batch = DB::table('election_import_batches')->find($id);
        $this->assertSame(403, $batch->ready_count + $batch->invalid_count);
        $this->assertGreaterThanOrEqual(398, $batch->ready_count);
        $fixtures = json_decode(file_get_contents(database_path('fixtures/pilibhit-assembly.json')), true);
        foreach ($fixtures as $fixture) {
            $row = DB::table('election_import_batch_rows')->where('batch_id', $id)->where('code', $fixture['code'])->first();
            $this->assertSame('ready', $row->status);
            $payload = json_decode($row->payload, true);
            foreach (['electors', 'votes_polled', 'valid_candidate_votes', 'candidates'] as $field) {
                $this->assertSame($fixture[$field], $payload[$field]);
            }
        }
        $service->process($id);
        $this->assertDatabaseCount('election_import_batch_rows', 403);
        $this->assertSame($before, DB::table('election_contests')->get()->toJson());
        $this->assertSame($offices, DB::table('office_assignments')->get()->toJson());
        $this->get('/admin/imports/election-batches/'.$id.'?code=1')->assertOk()->assertSee('Behat')->assertSee('Publication mapping pending');
        $this->get('/admin/imports/election-batches/'.$id.'?code=129')->assertOk()->assertSee('Create publication review');
        $this->post('/admin/imports/election-batches/'.$id.'/review/1')->assertStatus(422);
        $this->post('/admin/imports/election-batches/'.$id.'/retry')->assertStatus(409);
    }

    public function test_archive_tampering_fails_and_retry_does_not_duplicate_a_batch(): void
    {
        $user = $this->admin();
        $service = app(ElectionBatch::class);
        $id = $service->create($user->id);
        $batch = DB::table('election_import_batches')->find($id);
        Storage::disk('local')->put($batch->detail_path, 'tampered');
        $job = new ProcessElectionBatch($id);
        try {
            $job->handle($service);
            $this->fail('A changed archive must fail validation.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $job->failed($exception);
        }
        $this->assertDatabaseHas('election_import_batches', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('election_import_batch_rows', 0);
        $this->post('/admin/imports/election-batches/'.$id.'/retry')->assertRedirect();
        $this->assertDatabaseHas('election_import_batches', ['id' => $id, 'status' => 'queued']);
        $this->assertDatabaseCount('election_import_batches', 1);
    }

    public function test_admin_and_supported_edition_are_required(): void
    {
        $this->get('/admin/imports/election-batches')->assertRedirect(route('admin.login'));
        $user = $this->admin();
        $this->post('/admin/imports/election-batches', ['state' => 'uttar-pradesh', 'year' => 2007])->assertSessionHasErrors('year');
        $this->post('/admin/imports/election-batches', ['state' => 'bihar', 'year' => 2022])->assertSessionHasErrors('state');
        $this->assertDatabaseCount('election_import_batches', 0);
        $user->is_admin = false;
        $user->save();
        $this->get('/admin/imports/election-batches')->assertForbidden();
    }
}
