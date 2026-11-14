<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CensusPlaceNavigation;
use App\Services\ElectionGeographySummary;
use App\Services\LocalBoardLocations;
use App\Services\MyPlaceDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class LocalBoardTest extends TestCase
{
    use RefreshDatabase;

    private array $areas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        if (! Route::has('local-board.index')) {
            Route::middleware('web')->group(base_path('routes/local-board.php'));
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
        $district = ['key' => 'district:9:173', 'name' => 'Pilibhit', 'kind' => 'district'];
        $gp = ['key' => 'gp:9:82056', 'name' => 'Jatipur Panchayat', 'kind' => 'gp'];
        $this->areas = [
            'district:9:173' => $district + ['parents' => []],
            'gp:9:82056' => $gp + ['parents' => [$district]],
            'village:131596' => ['key' => 'village:131596', 'name' => 'Jatipur', 'kind' => 'village', 'parents' => [$district, $gp]],
            'village:131609' => ['key' => 'village:131609', 'name' => 'Aimi', 'kind' => 'village', 'parents' => [$district, ['key' => 'gp:9:82033', 'name' => 'Aimi Panchayat', 'kind' => 'gp']]],
            'city:9:151:800952' => ['key' => 'city:9:151:800952', 'name' => 'Pilibhit city', 'kind' => 'city', 'parents' => [$district]],
        ];
        foreach ($this->areas as &$area) {
            $area += ['state' => '9', 'district' => '173', 'url' => url('/india/districts/uttar-pradesh/pilibhit')];
        }
        unset($area);
        $locations = Mockery::mock(LocalBoardLocations::class);
        $locations->shouldReceive('resolve')->andReturnUsing(fn (string $key) => $this->areas[$key] ?? abort(404));
        $this->app->instance(LocalBoardLocations::class, $locations);
        $directory = Mockery::mock(MyPlaceDirectory::class);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $this->app->instance(MyPlaceDirectory::class, $directory);
        $census = Mockery::mock(CensusPlaceNavigation::class);
        $census->shouldReceive('stateDirectory')->andReturn([]);
        $this->app->instance(CensusPlaceNavigation::class, $census);
        $elections = Mockery::mock(ElectionGeographySummary::class);
        $elections->shouldReceive('states')->andReturn(collect());
        $this->app->instance(ElectionGeographySummary::class, $elections);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'area' => 'village:131596', 'kind' => 'job', 'category' => 'agriculture',
            'title' => 'Farm workers required', 'description' => 'Two workers needed for daytime field work. Please contact us for working hours.',
            'organisation' => 'Local farm', 'pay_text' => '₹600 per day', 'contact_name' => 'Farm manager',
            'contact_phone' => '+919876543210', 'contact_email' => 'manager@example.test',
            'expires_on' => now('Asia/Kolkata')->addDays(14)->toDateString(), 'contact_consent' => '1',
        ], $changes);
    }

    private function submit(User $user, array $changes = []): string
    {
        $this->actingAs($user)->post(route('local-board.store'), $this->payload($changes))->assertRedirect(route('local-board.mine'));

        return DB::table('local_board_listings')->orderByDesc('id')->value('id');
    }

    private function approve(string $id, User $admin): void
    {
        $revision = DB::table('local_board_listings')->where('id', $id)->value('revision');
        $this->actingAs($admin)->post(route('local-board.decide', $id), ['decision' => 'approved', 'revision' => $revision, 'verification_method' => 'phone', 'review_note' => 'Contact and work terms checked with the poster.'])->assertRedirect();
    }

    public function test_posting_requires_verified_email_consent_and_valid_expiry(): void
    {
        $this->post(route('local-board.store'), $this->payload())->assertRedirect();
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($unverified)->post(route('local-board.store'), $this->payload())->assertForbidden();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('local-board.store'), $this->payload(['contact_consent' => '0']))->assertSessionHasErrors('contact_consent');
        $this->post(route('local-board.store'), $this->payload(['expires_on' => now('Asia/Kolkata')->addDays(61)->toDateString()]))->assertSessionHasErrors('expires_on');
        $this->post(route('local-board.store'), $this->payload(['pay_text' => '']))->assertSessionHasErrors('pay_text');
        $this->assertDatabaseCount('local_board_listings', 0);
    }

    public function test_submissions_are_private_pending_and_client_cannot_approve_or_forge_scopes(): void
    {
        $user = User::factory()->create();
        $id = $this->submit($user, ['status' => 'approved', 'reviewed_at' => now(), 'scopes' => ['district:9:999']]);
        $this->assertDatabaseHas('local_board_listings', ['id' => $id, 'status' => 'pending', 'reviewed_at' => null]);
        $this->assertDatabaseCount('local_board_scopes', 3);
        $this->assertDatabaseMissing('local_board_scopes', ['area_key' => 'district:9:999']);
        Auth::logout();
        $this->get(route('local-board.show', $id))->assertNotFound();
        $this->get(route('local-board.index'))->assertOk()->assertDontSee('Farm workers required');
        $this->assertDatabaseHas('local_board_events', ['listing_id' => $id, 'action' => 'submitted']);
    }

    public function test_only_admin_review_with_evidence_can_publish_and_notes_remain_private(): void
    {
        $poster = User::factory()->create();
        $id = $this->submit($poster);
        $review = ['decision' => 'approved', 'revision' => 1, 'review_note' => 'Private verification note 88721', 'verification_method' => 'phone'];
        $this->post(route('local-board.decide', $id), $review)->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('local-board.decide', $id), array_replace($review, ['verification_method' => '']))->assertSessionHasErrors('verification_method');
        $this->post(route('local-board.decide', $id), $review)->assertRedirect();
        Auth::logout();
        $response = $this->get(route('local-board.show', $id))->assertOk()->assertSee('Reviewed listing')->assertSee('₹600 per day')->assertDontSee('Private verification note 88721');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('local_board_events', ['listing_id' => $id, 'actor_id' => $admin->id, 'verification_method' => 'phone']);
    }

    public function test_jobs_follow_verified_village_gp_district_and_city_scopes_without_leaking_neighbour_listings(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $village = $this->submit($poster, ['title' => 'Jatipur farm vacancy']);
        $this->approve($village, $admin);
        $other = $this->submit($poster, ['area' => 'village:131609', 'title' => 'Aimi shop vacancy']);
        $this->approve($other, $admin);
        $city = $this->submit($poster, ['area' => 'city:9:151:800952', 'title' => 'City repair service', 'kind' => 'service']);
        $this->approve($city, $admin);
        $district = $this->submit($poster, ['area' => 'district:9:173', 'title' => 'District-wide transport service', 'kind' => 'service']);
        $this->approve($district, $admin);
        Auth::logout();
        $this->get(route('local-board.index', ['area' => 'village:131596']))->assertOk()->assertSee('Jatipur farm vacancy')->assertSee('District-wide transport service')->assertDontSee('Aimi shop vacancy')->assertDontSee('City repair service');
        $this->get(route('local-board.index', ['area' => 'gp:9:82056']))->assertOk()->assertSee('Jatipur farm vacancy')->assertDontSee('Aimi shop vacancy');
        $this->get(route('local-board.index', ['area' => 'district:9:173']))->assertOk()->assertSee('Jatipur farm vacancy')->assertSee('Aimi shop vacancy')->assertSee('City repair service');
        $this->get(route('local-board.index', ['area' => 'city:9:151:800952']))->assertOk()->assertSee('City repair service')->assertDontSee('Jatipur farm vacancy');
    }

    public function test_expiry_removes_a_listing_from_all_public_feeds_and_detail_without_deleting_history(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->submit($poster);
        $this->approve($id, $admin);
        DB::table('local_board_listings')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        Auth::logout();
        foreach (['village:131596', 'gp:9:82056', 'district:9:173'] as $key) {
            $this->get(route('local-board.index', ['area' => $key]))->assertOk()->assertDontSee('Farm workers required');
        }
        $this->get(route('local-board.show', $id))->assertNotFound();
        $this->assertDatabaseHas('local_board_listings', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseCount('local_board_events', 2);
    }

    public function test_edit_requires_owner_and_fresh_review_and_prevents_stale_approval(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->submit($poster);
        $this->approve($id, $admin);
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('local-board.edit', $id))->assertForbidden();
        $this->post(route('local-board.update', $id), $this->payload(['revision' => 2]))->assertForbidden();
        $this->actingAs($poster)->post(route('local-board.update', $id), $this->payload(['revision' => 2, 'title' => 'Updated field work vacancy']))->assertRedirect(route('local-board.mine'));
        $this->assertDatabaseHas('local_board_listings', ['id' => $id, 'status' => 'pending', 'reviewed_at' => null, 'verification_method' => null, 'revision' => 3]);
        $this->actingAs($admin)->post(route('local-board.decide', $id), ['decision' => 'approved', 'revision' => 2, 'verification_method' => 'phone', 'review_note' => 'Checked the previous version.'])->assertStatus(409);
        Auth::logout();
        $this->get(route('local-board.show', $id))->assertNotFound();
    }

    public function test_owner_can_close_and_renew_but_cannot_approve_or_read_another_owners_pending_listing(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->submit($poster);
        $this->approve($id, $admin);
        $this->actingAs($poster)->post(route('local-board.close', $id), ['revision' => 2])->assertRedirect(route('local-board.mine'));
        $this->assertDatabaseHas('local_board_listings', ['id' => $id, 'status' => 'closed']);
        $this->get(route('local-board.mine'))->assertOk()->assertSee('Closed');
        $this->post(route('local-board.update', $id), $this->payload(['revision' => 3, 'expires_on' => now('Asia/Kolkata')->addDays(30)->toDateString()]))->assertRedirect();
        $this->assertDatabaseHas('local_board_listings', ['id' => $id, 'status' => 'pending', 'revision' => 4]);
        $this->actingAs(User::factory()->create())->get(route('local-board.show', $id))->assertNotFound();
    }

    public function test_rejection_provides_feedback_to_owner_without_exposing_private_review_note(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->submit($poster);
        $this->actingAs($admin)->post(route('local-board.decide', $id), ['decision' => 'rejected', 'revision' => 1, 'review_note' => 'Private moderation assessment.', 'review_message' => 'Please clarify the working hours and payment period.'])->assertRedirect();
        $this->actingAs($poster)->get(route('local-board.show', $id))->assertOk()->assertSee('Please clarify the working hours')->assertDontSee('Private moderation assessment.');
        Auth::logout();
        $this->get(route('local-board.show', $id))->assertNotFound();
    }

    public function test_invalid_location_is_rejected_and_duplicate_active_post_is_not_created(): void
    {
        $poster = User::factory()->create();
        $this->actingAs($poster)->post(route('local-board.store'), $this->payload(['area' => 'village:99999999']))->assertNotFound();
        $this->submit($poster);
        $this->post(route('local-board.store'), $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('local_board_listings', 1);
    }

    public function test_html_is_escaped_and_email_verification_revocation_hides_the_listing(): void
    {
        $poster = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->submit($poster, ['description' => '<script>alert("test")</script> This must be displayed as text, never executable markup.']);
        $this->approve($id, $admin);
        Auth::logout();
        $this->get(route('local-board.show', $id))->assertOk()->assertDontSee('<script>alert("test")</script>', false)->assertSee('&lt;script&gt;', false);
        $poster->forceFill(['email_verified_at' => null])->save();
        $this->get(route('local-board.index'))->assertOk()->assertDontSee('Farm workers required');
        $this->get(route('local-board.show', $id))->assertNotFound();
    }
}
