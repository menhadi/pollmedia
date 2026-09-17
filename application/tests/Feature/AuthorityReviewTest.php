<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorityReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authority_reviews_require_admin_access(): void
    {
        Http::preventStrayRequests();
        $this->get('/admin/authorities')->assertRedirect(route('admin.login'));
        $this->post('/admin/authorities/pilibhit-officers/check')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get('/admin/authorities')->assertForbidden();
        $this->post('/admin/authorities/pilibhit-officers/check')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_checks_preserve_officeholders_and_show_prior_evidence_after_failure(): void
    {
        $this->seed(PilibhitSeeder::class);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $assignments = DB::table('office_assignments')->get()->toJson();
        $url = config('source-monitor.sources.pilibhit-officers');
        Http::preventStrayRequests();
        Http::fake([$url => Http::sequence()
            ->push('<table><tr><td>Original officer</td></tr></table>')
            ->push('<table><tr><td>Replacement &lt;script&gt;alert(1)&lt;/script&gt;</td></tr></table>')
            ->push('', 503)]);
        $this->post('/admin/authorities/unknown/check')->assertNotFound();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $this->post('/admin/authorities/pilibhit-officers/check')->assertRedirect(route('authorities.index'));
        }
        $this->get('/admin/authorities')->assertOk()->assertSee('Original officer')
            ->assertSee('Replacement &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Latest check failed')->assertSee('Directory changes need review')
            ->assertSee('Published officeholders')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame($assignments, DB::table('office_assignments')->get()->toJson());
        $this->assertDatabaseCount('source_checks', 3);
        $this->travelBack();
    }

    public function test_reviewed_replacement_preserves_history_and_rejects_stale_or_unmatched_evidence(): void
    {
        $this->seed(PilibhitSeeder::class);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $source = DB::table('data_sources')->where('key', 'pilibhit-officers')->first();
        $old = DB::table('office_assignments as a')->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->where('r.data_source_id', $source->id)->whereNull('a.superseded_at')->select('a.*')->first();
        $content = json_encode([[['Verified new officer']]]);
        $check = DB::table('source_checks')->insertGetId(['data_source_id' => $source->id, 'status' => 'changed',
            'content' => $content, 'sha256' => hash('sha256', $content), 'checked_at' => now()]);
        $input = ['check_id' => $check, 'office_id' => $old->office_id, 'assignment_id' => $old->id,
            'name' => 'Unlisted name', 'note' => 'Office and jurisdiction verified from the official table.', 'confirmed' => 1];
        $this->post('/admin/authorities/replace', $input)->assertStatus(422);
        $input['name'] = 'Verified new officer';
        $this->post('/admin/authorities/replace', $input)->assertRedirect(route('authorities.index'));
        $this->assertNotNull(DB::table('office_assignments')->where('id', $old->id)->value('superseded_at'));
        $active = DB::table('office_assignments')->where('office_id', $old->office_id)->whereNull('superseded_at')->first();
        $this->assertNull($active->effective_from);
        $this->assertDatabaseHas('authority_reviews', ['office_assignment_id' => $active->id, 'reviewed_by' => $user->id]);
        $this->get('/india/district/pilibhit')->assertOk()->assertSee('Verified new officer');
        $this->post('/admin/authorities/replace', $input)->assertStatus(409);
        $this->assertDatabaseCount('authority_reviews', 1);
        DB::table('source_checks')->insert(['data_source_id' => $source->id, 'status' => 'failed', 'checked_at' => now()]);
        $input['assignment_id'] = $active->id;
        $this->post('/admin/authorities/replace', $input)->assertStatus(409);
        $this->assertDatabaseCount('authority_reviews', 1);
    }
}
