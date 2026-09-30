<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HindiLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_hindi_file_is_available_to_laravel_and_the_editor(): void
    {
        $this->assertSame('खोजें', __('Search', [], 'hi'));
        $this->assertSame('States', __('States', [], 'en'));
        $this->assertSame(config('hindi')['States'], __('States', [], 'hi'));
    }

    public function test_hindi_selection_persists_and_english_can_be_restored(): void
    {
        $this->get('/pages/privacy?lang=hi')->assertOk()->assertSee('lang="hi"', false)->assertSee('गोपनीयता नीति')->assertSee('खोजें')->assertHeader('Content-Language', 'hi')->assertSee('मूल अंग्रेज़ी पाठ');
        $this->get('/sources')->assertOk()->assertSee('खोजें')->assertHeader('Content-Language', 'hi');
        $this->get('/pages/privacy?lang=en')->assertOk()->assertSee('Privacy policy')->assertHeader('Content-Language', 'en');
        $this->get('/pages/privacy?lang=invalid')->assertOk()->assertHeader('Content-Language', 'en');
    }

    public function test_admin_can_edit_hindi_and_markup_is_escaped(): void
    {
        $this->get('/admin/translations')->assertRedirect(route('admin.login'));
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/translations', ['source' => 'Search', 'translation' => 'bad'])->assertForbidden();
        $user->is_admin = true;
        $user->save();
        $this->get('/admin/translations')->assertOk()->assertSee('Hindi translations');
        $this->post('/admin/translations', ['source' => 'Search', 'translation' => 'तलाश करें <script>bad()</script>'])->assertSessionHasNoErrors();
        $this->get('/pages/about?lang=hi')->assertOk()->assertSee('तलाश करें')->assertDontSee('<script>bad()</script>', false);
        $this->assertDatabaseHas('site_changes', ['target' => 'settings:translations.hi']);
        $this->get('/admin/translations')->assertOk()->assertSee('Hindi translations');
    }

    public function test_policy_translation_and_missing_translation_fallback(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $source = config('static-pages.about.content');
        $this->post('/admin/translations', ['source' => $source, 'translation' => "## परिचय\nमूल स्रोतों के साथ जानकारी।"])->assertSessionHasNoErrors();
        $this->get('/pages/about?lang=hi')->assertOk()->assertSee('<h2>परिचय</h2>', false)->assertDontSee('मूल अंग्रेज़ी पाठ');
        $this->get('/pages/about?lang=en')->assertOk()->assertSee('Public information, connected to places');
    }
}
