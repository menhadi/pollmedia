<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CitizenIssueAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function prepareIssue(): array
    {
        $this->seed(PilibhitSeeder::class);
        $jurisdiction = DB::table('office_jurisdictions')->first();
        $id = (string) Str::ulid();
        DB::table('citizen_issues')->insert(['id' => $id, 'category' => 'water', 'title' => 'Public water supply report', 'description' => 'A citizen report awaiting an office response.', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('citizen_issue_places')->insert(['issue_id' => $id, 'place_id' => $jurisdiction->place_id]);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);

        return [$id, $jurisdiction];
    }

    public function test_links_follow_offices_and_replace_superseded_people(): void
    {
        [$id, $j] = $this->prepareIssue();
        $this->post('/admin/issues/'.$id.'/authority', ['office_id' => $j->office_id, 'revision' => 0, 'active' => 1, 'reason' => 'This office covers the reported geographical area.'])->assertRedirect();
        $this->get('/admin/issues/'.$id)->assertOk()->assertSee('Record a response');
        $this->get('/issues/'.$id)->assertOk()->assertSee('Official jurisdiction source');
        DB::table('office_assignments')->where('office_id', $j->office_id)->update(['superseded_at' => now()]);
        $person = DB::table('people')->insertGetId(['key' => 'replacement-for-issue-test', 'display_name' => 'Replacement Officeholder']);
        DB::table('office_assignments')->insert(['office_id' => $j->office_id, 'person_id' => $person, 'source_release_id' => $j->source_release_id, 'status' => 'confirmed', 'verified_at' => now()]);
        $this->get('/issues/'.$id)->assertOk()->assertSee('Replacement Officeholder');
        DB::table('office_jurisdictions')->where('id', $j->id)->update(['valid_to' => today()->toDateString()]);
        $this->get('/issues/'.$id)->assertOk()->assertSee('office link needs review')->assertDontSee('Replacement Officeholder');
        $this->post('/admin/issues/'.$id.'/authority', ['office_id' => $j->office_id, 'revision' => 1, 'active' => 1, 'reason' => 'Attempt to use an expired office jurisdiction.'])->assertStatus(422);
    }

    public function test_official_response_summary_requires_link_and_can_be_hidden(): void
    {
        [$id, $j] = $this->prepareIssue();
        $this->post('/admin/issues/'.$id.'/authority', ['office_id' => $j->office_id, 'revision' => 0, 'active' => 1, 'reason' => 'Office relevance reviewed for this local report.'])->assertRedirect();
        $authority = DB::table('citizen_issue_authorities')->value('id');
        $input = ['authority_id' => $authority, 'revision' => 1, 'summary' => 'The source records a scheduled inspection of the water supply.', 'source_url' => 'https://example.com/response', 'responded_on' => today()->toDateString()];
        $this->post('/admin/issues/'.$id.'/responses', $input)->assertSessionHasErrors('source_url');
        $input['source_url'] = 'https://pilibhit.nic.in/response';
        $this->post('/admin/issues/'.$id.'/responses', $input)->assertRedirect();
        $this->get('/issues/'.$id)->assertOk()->assertSee('scheduled inspection')->assertSee('administrator-written summaries');
        $this->assertDatabaseHas('citizen_issues', ['id' => $id, 'status' => 'open']);
        $response = DB::table('citizen_issue_responses')->value('id');
        $this->post('/admin/issues/'.$id.'/responses/'.$response.'/hide')->assertRedirect();
        $this->get('/issues/'.$id)->assertOk()->assertDontSee('scheduled inspection');
        $this->get('/admin/issues/'.$id)->assertOk()->assertSee('scheduled inspection');
    }

    public function test_unrelated_offices_and_non_administrators_cannot_route_issues(): void
    {
        [$id, $j] = $this->prepareIssue();
        DB::table('office_jurisdictions')->where('id', $j->id)->update(['valid_from' => today()->addDay()->toDateString()]);
        $input = ['office_id' => $j->office_id, 'revision' => 0, 'active' => 1, 'reason' => 'This mapping must not be accepted before its start date.'];
        $this->post('/admin/issues/'.$id.'/authority', $input)->assertStatus(422);
        $this->actingAs(User::factory()->create());
        $this->post('/admin/issues/'.$id.'/authority', $input)->assertForbidden();
        $this->post('/admin/issues/'.$id.'/responses', [])->assertForbidden();

        $this->assertDatabaseCount('citizen_issue_authorities', 0);
    }
}
