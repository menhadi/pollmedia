<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SirVisionReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['seo-ai.key' => 'test-key', 'seo-ai.model' => 'test-vision-model']);
        $bytes = '%PDF-test-'.uniqid();
        $this->hash = hash('sha256', $bytes);
        $folder = storage_path('app/private/sir-pdfs');
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }
        file_put_contents($folder.'/'.$this->hash.'.pdf', $bytes);
        DB::table('sir_records')->insert(['document_type' => 'electoral_roll', 'edition_key' => str_repeat('a', 64), 'state_code' => '09', 'ac_code' => '127', 'ac_name' => 'Test AC', 'year' => 2026, 'edition' => 'Test draft', 'document_date' => '2026-01-06', 'part' => 1, 'station' => 'Test station', 'serial' => 1, 'name' => 'Unclear OCR', 'relative_name' => 'Parent OCR', 'relationship' => 'Father', 'pdf_page' => 3, 'source_url' => 'https://www.eci.gov.in/test.pdf', 'pdf_sha256' => $this->hash, 'extraction_status' => 'ocr_uncertain']);
        DB::table('site_settings')->insert(['key' => 'sir-roll-meta:'.str_repeat('a', 64), 'value' => json_encode(['printed_electors' => 1, 'uncertain_records' => 1])]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/sir-pdfs/'.$this->hash.'.pdf'));
        parent::tearDown();
    }

    private function administrator(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
    }

    private function suggestion(array $changes = []): array
    {
        return array_merge(['part' => 1, 'serial' => 1, 'pdf_page' => 3, 'name' => 'Verified original name', 'relative_name' => 'Verified parent', 'relationship' => 'Father', 'house_number' => '1', 'age' => 30, 'gender' => 'Male', 'section_number' => '1', 'section_name' => null, 'ward_number' => null, 'elector_id' => null, 'notes' => 'Check original PDF.'], $changes);
    }

    private function fakeResponse(array $changes = []): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'test-response', 'status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->suggestion($changes))]]]]])]);
    }

    public function test_review_queue_is_administrator_only(): void
    {
        $this->get('https://localhost/admin/sir/review')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('https://localhost/admin/sir/review')->assertForbidden();
        $this->post('https://localhost/admin/sir/review/1/vision')->assertForbidden();
        Http::assertNothingSent();
        $this->administrator();
        $this->get('https://localhost/admin/sir/review')->assertOk()->assertSee('Extract card with Vision');
    }

    public function test_vision_suggestion_is_staged_then_explicitly_reviewed_and_audited(): void
    {
        $this->administrator();
        $this->fakeResponse();
        $this->post('https://localhost/admin/sir/review/1/vision', ['model' => 'chosen-vision-model'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sir_records', ['id' => 1, 'name' => 'Unclear OCR']);
        $this->assertDatabaseHas('sir_extraction_reviews', ['record_id' => 1, 'status' => 'pending']);
        Http::assertSent(fn ($request) => $request['store'] === false && $request['input'][0]['content'][0]['type'] === 'input_file' && str_starts_with($request['input'][0]['content'][0]['file_data'], 'data:application/pdf;base64,'));
        Http::assertSent(fn ($request) => $request['model'] === 'chosen-vision-model');
        $this->get('https://localhost/admin/sir/review')->assertOk()->assertSee('Pending Vision suggestion')->assertSee('Verified original name');
        $values = $this->suggestion();
        unset($values['part'], $values['serial'], $values['pdf_page'], $values['notes']);
        $this->post('https://localhost/admin/sir/proposals/1', $values + ['decision' => 'approve'])->assertSessionHasErrors('verified');
        $this->post('https://localhost/admin/sir/proposals/1', $values + ['decision' => 'approve', 'verified' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sir_records', ['id' => 1, 'name' => 'Verified original name', 'age' => 30, 'extraction_status' => 'reviewed']);
        $this->assertDatabaseHas('sir_extraction_reviews', ['status' => 'approved']);
        $audit = DB::table('sir_extraction_reviews')->first();
        $this->assertSame('Unclear OCR', json_decode($audit->before_snapshot, true)['name']);
        $this->assertNotNull($audit->accepted_values);
        $this->postJson('/api/sir/records/search', ['name' => 'Verified'])->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.male', 1);
        $this->post('https://localhost/admin/sir/proposals/1', ['decision' => 'reject'])->assertStatus(409);
    }

    public function test_wrong_card_and_failed_provider_cannot_change_records(): void
    {
        $this->administrator();
        $this->fakeResponse(['serial' => 2]);
        $this->post('https://localhost/admin/sir/review/1/vision')->assertSessionHasErrors('vision');
        $this->assertDatabaseCount('sir_extraction_reviews', 0);
        Http::fake(['api.openai.com/*' => Http::response([], 429)]);
        $this->post('https://localhost/admin/sir/review/1/vision')->assertSessionHasErrors('vision');
        $this->assertDatabaseHas('sir_records', ['name' => 'Unclear OCR']);
    }

    public function test_missing_pdf_does_not_send_a_paid_request(): void
    {
        $this->administrator();
        unlink(storage_path('app/private/sir-pdfs/'.$this->hash.'.pdf'));
        Http::fake();
        $this->post('https://localhost/admin/sir/review/1/vision')->assertSessionHasErrors('vision');
        Http::assertNothingSent();
    }

    public function test_doubtful_age_queue_excludes_fields_already_verified_by_an_admin(): void
    {
        $this->administrator();
        DB::table('sir_records')->where('id', 1)->update(['age' => 12, 'extraction_status' => 'reviewed', 'field_notes' => 'Unverified age OCR']);
        $this->get('https://localhost/admin/sir/review?status=age')->assertOk()->assertSee('1 records match this review queue');
        DB::table('sir_records')->where('id', 1)->update(['field_notes' => null]);
        $this->get('https://localhost/admin/sir/review?status=age')->assertOk()->assertSee('0 records match this review queue');
    }

    public function test_duplicate_vision_requests_do_not_send_another_paid_request(): void
    {
        $this->administrator();
        $lock = Cache::lock('sir-vision-1', 150);
        $this->assertTrue($lock->get());
        try {
            $this->post('https://localhost/admin/sir/review/1/vision')->assertSessionHasErrors('vision');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_stale_suggestions_cannot_overwrite_changed_records_and_rejection_preserves_them(): void
    {
        $this->administrator();
        $this->fakeResponse();
        $this->post('https://localhost/admin/sir/review/1/vision')->assertSessionHasNoErrors();
        DB::table('sir_records')->where('id', 1)->update(['name' => 'Newer correction']);
        $this->post('https://localhost/admin/sir/proposals/1', ['decision' => 'approve', 'verified' => '1', 'name' => 'Old proposal', 'relative_name' => 'Parent', 'relationship' => 'Father'])->assertStatus(409);
        $this->assertDatabaseHas('sir_records', ['name' => 'Newer correction']);
        $this->post('https://localhost/admin/sir/proposals/1', ['decision' => 'reject'])->assertRedirect();
        $this->assertDatabaseHas('sir_extraction_reviews', ['status' => 'rejected']);
    }
}
