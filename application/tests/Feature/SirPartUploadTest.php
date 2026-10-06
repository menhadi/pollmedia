<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SirPartUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SirPartUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_chunks_preserve_exact_bytes_and_can_only_be_used_by_their_owner(): void
    {
        $user = $this->admin();
        $bytes = '%PDF-'.str_repeat('official', 100000);
        $token = $this->postJson('https://localhost/admin/sir/uploads', ['kind' => 'pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->assertOk()->json('token');
        try {
            $this->actingAs(User::factory()->create(['is_admin' => true]));
            $this->post('https://localhost/admin/sir/uploads/'.$token, ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', substr($bytes, 0, 524288))])->assertNotFound();
            $this->actingAs($user);
            $this->post('https://localhost/admin/sir/uploads/'.$token, ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', substr($bytes, 0, 524288))])->assertOk()->assertJsonPath('offset', 524288);
            $this->post('https://localhost/admin/sir/uploads/'.$token, ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'wrong')])->assertStatus(409);
            $this->post('https://localhost/admin/sir/uploads/'.$token, ['offset' => 524288, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', substr($bytes, 524288))])->assertOk()->assertJsonPath('offset', strlen($bytes));
            $file = app(SirPartUpload::class)->resolve($user->id, $token, 'pdf');
            $this->assertSame($bytes, file_get_contents($file->getRealPath()));
        } finally {
            app(SirPartUpload::class)->discard($user->id, $token);
        }
    }

    public function test_incomplete_or_wrong_checksum_upload_cannot_be_imported(): void
    {
        $user = $this->admin();
        $token = $this->postJson('https://localhost/admin/sir/uploads', ['kind' => 'pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)])->json('token');
        try {
            $this->post('https://localhost/admin/sir/imports', ['pdf_token' => $token, 'sha256' => str_repeat('b', 64)])->assertStatus(422);
            $this->post('https://localhost/admin/sir/uploads/'.$token, ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', '1234567890')])->assertOk();
            $this->post('https://localhost/admin/sir/imports', ['pdf_token' => $token, 'sha256' => str_repeat('b', 64)])->assertStatus(422);
            $this->assertDatabaseCount('sir_records', 0);
        } finally {
            app(SirPartUpload::class)->discard($user->id, $token);
        }
    }

    public function test_upload_initialization_requires_admin_and_bounded_sizes(): void
    {
        $this->postJson('https://localhost/admin/sir/uploads', ['kind' => 'pdf', 'size' => 1, 'sha256' => str_repeat('a', 64)])->assertRedirect();
        $this->admin();
        $this->postJson('https://localhost/admin/sir/uploads', ['kind' => 'records', 'size' => 50000001, 'sha256' => str_repeat('a', 64)])->assertStatus(422);
        $this->postJson('https://localhost/admin/sir/uploads', ['kind' => 'pdf', 'size' => 100000001, 'sha256' => str_repeat('a', 64)])->assertStatus(422);
    }

    public function test_completed_chunk_uploads_publish_through_the_normal_checked_import(): void
    {
        $user = $this->admin();
        $pdf = '%PDF-1.4 test-'.uniqid();
        $hash = hash('sha256', $pdf);
        $data = ['edition_key' => $hash, 'pdf_sha256' => $hash, 'document_type' => 'electoral_roll', 'state_code' => '09', 'state_name' => 'Uttar Pradesh', 'ac_code' => '127', 'ac_name' => 'Pilibhit', 'year' => 2026, 'edition' => 'Draft', 'document_date' => '2026-01-06', 'source_url' => 'https://www.eci.gov.in/test.pdf', 'printed_electors' => 1, 'records' => [['part' => 3, 'station' => 'Station', 'serial' => 1, 'name' => 'Official name', 'relative_name' => 'Parent', 'relationship' => 'Father', 'pdf_page' => 3, 'extraction_status' => 'ocr_uncertain']]];
        $json = json_encode($data);
        $tokens = [];
        try {
            foreach (['records' => $json, 'pdf' => $pdf] as $kind => $bytes) {
                $tokens[$kind] = $this->postJson('https://localhost/admin/sir/uploads', ['kind' => $kind, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->assertOk()->json('token');
                $this->post('https://localhost/admin/sir/uploads/'.$tokens[$kind], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes)])->assertOk();
            }
            $this->post('https://localhost/admin/sir/imports', ['records_token' => $tokens['records'], 'pdf_token' => $tokens['pdf'], 'sha256' => hash('sha256', $json)])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('sir_records', ['name' => 'Official name', 'part' => 3]);
            $this->assertDatabaseHas('sir_import_batches', ['status' => 'imported', 'records_count' => 1]);
        } finally {
            foreach ($tokens as $token) {
                @unlink(storage_path('app/private/sir-upload-parts/'.$token.'.part'));
            }
            @unlink(storage_path('app/private/sir-pdfs/'.$hash.'.pdf'));
        }
    }
}
