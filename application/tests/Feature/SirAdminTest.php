<?php

namespace Tests\Feature;

use App\Http\Controllers\SirReviewController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SirAdminTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecords(): int
    {
        $id = DB::table('sir_records')->insertGetId(['document_type' => 'electoral_roll', 'edition_key' => str_repeat('a', 64), 'state_code' => '09', 'state_name' => 'Uttar Pradesh', 'ac_code' => '127', 'ac_name' => 'Pilibhit', 'year' => 2026, 'edition' => 'Draft', 'document_date' => '2026-01-06', 'part' => 1, 'station' => 'Station', 'serial' => 1, 'name' => 'Unclear name', 'relative_name' => 'Parent', 'relationship' => 'Father', 'pdf_page' => 3, 'pdf_sha256' => str_repeat('c', 64), 'source_url' => 'https://www.eci.gov.in/test.pdf', 'extraction_status' => 'ocr_uncertain']);
        DB::table('sir_records')->insert(['document_type' => 'electoral_roll', 'edition_key' => str_repeat('b', 64), 'state_code' => '33', 'state_name' => 'Tamil Nadu', 'ac_code' => '1', 'ac_name' => 'Another AC', 'year' => 2025, 'edition' => 'Final', 'document_date' => '2025-01-01', 'part' => 1, 'station' => 'Another station', 'serial' => 1, 'name' => 'Reviewed name', 'relative_name' => 'Parent', 'relationship' => 'Father', 'pdf_page' => 3, 'pdf_sha256' => str_repeat('c', 64), 'source_url' => 'https://www.eci.gov.in/test.pdf', 'extraction_status' => 'reviewed', 'serial_verified' => true]);

        return $id;
    }

    private function admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
    }

    public function test_admin_records_filter_corrections_and_geographic_scope(): void
    {
        $this->setupRecords();
        $this->get('https://localhost/admin/sir')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('https://localhost/admin/sir')->assertForbidden();
        $this->admin();
        $this->get('https://localhost/admin/sir?status=correction')->assertOk()->assertSee('Unclear name')->assertDontSee('Reviewed name')->assertSee('AI correction');
        $this->get('https://localhost/admin/sir?state_code=33&year=2025')->assertOk()->assertSee('Reviewed name')->assertDontSee('Unclear name');
        $this->get('https://localhost/admin/listings/sir')->assertRedirect(route('sir.admin.index'));
    }

    public function test_manual_correction_is_audited_searchable_and_rejects_stale_edits(): void
    {
        $id = $this->setupRecords();
        $this->admin();
        $this->get('https://localhost/admin/sir/records/'.$id)->assertOk()->assertSee('Save correction');
        $hash = SirReviewController::fingerprint(DB::table('sir_records')->find($id));
        $values = ['record_hash' => $hash, 'name' => 'हरि राम', 'relative_name' => 'राम लाल', 'relationship' => 'Father', 'age' => 35, 'gender' => 'पुरुष', 'verified' => 1];
        $this->post('https://localhost/admin/sir/records/'.$id, $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sir_records', ['id' => $id, 'name' => 'हरि राम', 'extraction_status' => 'reviewed', 'name_latin' => 'hari rama']);
        $this->assertDatabaseHas('sir_extraction_reviews', ['record_id' => $id, 'provider' => 'manual', 'status' => 'approved']);
        $this->post('https://localhost/admin/sir/records/'.$id, $values)->assertStatus(409);
        $this->postJson('/api/sir/records/search', ['name' => 'hari'])->assertOk()->assertJsonPath('total', 1);
    }

    public function test_partial_correction_retains_uncertainty_with_a_required_note(): void
    {
        $id = $this->setupRecords();
        $this->admin();
        $values = ['record_hash' => SirReviewController::fingerprint(DB::table('sir_records')->find($id)), 'name' => 'Partial name', 'relative_name' => 'Parent', 'relationship' => 'Father', 'verified' => 1, 'keep_flagged' => 1];
        $this->post('https://localhost/admin/sir/records/'.$id, $values)->assertSessionHasErrors('note');
        $this->post('https://localhost/admin/sir/records/'.$id, $values + ['note' => 'Final letter unclear'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sir_records', ['id' => $id, 'extraction_status' => 'ocr_uncertain', 'field_notes' => 'Final letter unclear']);
        $this->assertDatabaseHas('sir_extraction_reviews', ['status' => 'approved_flagged']);
    }

    public function test_general_source_advice_does_not_flag_every_imported_record(): void
    {
        $id = $this->setupRecords();
        $this->admin();
        DB::table('sir_records')->where('id', $id)->update(['extraction_status' => 'ocr_candidate', 'age' => 35, 'gender' => 'Male', 'serial_verified' => true, 'field_notes' => 'Age, gender, house number and voter ID are OCR text; verify the original PDF.']);
        $this->get('https://localhost/admin/sir?status=correction')->assertOk()->assertDontSee('Unclear name');
        DB::table('sir_records')->where('id', $id)->update(['age' => 5]);
        $this->get('https://localhost/admin/sir?status=correction')->assertOk()->assertSee('Unclear name');
    }
}
