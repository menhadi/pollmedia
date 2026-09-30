<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StaticPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    public function test_default_pages_and_public_navigation(): void
    {
        $response = $this->get('/pages/about')->assertOk()->assertSee('About Pollmedia')->assertSee('https://x.com/xpollmedia')->assertSee('https://www.facebook.com/122102117355488967');
        $html = $response->getContent();
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));
        $this->assertStringNotContainsString('Admin', $header);
        $response->assertSee('Admin panel');
        $this->get('/sources')->assertOk()->assertSee('public-header site-header')->assertSee('About &amp; policies', false)->assertDontSee("@include('public-footer')", false);
        foreach (array_keys(config('static-pages')) as $slug) {
            $this->get('/pages/'.$slug)->assertOk();
        }
    }

    public function test_permissions(): void
    {
        $this->get('/admin/pages')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/pages', [])->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/pages')->assertOk()->assertSee('New page');
        $this->get('/admin/pages?edit=about')->assertOk()->assertSee('Edit page');
    }

    public function test_draft_publish_footer_safe_markdown_and_conflicts(): void
    {
        $this->actingAs($this->admin());
        $data = ['slug' => 'research-notes', 'title' => 'Research notes', 'summary' => 'Methods explained', 'content' => '## Methods'."\n".'<script>alert(1)</script> [bad](javascript:alert(1))', 'published' => 0, 'order' => 60, 'expected' => hash('sha256', 'null')];
        $this->post('/admin/pages', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/pages/research-notes')->assertNotFound();
        $this->get('/pages/about')->assertDontSee('Research notes');
        $data['published'] = 1;
        $data['expected'] = hash('sha256', json_encode(app(StaticPages::class)->all()['research-notes']));
        $this->post('/admin/pages', $data)->assertSessionHasNoErrors();
        $this->get('/pages/research-notes')->assertOk()->assertSee('<h2>Methods</h2>', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('href="javascript:', false);
        $this->get('/pages/about')->assertSee('/pages/research-notes', false);
        $this->post('/admin/pages', $data)->assertStatus(409);
        $this->assertDatabaseHas('site_changes', ['target' => 'settings:static-pages']);
    }

    public function test_social_urls_and_header_colors_are_validated(): void
    {
        $this->actingAs($this->admin());
        $data = config('site.appearance');
        $data['socials']['X'] = 'javascript:alert(1)';
        $this->post('/admin/site/appearance', $data)->assertSessionHasErrors('socials.X');
        $data = config('site.appearance');
        $data['header_background'] = '</style>';
        $this->post('/admin/site/appearance', $data)->assertSessionHasErrors('header_background');
        $data = config('site.appearance');
        $data['socials']['X'] = '';
        $this->post('/admin/site/appearance', $data)->assertSessionHasNoErrors();
        $this->get('/pages/about')->assertDontSee('https://x.com/xpollmedia');
    }
}
