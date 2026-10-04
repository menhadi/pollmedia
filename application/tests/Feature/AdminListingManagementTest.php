<?php

namespace Tests\Feature;

use App\Http\Controllers\PlaceController;
use App\Models\User;
use App\Services\DataCorrections;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminListingManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_menus_and_attachment_download_are_admin_only(): void
    {
        Storage::fake('local');
        $this->get('/admin/listings/census')->assertRedirect(route('admin.login'));
        $this->admin();
        $id = DB::table('places')->insertGetId(['slug' => 'sample', 'name' => 'Sample', 'type' => 'district', 'country_code' => 'IN']);
        $this->get('/admin/listings/elections')->assertOk()->assertSee('Election listings');
        $this->get('/admin/listings/census?table=places')->assertNotFound();
        $this->post('/admin/listings/attachments', ['table' => 'places', 'id' => $id, 'file' => UploadedFile::fake()->create('source.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $key = DB::table('site_settings')->where('key', 'like', 'listing-file:%')->value('key');
        $this->get(route('listings.file', ['key' => $key]))->assertOk()->assertDownload('source.pdf');
        $this->get('/admin/corrections?table=places&id='.$id)->assertOk()->assertSee('source.pdf')->assertSee('All stored fields');
        $this->actingAs(User::factory()->create())->get(route('listings.file', ['key' => $key]))->assertForbidden();
    }

    public function test_sir_corrections_and_removal_reach_the_public_api_without_changing_source(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->admin();
        $record = app(DataCorrections::class)->sirRows()->first();
        $original = app(PlaceController::class)->payload('sir-pilibhit');
        $input = ['table' => 'sir_parts', 'id' => $record->id, 'expected' => hash('sha256', json_encode($record)), 'reason' => 'Reviewed against source document.', 'fields' => ['name' => 'Corrected polling part', 'listed_records' => 42]];
        $this->get('/admin/listings/sir?id='.$record->id)->assertOk();
        $this->post('/admin/corrections', $input)->assertSessionHasNoErrors();
        $this->getJson('/api/sir?state=09&ac=127')->assertOk()->assertJsonPath('rows.0.name', 'Corrected polling part')->assertJsonPath('rows.0.listed_records', 42);
        $this->post('/admin/listings/remove', $input)->assertStatus(409);
        $updated = app(DataCorrections::class)->record('sir_parts', $record->id);
        $input['expected'] = hash('sha256', json_encode($updated));
        $this->post('/admin/listings/remove', $input)->assertSessionHasNoErrors();
        $this->getJson('/api/sir?state=09&ac=127')->assertJsonPath('total_rows', 19);
        $this->assertSame($original, app(PlaceController::class)->payload('sir-pilibhit'));
    }

    public function test_bulk_missing_seo_preserves_existing_fields_and_updates_keywords(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->admin();
        DB::table('seo_metadata')->insert(['path' => '/india/sir', 'title' => 'Reviewed SIR title', 'description' => 'Reviewed description', 'keywords' => null, 'revision_id' => 0, 'updated_at' => now()]);
        $response = $this->post('/admin/seo/drafts', ['type' => 'sir', 'year' => '2011', 'bulk' => 'all', 'mode' => 'missing'])->assertRedirect()->assertSessionHasNoErrors();
        $id = basename($response->headers->get('Location'));
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 1])->assertRedirect();
        $this->assertDatabaseHas('seo_metadata', ['path' => '/india/sir', 'title' => 'Reviewed SIR title', 'description' => 'Reviewed description']);
        $this->get('/india/sir')->assertOk()->assertSee('Reviewed SIR title')->assertSee('name="keywords"', false);
        $this->post('/admin/seo/drafts', ['type' => 'sir', 'year' => '2011', 'bulk' => 'all', 'mode' => 'missing'])->assertSessionHasErrors('paths');
    }

    public function test_bulk_template_can_generate_more_than_fifty_pages(): void
    {
        $this->admin();
        for ($i = 1; $i <= 110; $i++) {
            DB::table('places')->insert(['slug' => 'ac-test-'.$i, 'name' => 'Test '.$i, 'type' => 'ac', 'country_code' => 'IN']);
        }
        $response = $this->post('/admin/seo/drafts', ['type' => 'ac', 'year' => '2011', 'bulk' => '100'])->assertRedirect()->assertSessionHasNoErrors();
        $id = basename($response->headers->get('Location'));
        $this->assertCount(100, json_decode(DB::table('seo_batches')->where('id', $id)->value('items'), true));
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 1])->assertRedirect();
        $this->assertDatabaseCount('seo_metadata', 100);
        $response = $this->post('/admin/seo/drafts', ['type' => 'ac', 'year' => '2011', 'bulk' => 'all'])->assertRedirect();
        $id = basename($response->headers->get('Location'));
        $this->get('/admin/seo/drafts/'.$id.'?page=2')->assertOk()->assertSee('110 pages');
        $this->post('/admin/seo/drafts/'.$id.'/save', ['version' => 1, 'items' => [100 => ['title' => 'Reviewed title', 'description' => 'Reviewed source description', 'keywords' => 'Reviewed keyword']]])->assertSessionHasNoErrors();
        $items = json_decode(DB::table('seo_batches')->where('id', $id)->value('items'), true);
        $this->assertCount(110, $items);
        $this->assertSame('Reviewed title', $items[100]['title']);
        $this->assertNotSame('Reviewed title', $items[0]['title']);

    }
}
