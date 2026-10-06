<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SirImportBatchTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $pdfHash): array
    {
        return ['edition_key' => $pdfHash, 'pdf_sha256' => $pdfHash, 'document_type' => 'electoral_roll', 'state_code' => '09', 'state_name' => 'Uttar Pradesh', 'ac_code' => '127', 'ac_name' => 'Pilibhit', 'year' => 2026, 'edition' => 'Draft', 'document_date' => '2026-01-06', 'source_url' => 'https://www.eci.gov.in/test.pdf', 'printed_electors' => 1, 'held_rows' => [], 'official_statistics' => [['part' => 2, 'male' => 1, 'female' => 0, 'third_gender' => 0, 'total' => 1, 'pdf_page' => 4]], 'records' => [['part' => 2, 'station' => 'Station', 'serial' => 1, 'name' => 'Original name', 'relative_name' => 'Parent', 'relationship' => 'Father', 'pdf_page' => 3, 'extraction_status' => 'ocr_uncertain', 'serial_verified' => true]]];
    }

    public function test_admin_package_import_publishes_all_entries_and_repeat_does_not_overwrite_review(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $pdf = '%PDF-1.4 test-'.uniqid();
        $hash = hash('sha256', $pdf);
        $data = $this->package($hash);
        $json = json_encode($data);
        $upload = fn () => ['records_file' => UploadedFile::fake()->createWithContent('records.json', $json), 'pdf_file' => UploadedFile::fake()->createWithContent('original.pdf', $pdf), 'sha256' => hash('sha256', $json)];
        try {
            $this->get('https://localhost/admin/sir/imports')->assertOk();
            $this->post('https://localhost/admin/sir/imports', $upload())->assertSessionHasNoErrors();
            $this->assertDatabaseHas('sir_import_batches', ['status' => 'imported', 'records_count' => 1]);
            $id = DB::table('sir_records')->value('id');
            DB::table('sir_records')->where('id', $id)->update(['name' => 'Manual correction', 'extraction_status' => 'reviewed']);
            DB::table('sir_extraction_reviews')->insert(['record_id' => $id, 'record_hash' => str_repeat('a', 64), 'pdf_sha256' => $hash, 'before_snapshot' => '{}', 'suggestion' => '{}', 'model' => 'administrator', 'provider' => 'manual', 'status' => 'approved', 'requested_by' => auth()->id()]);
            $this->post('https://localhost/admin/sir/imports', $upload())->assertSessionHasNoErrors();
            $file = tempnam(sys_get_temp_dir(), 'sir-json-');
            $pdfFile = tempnam(sys_get_temp_dir(), 'sir-pdf-');
            file_put_contents($file, $json);
            file_put_contents($pdfFile, $pdf);
            try {
                $this->artisan('sir:import-records', ['file' => $file, '--sha256' => hash('sha256', $json), '--pdf' => $pdfFile])->assertSuccessful();
                $this->assertDatabaseHas('sir_records', ['id' => $id, 'name' => 'Manual correction']);
                $meta = json_decode(DB::table('site_settings')->where('key', 'sir-roll-meta:'.$hash)->value('value'), true);
                $this->assertSame(0, $meta['uncertain_records']);
                $data['pdf_sha256'] = str_repeat('b', 64);
                file_put_contents($file, json_encode($data));
                $this->artisan('sir:import-records', ['file' => $file, '--sha256' => hash_file('sha256', $file), '--pdf' => $pdfFile])->assertFailed();
                $this->assertDatabaseHas('sir_records', ['id' => $id, 'name' => 'Manual correction']);
            } finally {
                unlink($file);
                unlink($pdfFile);
            }
        } finally {
            @unlink(storage_path('app/private/sir-pdfs/'.$hash.'.pdf'));
        }
    }

    public function test_incomplete_package_or_bad_checksum_does_not_publish(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = $this->package(str_repeat('a', 64));
        $data['printed_electors'] = 2;
        $json = json_encode($data);
        $this->post('https://localhost/admin/sir/imports', ['records_file' => UploadedFile::fake()->createWithContent('records.json', $json), 'pdf_file' => UploadedFile::fake()->createWithContent('original.pdf', '%PDF-test'), 'sha256' => hash('sha256',$json)])->assertSessionHasErrors('records_file');
        $this->assertDatabaseCount('sir_records',0);
    }
}
