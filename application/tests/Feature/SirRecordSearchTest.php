<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SirRecordSearchTest extends TestCase
{
    use RefreshDatabase;

    private function row(): array
    {
        return ['edition_key' => str_repeat('a', 64), 'state_code' => '09', 'ac_code' => '127', 'ac_name' => 'Test AC', 'year' => 2003, 'edition' => 'Final roll', 'document_date' => '2003-01-01', 'part' => 1, 'station' => 'Test station', 'serial' => 19, 'name' => 'Test Elector', 'relative_name' => 'Test Parent', 'relationship' => 'Father', 'pdf_page' => 4, 'source_url' => 'https://www.eci.gov.in/test.pdf'];
    }

    public function test_search_filters_names_and_editions_and_preserves_provenance(): void
    {
        DB::table('sir_records')->insert($this->row());
        $this->getJson('/api/sir/editions')->assertOk()->assertJsonPath('editions.0.year', 2003);
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64), 'name' => 'Elector', 'relative_name' => 'Parent'])->assertOk()->assertJsonPath('data.0.pdf_page', 4)->assertJsonPath('data.0.relationship', 'Father')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('b', 64), 'name' => 'Elector'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64)])->assertStatus(422);
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64), 'name' => '%%'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_import_validates_checksum_and_is_repeatable(): void
    {
        $row = $this->row();
        $file = tempnam(sys_get_temp_dir(), 'sir-test-');
        $data = array_intersect_key($row, array_flip(['edition_key', 'state_code', 'ac_code', 'ac_name', 'year', 'edition', 'document_date', 'source_url']));
        $data['records'] = [array_intersect_key($row, array_flip(['part', 'station', 'serial', 'name', 'relative_name', 'relationship', 'pdf_page']))];
        file_put_contents($file, json_encode($data));
        try {
            $this->artisan('sir:import-records', ['file' => $file, '--sha256' => str_repeat('0', 64)])->assertFailed();
            $this->assertDatabaseCount('sir_records', 0);
            for ($i = 0; $i < 2; $i++) {
                $this->artisan('sir:import-records', ['file' => $file, '--sha256' => hash_file('sha256', $file)])->assertSuccessful();
            }
            $this->assertDatabaseCount('sir_records', 1);
            $data['records'][] = $data['records'][0];
            file_put_contents($file, json_encode($data));
            $this->artisan('sir:import-records', ['file' => $file, '--sha256' => hash_file('sha256', $file)])->assertFailed();
            $this->assertDatabaseCount('sir_records',1);
        } finally {
            unlink($file);
        }
    }
}
