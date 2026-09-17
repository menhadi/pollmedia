<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SeoPages;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoEditorTest extends TestCase
{
    use RefreshDatabase;

    private function seedPages(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
    }

    private function draft(array $paths, string $type = 'ac', string $year = '2011'): string
    {
        $response = $this->post('/admin/seo/drafts', compact('paths', 'type', 'year'))->assertRedirect();

        return basename($response->headers->get('Location'));
    }

    public function test_review_save_apply_and_restore_changes_only_metadata(): void
    {
        $this->seedPages();
        $path = '/india/ac/puranpur';
        $this->get('/admin/seo')->assertOk()->assertSee('Assembly constituencies')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $id = $this->draft([$path]);
        $this->get('/admin/seo/drafts/'.$id)->assertOk()->assertSee('Saved preview');
        $this->assertDatabaseCount('seo_metadata', 0);
        $this->assertDatabaseHas('seo_batches', ['id' => $id, 'user_id' => auth()->id()]);
        $title = 'Puranpur <script>alert(1)</script> & facts';
        $description = 'Public information with dated official sources.';
        $this->post('/admin/seo/drafts/'.$id.'/save', ['version' => 1, 'items' => [['title' => $title, 'description' => $description]]])->assertRedirect();
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 1])->assertStatus(409);
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 2])->assertRedirect();
        $response = $this->get($path)->assertOk()->assertSee('<title>'.e($title).'</title>', false)
            ->assertSee('content="'.e($title).'"', false)->assertSee($description)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<link rel="canonical" href="'.url($path).'">', false)->assertSee('Puranpur Assembly Constituency');
        $this->assertSame(1, substr_count($response->getContent(), '<title>'));
        $this->assertSame(1, substr_count($response->getContent(), 'name="description"'));
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 2])->assertStatus(409);
        $revision = DB::table('seo_metadata')->where('path', $path)->value('revision_id');
        $this->post('/admin/seo/revisions/'.$revision.'/restore', ['expected_revision' => $revision])->assertRedirect();
        $this->get($path)->assertOk()->assertDontSee(e($title), false);
        $this->assertDatabaseCount('seo_revisions', 2);
        $this->assertSame(2, DB::table('seo_revisions')->where('user_id', auth()->id())->count());
        $this->post('/admin/seo/revisions/'.$revision.'/restore', ['expected_revision' => $revision])->assertStatus(409);
    }

    public function test_stale_bulk_draft_rolls_back_every_page_and_rejects_invalid_selection(): void
    {
        $this->seedPages();
        $paths = ['/india/ac/pilibhit', '/india/ac/puranpur'];
        $stale = $this->draft($paths);
        $fresh = $this->draft([$paths[1]]);
        $this->post('/admin/seo/drafts/'.$fresh.'/apply', ['version' => 1])->assertRedirect();
        $this->post('/admin/seo/drafts/'.$stale.'/apply', ['version' => 1])->assertStatus(409);
        $this->assertDatabaseMissing('seo_metadata', ['path' => $paths[0]]);
        $this->assertDatabaseHas('seo_batches', ['id' => $stale, 'applied_at' => null]);
        $this->post('/admin/seo/drafts', ['type' => 'ac', 'year' => '2011', 'paths' => ['/reports/pilibhit']])->assertStatus(422);
        $this->post('/admin/seo/drafts', ['type' => 'ac', 'year' => '2011', 'paths' => array_fill(0, 51, $paths[0])])->assertSessionHasErrors('paths');
        $this->post('/admin/seo/drafts/'.$stale.'/save', ['version' => 1, 'items' => [['title' => str_repeat('x', 181), 'description' => 'test']]])->assertSessionHasErrors('items.0.title');
    }

    public function test_historical_census_draft_is_separate_and_editor_is_not_public(): void
    {
        $this->seedPages();
        $this->get('/admin/seo?type=village&year=2001')->assertOk()->assertSee('Census 2001')->assertSee('1,216');
        $catalog = app(SeoPages::class)->catalog('village', '2001');
        $path = $catalog->first()['path'];
        $id = $this->draft([$path], 'village', '2001');
        $this->post('/admin/seo/drafts/'.$id.'/apply', ['version' => 1])->assertRedirect();
        $this->get($path)->assertOk()->assertSee('Historical figures, not current estimates.');
        $this->get('/india/village/131548-alam-dandi')->assertOk()->assertDontSee('Historical figures, not current estimates.');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/admin/seo');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->get('/admin/seo')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post('/admin/seo/drafts', [])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://public.example/admin/seo')->assertForbidden();
        $this->app->instance('env', 'production');
        $this->get('http://localhost/admin/seo')->assertForbidden();
    }
}
