<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionPublication;
use App\Services\ElectionResults;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ElectionPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): User
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        Storage::fake('local');
        Storage::disk('local')->put('test-detail', 'official detailed test');
        Storage::disk('local')->put('test-summary', 'official summary test');

        return $user;
    }

    private function draft(object $contest, int $user, bool $change = true): string
    {
        $payload = json_decode(DB::table('source_releases')->where('id', $contest->source_release_id)->value('payload'), true);
        $payload['code'] ??= 26;
        $payload['sha256'] = hash('sha256', 'official detailed test');
        $payload['totals_sha256'] = hash('sha256', 'official summary test');
        if ($change) {
            $payload['candidates'][0]['votes']--;
            $payload['candidates'][0]['general_votes']--;
            $payload['candidates'][1]['votes']++;
            $payload['candidates'][1]['general_votes']++;
            $payload['candidates'][0]['party_at_election'] = 'REVIEWED PARTY';
        }
        $service = $this->partialMock(ElectionPublication::class);
        $service->shouldReceive('extract')->once()->andReturn($payload);

        return $service->stage($contest->id, Storage::disk('local')->path('test-detail'), $contest->type === 'ac' || $contest->year === 2019 ? Storage::disk('local')->path('test-summary') : null, $user);
    }

    public function test_pc_and_ac_publication_updates_results_and_rollback_preserves_history(): void
    {
        $user = $this->prepare();
        $offices = DB::table('office_assignments')->get()->toJson();
        $historical = DB::table('election_contests')->where('year', 2019)->first();
        $targets = app(ElectionPublication::class)->contests()->filter(fn ($c) => ($c->slug === 'pc-pilibhit' && $c->year === 2024) || $c->slug === 'ac-puranpur');
        foreach ($targets as $contest) {
            $old = app(ElectionResults::class)->forPlace($contest->place_id)->firstWhere('year', $contest->year);
            $id = $this->draft($contest, $user->id);
            $url = '/admin/imports/elections/'.$id;
            $this->get($url)->assertOk()->assertSee('REVIEWED PARTY')->assertSee('Winning margin');
            $this->post($url.'/publish', ['reviewed' => 1])->assertRedirect();
            $new = app(ElectionResults::class)->forPlace($contest->place_id)->firstWhere('year', $contest->year);
            $this->assertSame($old->margin - 2, $new->margin);
            $this->assertSame('REVIEWED PARTY', $new->winner->party_at_election);
            $this->get('/india/'.$contest->type.'/'.substr($contest->slug, 3).'?year='.$contest->year)->assertOk()->assertSee('REVIEWED PARTY');
            $this->assertDatabaseHas('election_contests', ['id' => $contest->id, 'active' => false]);
            $this->post($url.'/publish', ['reviewed' => 1])->assertStatus(409);
            $this->post($url.'/restore')->assertRedirect();
            $restored = app(ElectionResults::class)->forPlace($contest->place_id)->firstWhere('year', $contest->year);
            $this->assertSame($old->margin, $restored->margin);
            $this->assertNotSame($old->id, $restored->id);
            $this->assertDatabaseHas('election_publications', ['id' => $id, 'status' => 'rolled_back']);
            $this->post($url.'/restore')->assertStatus(409);
        }
        $this->assertSame($offices, DB::table('office_assignments')->get()->toJson());
        $this->assertDatabaseHas('election_contests', ['id' => $historical->id, 'active' => true]);
    }

    public function test_stale_drafts_integrity_and_required_review_block_publication(): void
    {
        $user = $this->prepare();
        $contest = app(ElectionPublication::class)->contests()->firstWhere('slug', 'ac-puranpur');
        $first = $this->draft($contest, $user->id);
        $second = $this->draft($contest, $user->id);
        $this->post('/admin/imports/elections/'.$first.'/publish')->assertSessionHasErrors('reviewed');
        $this->post('/admin/imports/elections/'.$first.'/publish', ['reviewed' => 1])->assertRedirect();
        $this->post('/admin/imports/elections/'.$second.'/publish', ['reviewed' => 1])->assertStatus(409);
        $current = app(ElectionPublication::class)->contests()->firstWhere('slug', 'ac-puranpur');
        $third = $this->draft($current, $user->id);
        $path = DB::table('election_publications')->where('id', $third)->value('detail_path');
        Storage::disk('local')->put($path, 'tampered');
        $this->post('/admin/imports/elections/'.$third.'/publish', ['reviewed' => 1])->assertStatus(422);
        $this->assertDatabaseHas('election_contests', ['id' => $current->id, 'active' => true]);
    }

    public function test_matching_reports_verify_without_duplicate_editions_and_invalid_totals_fail(): void
    {
        $user = $this->prepare();
        $contest = app(ElectionPublication::class)->contests()->first();
        $id = $this->draft($contest, $user->id, false);
        $count = DB::table('election_contests')->count();
        $this->post('/admin/imports/elections/'.$id.'/publish', ['reviewed' => 1])->assertRedirect();
        $this->assertDatabaseCount('election_contests', $count);
        $this->assertDatabaseHas('election_publications', ['id' => $id, 'status' => 'verified']);
        $payload = json_decode(DB::table('election_publications')->where('id', $id)->value('payload'), true);
        $payload['votes_polled'] = 1;
        $this->expectException(ValidationException::class);
        app(ElectionPublication::class)->validate($payload);
    }

    public function test_election_import_routes_require_administrator(): void
    {
        $this->get('/admin/imports/elections')->assertRedirect(route('admin.login'));
        $this->post('/admin/imports/elections')->assertRedirect(route('admin.login'));
        $this->post('/admin/imports/elections/01M2N30YW2MF1R87XFC3G71CYY/publish')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create());
        $this->get('/admin/imports/elections')->assertForbidden();
        $this->post('/admin/imports/elections/01M2N30YW2MF1R87XFC3G71CYY/restore')->assertForbidden();
    }
}
