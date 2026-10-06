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
        return ['document_type' => 'electoral_roll', 'edition_key' => str_repeat('a', 64), 'state_code' => '09', 'ac_code' => '127', 'ac_name' => 'Test AC', 'year' => 2003, 'edition' => 'Final roll', 'document_date' => '2003-01-01', 'part' => 1, 'station' => 'Test station', 'serial' => 19, 'name' => 'Test Elector', 'relative_name' => 'Test Parent', 'relationship' => 'Father', 'pdf_page' => 4, 'source_url' => 'https://www.eci.gov.in/test.pdf'];
    }

    public function test_search_filters_names_and_editions_and_preserves_provenance(): void
    {
        DB::table('sir_records')->insert($this->row());
        $this->getJson('/api/sir/editions')->assertOk()->assertJsonPath('editions.0.year', 2003);
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64), 'name' => 'Elector', 'relative_name' => 'Parent'])->assertOk()->assertJsonPath('data.0.pdf_page', 4)->assertJsonPath('data.0.relationship', 'Father')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('b', 64), 'name' => 'Elector'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64)])->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/sir/records/search', [])->assertStatus(422);
        $this->postJson('/api/sir/records/search', ['edition_key' => str_repeat('a', 64), 'name' => '%%'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dynamic_filters_browse_an_ac_without_names_or_a_pc(): void
    {
        DB::table('sir_records')->insert($this->row());
        DB::table('sir_records')->insert(array_merge($this->row(), ['edition_key' => str_repeat('b', 64), 'ac_code' => '128', 'ac_name' => 'Other AC', 'part' => 2, 'station' => 'Other station', 'year' => 2026, 'document_date' => '2026-01-01']));
        $this->getJson('/api/sir/editions?period=revision:2003&state_code=09&ac_code=127')->assertOk()->assertJsonCount(1, 'acs')->assertJsonCount(0, 'pcs')->assertJsonPath('stations.0.value', str_repeat('a', 64).':1');
        $this->postJson('/api/sir/records/search', ['state_code' => '09', 'ac_code' => '127'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1)->assertJsonPath('data.0.ac_code', '127');
        $this->postJson('/api/sir/records/search', ['period' => 'revision:2026'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ac_code', '128');
        $this->postJson('/api/sir/records/search', ['state_code' => '09'])->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/sir/records/search', ['ac_code' => '127', 'station_key' => str_repeat('b', 64).':2'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pc_and_station_filters_use_only_verified_imported_mapping(): void
    {
        DB::table('sir_records')->insert(array_merge($this->row(), ['pc_code' => '26', 'pc_name' => 'Test PC', 'pc_source_url' => 'https://www.eci.gov.in/mapping.pdf']));
        DB::table('sir_records')->insert(array_merge($this->row(), ['edition_key' => str_repeat('b', 64), 'ac_code' => '128', 'pc_code' => '26', 'pc_name' => 'Unverified PC']));
        $this->getJson('/api/sir/editions?state_code=09&pc_code=26')->assertOk()->assertJsonCount(1, 'pcs')->assertJsonCount(1, 'acs')->assertJsonPath('acs.0.value', '09|127');
        $this->postJson('/api/sir/records/search', ['state_code' => '09', 'pc_code' => '26'])->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/sir/records/search', ['station_key' => str_repeat('a', 64).':1'])->assertOk()->assertJsonPath('data.0.pdf_page', 4);
    }

    public function test_document_year_is_distinct_from_revision_year_and_browsing_is_paginated(): void
    {
        for ($serial = 1; $serial <= 26; $serial++) {
            DB::table('sir_records')->insert(array_merge($this->row(), ['year' => null, 'serial' => $serial, 'document_date' => '2025-12-17']));
        }
        $this->getJson('/api/sir/editions')->assertOk()->assertJsonPath('periods.0.value', 'document:2025');
        $this->postJson('/api/sir/records/search', ['period' => 'revision:2025'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/sir/records/search', ['period' => 'document:2025'])->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26);
        $this->postJson('/api/sir/records/search', ['period' => 'document:2025', 'page' => 2])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.serial', 26);
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
            $this->assertDatabaseCount('sir_records', 1);
        } finally {
            unlink($file);
        }
    }

    public function test_uncollected_form_records_are_excluded_from_the_voter_roll_browser(): void
    {
        DB::table('sir_records')->insert(array_merge($this->row(), ['document_type' => 'uncollected_forms']));
        $this->getJson('/api/sir/editions')->assertOk()->assertJsonCount(0, 'editions')->assertJsonCount(0, 'periods');
        $this->postJson('/api/sir/records/search', ['state_code' => '09'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_voter_roll_import_requires_the_matching_original_pdf(): void
    {
        $row = $this->row();
        $data = array_intersect_key($row, array_flip(['edition_key', 'state_code', 'ac_code', 'ac_name', 'year', 'edition', 'document_date', 'source_url', 'document_type']));
        $data['pdf_sha256'] = str_repeat('0', 64);
        $data['records'] = [array_intersect_key($row, array_flip(['part', 'station', 'serial', 'name', 'relative_name', 'relationship', 'pdf_page']))];
        $file = tempnam(sys_get_temp_dir(), 'sir-roll-test-');
        file_put_contents($file, json_encode($data));
        try {
            $this->artisan('sir:import-records', ['file' => $file, '--sha256' => hash_file('sha256', $file)])->expectsOutputToContain('matching original PDF')->assertFailed();
            $this->assertDatabaseCount('sir_records', 0);
        } finally {
            unlink($file);
        }
    }

    public function test_pdf_links_are_generated_from_the_preserved_document_hash(): void
    {
        $row = array_merge($this->row(), ['pdf_sha256' => str_repeat('b', 64)]);
        DB::table('sir_records')->insert($row);
        $this->postJson('/api/sir/records/search', ['ac_code' => '127'])->assertOk()->assertJsonPath('data.0.pdf_url', route('sir.document', ['hash' => str_repeat('b', 64)]).'#page=4');
        $this->get('/sir/documents/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/sir/documents/'.str_repeat('b', 64))->assertNotFound();
    }
}
