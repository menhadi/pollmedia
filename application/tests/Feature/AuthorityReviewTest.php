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
}
