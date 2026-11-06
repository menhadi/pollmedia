<?php

namespace Tests\Feature;

use App\Http\Controllers\CommunityListingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommunityListingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_setup_and_login_require_verified_email_and_configured_password(): void
    {
        $user = User::factory()->create();
        $password = 'Fixture password 467!';
        $input = ['password' => $password, 'password_confirmation' => $password];
        $this->actingAs($user)->post(route('community.password.set'), $input)->assertForbidden();
        $this->withSession(['community.password_verified' => ['email' => $user->email, 'at' => time() - 601]])->post(route('community.password.set'), $input)->assertForbidden();
        $this->withSession(['community.password_verified' => ['email' => $user->email, 'at' => time()]])->post(route('community.password.set'), $input)->assertRedirect(route('community.account'))->assertSessionMissing('community.password_verified');
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->post(route('community.logout'));
        $this->post(route('community.password.login'), ['email' => $user->email, 'password' => 'Wrong'])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->post(route('community.password.login'), ['email' => $user->email, 'password' => $password])->assertRedirect(route('community.account'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unconfigured_and_admin_passwords_are_rejected_and_spam_help_is_visible(): void
    {
        foreach ([false, true] as $admin) {
            $user = User::factory()->create(['password' => 'Fixture password 467!', 'is_admin' => $admin]);
            if ($admin) {
                $user->forceFill(['contributor_password_set_at' => now()])->save();
            }$this->post(route('community.password.login'), ['email' => $user->email, 'password' => 'Fixture password 467!'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->get(route('community.login'))->assertSee('spam or junk folder')->assertSee('Already set a password?');
    }

    private function listing(): int
    {
        return DB::table('places')->insertGetId(['slug' => 'district-fixture', 'name' => 'Fixture district', 'type' => 'district', 'country_code' => 'IN', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_direct_listing_edit_returns_after_login_and_rejects_external_redirect(): void
    {
        $id = $this->listing();
        $path = route('community.listing', $id, false);
        $this->get(route('community.listing', $id))->assertRedirect(route('community.login', ['next' => $path]));
        $this->get(route('community.login', ['next' => $path]))->assertOk()->assertSessionHas('url.intended', $path);
        $this->get(route('community.login', ['next' => '//evil.example']))->assertSessionHas('url.intended', $path);
        $this->actingAs(User::factory()->create())->get(route('community.listing', $id))->assertOk()->assertSee('Place name')->assertSee('Edit this value');
    }

    public function test_admin_amendment_preserves_original_suggestion_and_account_status(): void
    {
        $id = $this->listing();
        $user = User::factory()->create();
        [, $documents] = app(CommunityListingController::class)->documents($id);
        $input = ['table' => 'places', 'id' => $id, 'expected' => $documents[0]['expected'], 'value' => 'Proposed fixture', 'reason' => 'The official district directory has a newer spelling.'];
        $this->actingAs($user)->post(route('community.listing.propose', $id), $input)->assertRedirect();
        $this->assertDatabaseHas('places', ['id' => $id, 'name' => 'Fixture district']);
        $proposal = DB::table('community_data_proposals')->first();
        $this->get(route('community.account'))->assertOk()->assertSee('Proposed fixture')->assertSee('Awaiting review');
        $this->post(route('community.decide', $proposal->id), ['decision' => 'approved', 'review_note' => 'Verified source spelling.'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('community.review'))->assertOk()->assertSee('Modify the proposed value');
        $this->post(route('community.decide', $proposal->id), ['decision' => 'approved', 'review_note' => 'Verified and amended the spelling.', 'amend' => 1, 'approved_type' => 'text', 'approved_value' => 'Approved fixture'])->assertRedirect();
        $this->assertDatabaseHas('places', ['id' => $id, 'name' => 'Approved fixture']);
        $this->assertDatabaseHas('community_data_proposals', ['id' => $proposal->id, 'proposed_value' => json_encode('Proposed fixture'), 'approved_value' => json_encode('Approved fixture')]);
        $this->actingAs($user)->get(route('community.account'))->assertOk()->assertSee('Approved fixture')->assertSee('Proposed fixture')->assertSee('Verified and amended the spelling.');
        $this->get(route('community.listing', $id))->assertOk()->assertSee('Verified and amended the spelling.');
        $this->actingAs(User::factory()->create())->get(route('community.account'))->assertDontSee('Proposed fixture');
    }

    public function test_stale_listing_and_foreign_target_cannot_be_published(): void
    {
        $id = $this->listing();
        [, $documents] = app(CommunityListingController::class)->documents($id);
        $input = ['table' => 'places', 'id' => $id, 'expected' => $documents[0]['expected'], 'value' => 'Proposed fixture', 'reason' => 'The directory shows a newer spelling.'];
        $this->actingAs(User::factory()->create())->post(route('community.listing.propose', $id), array_replace($input, ['id' => $id + 1]))->assertNotFound();
        $this->post(route('community.listing.propose', $id), $input)->assertRedirect();
        DB::table('places')->where('id', $id)->update(['name' => 'Changed meanwhile']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', DB::table('community_data_proposals')->value('id')), ['decision' => 'approved', 'review_note' => 'Cannot overwrite a changed listing.'])->assertConflict();
    }
}
