<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiSeoGenerator;
use App\Services\SeoPages;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiSeoGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->seed(PilibhitSeeder::class);
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
        config(['seo-ai.key' => 'test-private-key', 'seo-ai.model' => 'gpt-5.4-nano']);
        Http::preventStrayRequests();
    }

    private function responsePayload(array $pages): array
    {
        return ['status' => 'completed', 'model' => 'gpt-5.4-nano', 'usage' => ['input_tokens' => 250, 'output_tokens' => 70],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['pages' => $pages])]]]]];
    }

    public function test_generation_sends_only_public_catalog_and_saves_unapplied_review_draft(): void
    {
        $this->prepare();
        Http::fake(['api.openai.com/*' => Http::response($this->responsePayload([['id' => 0, 'title' => 'Pilibhit District | Pollmedia', 'description' => 'Explore Pilibhit District public data, connected places and dated official sources.']]))]);
        $response = $this->post('/admin/seo/drafts', ['type' => 'district', 'year' => '2011', 'generation' => 'ai', 'provider' => 'openai', 'paths' => ['/india/district/pilibhit']])->assertRedirect();
        $draft = DB::table('seo_batches')->first();
        $this->assertNull($draft->applied_at);
        $this->assertDatabaseCount('seo_metadata', 0);
        $this->assertSame(250, json_decode($draft->ai_generation, true)['input_tokens']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('AI-assisted draft')->assertDontSee('test-private-key');
        Http::assertSent(function ($request): bool {
            $input = json_decode($request['input'], true);

            return $request->url() === 'https://api.openai.com/v1/responses' && $request['store'] === false
                && $request['text']['format']['strict'] === true && $request['max_output_tokens'] === 4096
                && array_keys($input[0]) === ['id', 'page', 'title', 'description']
                && ! str_contains($request->body(), auth()->user()->email)
                && ! str_contains($request->body(), 'previous_title');
        });
        Http::assertSentCount(1);
    }

    public function test_missing_key_and_provider_failure_save_nothing_and_do_not_expose_errors(): void
    {
        $this->prepare();
        config(['seo-ai.key' => null]);
        $payload = ['type' => 'district', 'year' => '2011', 'generation' => 'ai', 'provider' => 'openai', 'paths' => ['/india/district/pilibhit']];
        $this->post('/admin/seo/drafts', $payload)->assertSessionHasErrors('generation');
        Http::assertNothingSent();
        config(['seo-ai.key' => 'test-private-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'test-private-key secret diagnostic']], 401)]);
        $this->post('/admin/seo/drafts', $payload)->assertSessionHasErrors(['generation' => 'OpenAI access was rejected. Check the configured API key and model permissions.']);
        $this->assertDatabaseCount('seo_batches', 0);
        Http::assertSentCount(1);
    }

    public function test_incomplete_duplicate_unsupported_and_wrong_year_outputs_are_rejected(): void
    {
        $this->prepare();
        $items = [['path' => '/village', 'label' => 'Alam Dandi - Census 2011', 'title' => 'Alam Dandi Census 2011', 'description' => 'Historical Census 2011 figures.']];
        $responses = [
            ['status' => 'incomplete', 'output' => []],
            ['status' => 'completed', 'output' => [['content' => [['type' => 'refusal']]]]],
            $this->responsePayload([['id' => 1, 'title' => 'Other', 'description' => 'Other']]),
            $this->responsePayload([['id' => 0, 'title' => 'Census 2026', 'description' => 'Population 500']]),
            $this->responsePayload([['id' => 0, 'title' => 'Alam Dandi', 'description' => 'Historical figures.']]),
            $this->responsePayload([['id' => 0, 'title' => '<script>2011</script>', 'description' => 'Census 2011']]),
            $this->responsePayload([['id' => 0, 'title' => '2011', 'description' => '2011'], ['id' => 0, 'title' => '2011', 'description' => '2011']]),
        ];
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }
        Http::fake(['api.openai.com/*' => $sequence]);
        foreach ($responses as $response) {
            try {
                app(AiSeoGenerator::class)->generate($items);
                $this->fail('Invalid output must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertDatabaseCount('seo_batches', 0);
    }

    public function test_size_limit_and_running_request_are_checked_before_api_call(): void
    {
        $this->prepare();
        $paths = app(SeoPages::class)->catalog('village', '2011')->keys()->take(11)->all();
        $this->post('/admin/seo/drafts', ['type' => 'village', 'year' => '2011', 'generation' => 'ai', 'provider' => 'openai', 'paths' => $paths])->assertSessionHasErrors('generation');
        $lock = Cache::lock('seo-ai:'.auth()->id().':running', 90);
        $lock->get();
        $this->post('/admin/seo/drafts', ['type' => 'district', 'year' => '2011', 'generation' => 'ai', 'provider' => 'openai', 'paths' => ['/india/district/pilibhit']])->assertSessionHasErrors('generation');
        $lock->release();
        Http::assertNothingSent();
    }
}
