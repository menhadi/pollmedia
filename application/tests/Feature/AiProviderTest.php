<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiProviders;
use App\Services\AiSeoGenerator;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        Http::preventStrayRequests();
    }

    public function test_settings_are_admin_only_encrypted_and_never_flashed_or_rendered(): void
    {
        $this->get('/admin/ai-settings')->assertRedirect(route('admin.login'));
        $this->post('/admin/ai-settings/gemini', [])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get('/admin/ai-settings')->assertForbidden();
        $this->admin();
        $this->post('/admin/ai-settings/gemini', ['model' => 'test-gemini', 'api_key' => 'private-gemini-key'])->assertRedirect(route('ai.settings'));
        $stored = DB::table('ai_provider_settings')->where('provider', 'gemini')->first();
        $this->assertNotSame('private-gemini-key', $stored->encrypted_key);
        $this->assertSame('private-gemini-key', Crypt::decryptString($stored->encrypted_key));
        $this->get('/admin/ai-settings')->assertOk()->assertSee('OpenAI')->assertSee('DeepSeek')->assertSee('Gemini')->assertSee('Claude')->assertDontSee('private-gemini-key')->assertSee('test-gemini');
        $this->post('/admin/ai-settings/gemini', ['model' => 'test-gemini-next', 'api_key' => ''])->assertRedirect();
        $this->assertSame('private-gemini-key', app(AiProviders::class)->settings('gemini')['key']);
        $this->post('/admin/ai-settings/gemini', ['model' => '../bad', 'api_key' => 'private-error-key'])->assertSessionHasErrors('model')->assertSessionMissing('_old_input.api_key');
        $this->post('/admin/ai-settings/unknown', ['model' => 'test'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_each_provider_uses_its_own_endpoint_credentials_format_and_usage(): void
    {
        $this->admin();
        $pages = ['pages' => [['id' => 0, 'title' => 'Puranpur Assembly Constituency | Pollmedia', 'description' => 'Public data and dated official sources for Puranpur Assembly Constituency.']]];
        $text = json_encode($pages);
        $fixtures = [
            'deepseek' => ['url' => 'https://api.deepseek.com/chat/completions', 'header' => 'Authorization', 'credential' => 'Bearer deepseek-key',
                'body' => ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]], 'model' => 'deepseek-test', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]]],
            'gemini' => ['url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent', 'header' => 'x-goog-api-key', 'credential' => 'gemini-key',
                'body' => ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]], 'modelVersion' => 'gemini-test', 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50]]],
            'claude' => ['url' => 'https://api.anthropic.com/v1/messages', 'header' => 'x-api-key', 'credential' => 'claude-key',
                'body' => ['stop_reason' => 'end_turn', 'model' => 'claude-test', 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]]],
        ];
        $fakes = [];
        foreach ($fixtures as $provider => $fixture) {
            config(['seo-ai.providers.'.$provider => ['key' => $provider.'-key', 'model' => $provider.'-test']]);
            $fakes[$fixture['url']] = Http::response($fixture['body']);
        }
        Http::fake($fakes);
        $items = [['label' => 'Puranpur Assembly Constituency', 'title' => 'Puranpur', 'description' => 'Public data and official sources.']];
        foreach ($fixtures as $provider => $fixture) {
            $result = app(AiSeoGenerator::class)->generate($items, $provider);
            $this->assertSame($provider, $result['generation']['provider_id']);
            $this->assertSame($provider.'-test', $result['generation']['model']);
            $this->assertSame(100, $result['generation']['input_tokens']);
            $this->assertSame(50, $result['generation']['output_tokens']);
            $this->assertSame($pages['pages'][0]['title'], $result['items'][0]['title']);
            Http::assertSent(fn ($request) => $request->url() === $fixture['url'] && $request->hasHeader($fixture['header'], $fixture['credential']));
        }
        Http::assertSent(fn ($request) => $request->url() === $fixtures['deepseek']['url'] && $request['response_format']['type'] === 'json_object');
        Http::assertSent(fn ($request) => $request->url() === $fixtures['gemini']['url'] && $request['generationConfig']['responseFormat']['text']['mimeType'] === 'application/json' && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn ($request) => $request->url() === $fixtures['claude']['url'] && $request['output_config']['format']['type'] === 'json_schema' && $request->hasHeader('anthropic-version', '2023-06-01'));
        Http::assertSentCount(3);
    }

    public function test_selected_provider_creates_a_draft_and_requires_explicit_choice(): void
    {
        $this->admin();
        $this->seed(PilibhitSeeder::class);
        config(['seo-ai.providers.deepseek' => ['key' => 'deepseek-key', 'model' => 'deepseek-test']]);
        $payload = ['type' => 'district', 'year' => '2011', 'generation' => 'ai', 'paths' => ['/india/district/pilibhit']];
        $this->post('/admin/seo/drafts', $payload)->assertSessionHasErrors('provider');
        Http::assertNothingSent();
        $text = json_encode(['pages' => [['id' => 0, 'title' => 'Pilibhit District | Pollmedia', 'description' => 'Public data and official sources for Pilibhit District.']]]);
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]], 'model' => 'deepseek-test'])]);
        $this->post('/admin/seo/drafts', $payload + ['provider' => 'deepseek'])->assertRedirect();
        $draft = DB::table('seo_batches')->first();
        $this->assertSame('DeepSeek', json_decode($draft->ai_generation, true)['provider']);
        $this->assertNull($draft->applied_at);
        $this->assertDatabaseCount('seo_metadata', 0);
        $this->get('/admin/seo/drafts/'.$draft->id)->assertOk()->assertSee('DeepSeek')->assertSee('deepseek-test');
        Http::assertSentCount(1);
    }

    public function test_unconfigured_or_failed_provider_never_falls_back_to_openai(): void
    {
        $this->admin();
        config(['seo-ai.key' => 'openai-private', 'seo-ai.model' => 'openai-test', 'seo-ai.providers.deepseek' => ['key' => null, 'model' => null]]);
        $items = [['label' => 'Place', 'title' => 'Place', 'description' => 'Public data.']];
        try {
            app(AiSeoGenerator::class)->generate($items, 'deepseek');
            $this->fail('Must reject missing configuration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Configure', $error->getMessage());
        }
        Http::assertNothingSent();
        config(['seo-ai.providers.deepseek' => ['key' => 'deepseek-key', 'model' => 'deepseek-test']]);
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'private diagnostic'], 429)]);
        try {
            app(AiSeoGenerator::class)->generate($items, 'deepseek');
            $this->fail('Must reject quota failure.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('DeepSeek quota', $error->getMessage());
            $this->assertStringNotContainsString('private diagnostic', $error->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
    }
}
