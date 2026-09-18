<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalCensusTableTest extends TestCase
{
    private function prepare(): string
    {
        Storage::fake('local');
        $id = '1991-12345-'.str_repeat('a', 16);
        $page = json_encode([
            ['source_row' => 2, 'cells' => ['Example village', 0, null], 'flags' => ['Source components differ.'], 'formula_columns' => [], 'error_columns' => []],
            ['source_row' => 9, 'cells' => ['<script>alert(1)</script>', '=1+1', '#N/A'], 'flags' => [], 'formula_columns' => [2], 'error_columns' => [3]],
        ]);
        $file = ['offset' => 0, 'length' => strlen($page), 'sha256' => hash('sha256', $page)];
        $entry = ['id' => $id, 'year' => 1991, 'name' => 'Historical test table', 'area_as_recorded' => 'Historical area', 'population_group' => 'Rural', 'landing' => 'https://censusindia.gov.in/nada/index.php/catalog/12345'];
        $sheet = ['name' => 'PCA', 'headers' => ['NAME', 'COUNT', 'OTHER'], 'header_source_row' => 1, 'row_count' => 2, 'pages' => [$file], 'districts' => [['id' => str_repeat('b', 16), 'name' => 'District as recorded', 'row_count' => 2, 'pages' => [$file]]]];
        $metadata = json_encode([...$entry, 'retrieved_at' => '2026-09-18', 'source_url' => $entry['landing'].'/download/source.xlsx', 'sheets' => [$sheet]]);
        Storage::disk('local')->put('census-source-tables/'.$id.'/pages.jsonl', $page);
        Storage::disk('local')->put('census-source-tables/'.$id.'/manifest.json', $metadata);
        Storage::disk('local')->put('census-source-tables/index.json', json_encode(['sources' => [[...$entry, 'manifest_sha256' => hash('sha256', $metadata)]], 'pending' => [['name' => 'Pending official table', 'landing' => 'https://censusindia.gov.in/nada/index.php/catalog/12346']], 'scope_note' => 'Historical geography; do not sum overlapping records.']));

        return $id;
    }

    public function test_public_rows_preserve_zero_blanks_flags_source_links_and_escaping(): void
    {
        $id = $this->prepare();
        $this->get('/india/census/source-tables?source='.$id)->assertOk()
            ->assertSee('Example village')->assertSee('Not reported')->assertSee('Source components differ.')
            ->assertSee('Source formula shown as text')->assertSee('original workbook contains an Excel error')
            ->assertSee('Official Excel workbook')->assertSee('Pending official table')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertViewHas('rows', fn ($rows) => $rows[0]['cells'][1] === 0 && $rows[0]['cells'][2] === null && $rows[1]['source_row'] === 9);
    }

    public function test_filters_and_invalid_pages_cannot_leak_other_source_partitions(): void
    {
        $id = $this->prepare();
        $this->get('/india/census/source-tables?source='.$id.'&district='.str_repeat('b', 16))->assertOk()->assertViewHas('rowCount', 2);
        $this->get('/india/census/source-tables?area=Other')->assertOk()->assertSee('No prepared source tables match');
        $this->get('/india/census/source-tables?area=Other&source='.$id)->assertNotFound();
        $this->get('/india/census/source-tables?source='.$id.'&page=2')->assertNotFound();
        $this->get('/india/census/source-tables?source='.$id.'&district='.str_repeat('c', 16))->assertStatus(422);
        $this->getJson('/india/census/source-tables?source=../../secrets')->assertUnprocessable();
    }

    public function test_csv_preserves_row_locators_and_neutralizes_source_formulas(): void
    {
        $id = $this->prepare();
        $response = $this->get('/india/census/source-tables?source='.$id.'&format=csv')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Source components differ.', $csv);
        $this->assertStringContainsString('https://censusindia.gov.in', $csv);
        $this->assertStringContainsString('source_row,NAME,COUNT,OTHER', $csv);
    }

    public function test_changed_prepared_pages_are_not_silently_published(): void
    {
        $id = $this->prepare();
        Storage::disk('local')->put('census-source-tables/'.$id.'/pages.jsonl', 'changed');
        $this->get('/india/census/source-tables?source='.$id)->assertStatus(503);
    }

    public function test_later_pages_read_only_their_indexed_source_rows(): void
    {
        $id = $this->prepare();
        $disk = Storage::disk('local');
        $prefix = 'census-source-tables/'.$id.'/';
        $first = $disk->get($prefix.'pages.jsonl');
        $second = json_encode([['source_row' => 555, 'cells' => ['Later village', 8, null], 'flags' => []]]);
        $disk->put($prefix.'pages.jsonl', $first."\n".$second);
        $metadata = json_decode($disk->get($prefix.'manifest.json'), true);
        $metadata['sheets'][0]['pages'][] = ['offset' => strlen($first) + 1, 'length' => strlen($second), 'sha256' => hash('sha256', $second)];
        $metadata['sheets'][0]['row_count'] = 3;
        $body = json_encode($metadata);
        $disk->put($prefix.'manifest.json', $body);
        $index = json_decode($disk->get('census-source-tables/index.json'), true);
        $index['sources'][0]['manifest_sha256'] = hash('sha256', $body);
        $disk->put('census-source-tables/index.json', json_encode($index));
        $this->get('/india/census/source-tables?source='.$id.'&page=2')->assertOk()
            ->assertSee('Later village')->assertDontSee('Example village')
            ->assertViewHas('rows', fn ($rows) => count($rows) === 1 && $rows[0]['source_row'] === 555);
    }

    public function test_missing_installation_data_is_explicit(): void
    {
        Storage::fake('local');
        $this->get('/india/census/source-tables')->assertOk()->assertSee('have not been prepared');
    }
}
