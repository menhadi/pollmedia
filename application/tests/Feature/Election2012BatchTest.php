<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionBatch;
use App\Services\StateElectionPublication;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Election2012BatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_2012_pdf_is_validated_without_inventing_nota_or_overwriting_current_editions(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitAssemblySeeder::class]);
        Bus::fake();
        Storage::fake('local');
        $user = User::factory()->create(['is_admin' => true]);
        $batch = app(ElectionBatch::class);
        $publish = app(StateElectionPublication::class);
        $current = $batch->create($user->id);
        $batch->process($current);
        $publish->publish($current, $user->id);
        $before = DB::table('election_contests')->where('year', 2022)->get()->toJson();
        $id = $batch->create($user->id, 2012);
        $batch->process($id);
        $this->assertDatabaseHas('election_import_batches', ['id' => $id, 'ready_count' => 396, 'invalid_count' => 7]);
        $publish->publish($id, $user->id);
        $this->assertSame(396, DB::table('election_contests')->where('year', 2012)->count());
        $this->assertSame($before, DB::table('election_contests')->where('year', 2022)->get()->toJson());
        $this->get('/india/ac/uttar-pradesh-1-behat?year=2012')->assertOk()->assertSee('MAHAVEER SINGH RANA')->assertSee('70,274')->assertSee('514')->assertSee('No NOTA option in this edition');
        $this->get('/india/ac/puranpur?year=2012')->assertOk();
        $publish->publish($id, $user->id);
        $this->assertSame(396, DB::table('election_contests')->where('year', 2012)->count());
    }
}
