<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ManagedTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    public function test_permissions(): void
    {
        $this->get('/admin/site')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/site/api', [])->assertForbidden();
    }

    public function test_admin_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/site')->assertOk()->assertSee('Public API controls');
    }

    public function test_theme_and_css_validation(): void
    {
        $this->actingAs($this->admin());
        $data = config('site.appearance');
        $data['name'] = 'Civic Records';
        $this->post('/admin/site/appearance', $data)->assertSessionHasNoErrors();
        $this->get('/report-problem')->assertOk()->assertSee('Civic Records');
        $data['css'] = '</style><script>alert(1)</script>';
        $this->post('/admin/site/appearance', $data)->assertSessionHasErrors('css');
    }

    public function test_private_report_editor_link(): void
    {
        $this->post('/report-problem', ['category' => 'data', 'path' => '/india/census/places/12', 'details' => 'The population total needs checking.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('data_feedback', ['status' => 'open']);
        $this->actingAs($this->admin())->get('/admin/feedback')->assertOk()->assertSee('Edit this Census record');
    }

    public function test_problem_report_receives_the_affected_result_path_automatically(): void
    {
        $path = '/india/constituency?kind=pc&state=Uttar%20Pradesh&name=Pilibhit&edition='.str_repeat('a', 24);
        $this->get(route('feedback.create', ['path' => $path, 'category' => 'data']))
            ->assertOk()
            ->assertSee('Page and result:')
            ->assertSee('type="hidden" name="path"', false)
            ->assertDontSee('Page path');

        $this->post('/report-problem', ['category' => 'data', 'path' => $path, 'details' => 'The 2009 winning margin needs source review.'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('data_feedback', ['path' => $path, 'category' => 'data']);

        $this->post('/report-problem', ['category' => 'source', 'path' => $path])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('data_feedback', ['path' => $path, 'category' => 'source', 'details' => 'Problem reported for this page and result.']);
    }

    public function test_report_rejects_external_urls(): void
    {
        $this->post('/report-problem', ['category' => 'data', 'path' => '//example.com', 'details' => 'This value needs checking.'])->assertSessionHasErrors('path');
    }

    public function test_api_disable(): void
    {
        $this->actingAs($this->admin())->post('/admin/site/api', ['path' => 'api/census', 'enabled' => 0, 'limit' => 5])->assertSessionHasNoErrors();
        $this->getJson('/api/census')->assertStatus(503);
    }

    public function test_schedule_validation(): void
    {
        $this->actingAs($this->admin())->post('/admin/site/tasks/queue', ['enabled' => 0, 'schedule' => 'rm -rf /'])->assertSessionHasErrors('schedule');
        $this->post('/admin/site/tasks/queue', ['enabled' => 0, 'schedule' => '*/15 * * * *'])->assertSessionHasNoErrors();
        $this->assertFalse(app(ManagedTasks::class)->all()['queue']['enabled']);
    }

    public function test_audited_correction_and_stale_write(): void
    {
        $this->actingAs($this->admin());
        $id = DB::table('places')->insertGetId(['slug' => 'test-place', 'name' => 'Before', 'type' => 'district', 'country_code' => 'IN']);
        $row = DB::table('places')->find($id);
        $data = ['table' => 'places', 'id' => $id, 'expected' => hash('sha256', json_encode($row)), 'reason' => 'Checked against the official document.', 'fields' => ['name' => 'After']];
        $this->post('/admin/corrections', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('places', ['id' => $id, 'name' => 'After']);
        $this->assertDatabaseHas('site_changes', ['target' => 'places:'.$id]);
        $this->post('/admin/corrections', $data)->assertStatus(409);
        $this->get('/admin/corrections?table=places&id='.$id)->assertOk()->assertSee('Before');
    }

    public function test_monitor(): void
    {
        $this->artisan('site:monitor')->assertSuccessful();
        $this->assertDatabaseHas('task_health', ['key' => 'health', 'status' => 'healthy']);
    }

    public function test_seo_applies_to_public_page_and_escapes_markup(): void
    {
        $this->actingAs($this->admin())->post('/admin/site/seo', ['path' => '/report-problem', 'title' => 'Civic <records>', 'description' => 'Source checks & corrections', 'revision' => 0])->assertSessionHasNoErrors();
        $this->get('/report-problem')->assertOk()->assertSee('<title>Civic &lt;records&gt;</title>', false)->assertSee('Source checks &amp; corrections', false);
    }

    public function test_source_correction_preserves_evidence_and_flags(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $id = '1991-12345-'.str_repeat('a', 16);
        $raw = json_encode([['source_row' => 2, 'cells' => ['Village', 5], 'flags' => ['Pending review']]]);
        $source = ['id' => $id, 'year' => 1991, 'name' => 'Test source', 'area_as_recorded' => 'Test area', 'population_group' => 'Rural', 'landing' => 'https://censusindia.gov.in/'];
        $manifest = json_encode($source + ['retrieved_at' => '2026-09-29', 'source_url' => 'https://censusindia.gov.in/test.xlsx', 'sheets' => [['name' => 'PCA', 'headers' => ['Name', 'Count'], 'header_source_row' => 1, 'row_count' => 1, 'districts' => [], 'pages' => [['offset' => 0, 'length' => strlen($raw), 'sha256' => hash('sha256', $raw)]]]]]);
        $disk->put('census-source-tables/index.json', json_encode(['sources' => [$source + ['manifest_sha256' => hash('sha256', $manifest)]], 'pending' => [], 'scope_note' => 'Test historical geography']));
        $disk->put('census-source-tables/'.$id.'/manifest.json', $manifest);
        $disk->put('census-source-tables/'.$id.'/pages.jsonl', $raw);
        $input = ['source' => $id, 'sheet' => 0, 'page' => 1, 'row' => '2', 'cells' => '["Village",6]', 'reason' => 'Corrected against the official count.', 'revision' => 0];
        $this->actingAs($this->admin())->post('/admin/source-correction', $input)->assertSessionHasNoErrors();
        $this->get('/india/census/source-tables?source='.$id)->assertOk()->assertSee('Administrative correction')->assertSee('Pending review')->assertViewHas('rows', fn ($rows) => $rows[0]['cells'][1] === 6 && $rows[0]['original_cells'][1] === 5);
        $this->assertSame($raw, $disk->get('census-source-tables/'.$id.'/pages.jsonl'));
        $this->post('/admin/source-correction', $input)->assertStatus(409);
        $this->get('/admin/source-correction?'.http_build_query(array_diff_key($input, ['cells' => true, 'reason' => true, 'revision' => true])))->assertOk()->assertSee('Original extracted cells');
    }
}
