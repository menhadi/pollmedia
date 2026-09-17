<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CitizenIssueTest extends TestCase
{
    use RefreshDatabase;

    private function submit(): string
    {
        $place = DB::table('places')->insertGetId(['slug' => 'issue-test', 'name' => 'Issue test district', 'type' => 'district', 'country_code' => 'IN']);
        $this->post('/issues', ['place_id' => $place, 'category' => 'water', 'title' => 'Water supply interrupted',
            'description' => '<script>alert(1)</script> No water supply for three days in the public square.',
            'evidence_url' => 'https://example.com/evidence', 'consent' => 1])->assertRedirect('/issues')->assertSessionHas('status');

        return DB::table('citizen_issues')->value('id');
    }

    private function administrator(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
    }

    public function test_private_submission_review_publication_resolution_and_removal(): void
    {
        $id = $this->submit();
        $this->get('/issues/'.$id)->assertNotFound();
        $this->get('/issues')->assertOk()->assertDontSee('Water supply interrupted');
        $this->get('/admin/issues')->assertRedirect('/admin/login');
        $this->post('/admin/issues/'.$id, [])->assertRedirect('/admin/login');
        $this->administrator();
        $this->get('/admin/issues')->assertOk()->assertSee('Water supply interrupted');
        $this->get('/admin/issues/'.$id)->assertOk()->assertDontSee('<script>', false);
        $this->post('/admin/issues/'.$id, ['revision' => 0, 'status' => 'open', 'note' => 'Reviewed for public visibility.'])->assertRedirect();
        $this->get('/issues/'.$id)->assertOk()->assertSee('Reviewed for public visibility.')->assertDontSee('<script>', false);
        $this->post('/admin/issues/'.$id, ['revision' => 0, 'status' => 'resolved', 'note' => 'Stale review must not be accepted.'])->assertStatus(409);
        $this->post('/admin/issues/'.$id, ['revision' => 1, 'status' => 'resolved', 'note' => 'Repair completion reported by administrator.'])->assertRedirect();
        $this->get('/issues/'.$id)->assertOk()->assertSee('not citizen-confirmed');
        $this->post('/admin/issues/'.$id, ['revision' => 2, 'status' => 'rejected', 'note' => 'Private reason for taking this report down.'])->assertRedirect();
        $this->get('/issues/'.$id)->assertNotFound();
        $this->get('/issues')->assertDontSee('Water supply interrupted');
        $this->post('/admin/issues/'.$id, ['revision' => 3, 'status' => 'open', 'note' => 'Restored after another public review.'])->assertRedirect();
        $this->get('/issues/'.$id)->assertOk()->assertDontSee('Private reason');
        $this->assertDatabaseCount('citizen_issue_events', 4);
    }

    public function test_non_admin_cannot_review_and_invalid_submission_is_not_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/issues')->assertForbidden();
        $this->post('/issues', ['category' => 'invalid', 'evidence_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors(['place_id', 'category', 'title', 'description', 'evidence_url', 'consent']);
        $this->assertDatabaseCount('citizen_issues', 0);
    }

    public function test_geography_filters_and_shared_place_links_do_not_duplicate_reports(): void
    {
        $id = $this->submit();
        $other = DB::table('places')->insertGetId(['slug' => 'issue-test-two', 'name' => 'Second district', 'type' => 'district', 'country_code' => 'IN']);
        DB::table('citizen_issue_places')->insert(['issue_id' => $id, 'place_id' => $other]);
        DB::table('citizen_issues')->where('id', $id)->update(['status' => 'open']);
        $this->get('/issues')->assertOk()->assertViewHas('issues', fn ($rows) => $rows->total() === 1);
        $this->get('/issues?place='.$other)->assertOk()->assertViewHas('issues', fn ($rows) => $rows->total() === 1);
        $this->get('/issues?country=GB')->assertOk()->assertViewHas('issues', fn ($rows) => $rows->total() === 0);
        $this->administrator();
        $this->post('/admin/issues/'.$id, ['revision' => 0, 'status' => 'open', 'note' => 'Cannot repeat the current status.'])->assertStatus(422);

        $this->assertDatabaseCount('citizen_issue_events', 0);
    }
}
