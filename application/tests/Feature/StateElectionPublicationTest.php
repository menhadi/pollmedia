<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionBatch;
use App\Services\SeoPages;
use App\Services\StateElectionPublication;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StateElectionPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_statewide_publication_is_atomic_preserves_existing_editions_and_exposes_sourced_pages(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        Bus::fake();
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $batchService = app(ElectionBatch::class);
        $id = $batchService->create($admin->id);
        $batchService->process($id);
        $offices = DB::table('office_assignments')->get()->toJson();
        $relations = DB::table('place_relationships')->get()->toJson();
        $oldContests = DB::table('election_contests')->get()->toJson();
        $service = app(StateElectionPublication::class);
        $last = DB::table('election_import_batch_rows')->where('batch_id', $id)->where('code', 403)->first();
        $corrupt = json_decode($last->payload, true);
        $corrupt['name'] = 'Incorrect identity';
        DB::table('election_import_batch_rows')->where('id', $last->id)->update(['payload' => json_encode($corrupt)]);
        try {
            $service->publish($id, $admin->id);
            $this->fail('Changed staged data must not be published.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('places', 8);
        $this->assertSame($oldContests, DB::table('election_contests')->get()->toJson());
        $this->assertSame(0, DB::table('election_import_batch_rows')->whereNotNull('place_id')->count());
        DB::table('election_import_batch_rows')->where('id', $last->id)->update(['payload' => $last->payload]);
        $this->post('/admin/imports/election-batches/'.$id.'/publish')->assertSessionHasErrors('reviewed');
        $this->post('/admin/imports/election-batches/'.$id.'/publish', ['reviewed' => '1'])->assertRedirect();
        $this->assertDatabaseCount('places', 406);
        $this->assertSame(403, DB::table('election_import_batch_rows')->whereNotNull('published_contest_id')->count());
        $this->assertSame(403, DB::table('election_contests')->where('year', 2022)->where('active', true)->count());
        $this->assertSame($offices, DB::table('office_assignments')->get()->toJson());
        $this->assertSame($relations, DB::table('place_relationships')->get()->toJson());
        $this->assertSame($oldContests, DB::table('election_contests')->where('id', '<=', 7)->get()->toJson());
        $service->publish($id, $admin->id);
        $this->assertDatabaseCount('places', 406);
        $this->assertDatabaseCount('election_contests', 405);
        $this->get('/india/ac/uttar-pradesh-1-behat')->assertOk()->assertSee('Umar Ali Khan')->assertSee('37,880')->assertSee('Official ECI report')->assertSee('Verified parliamentary constituency and district links have not been imported')->assertDontSee('Available coverage in the Pilibhit PC report');
        $this->get('/india/ac/puranpur')->assertOk()->assertSee('BABURAM');
        $this->get('/india/state/uttar-pradesh?type=ac')->assertOk()->assertSee('403 available pages')->assertSee('Choose an available place');
        $this->get('/india/state/uttar-pradesh?place=ac-uttar-pradesh-1-behat')->assertOk()->assertSee('1 available pages')->assertSee('/india/ac/uttar-pradesh-1-behat');
        $this->get('/admin/imports/election-batches/'.$id.'?code=1')->assertOk()->assertSee('Open published election page');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/india/ac/uttar-pradesh-1-behat');
        $this->assertCount(403, app(SeoPages::class)->catalog('ac', '2022'));
    }

    public function test_publication_requires_an_administrator(): void
    {
        $id = (string) Str::ulid();
        $this->post('/admin/imports/election-batches/'.$id.'/publish', ['reviewed' => '1'])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/admin/imports/election-batches/'.$id.'/publish', ['reviewed' => '1'])->assertForbidden();
    }
}
