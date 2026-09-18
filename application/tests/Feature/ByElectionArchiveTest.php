<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ByElectionArchiveTest extends TestCase
{
    private function source(): array
    {
        Storage::fake('local');
        $id = str_repeat('a', 24);
        $folder = 'election-by-elections/'.$id.'/';
        $raw = 'preserved source';
        $file = hash('sha256', $raw).'.xls';
        $source = hash('sha256', $raw).'-tables.json';
        $url = 'https://old.eci.gov.in/ByeElection/2014/example.xls';
        Storage::disk('local')->put('election-by-elections/catalogue.json', json_encode(['entries' => [
            ['id' => $id, 'year' => 2014, 'label' => '2014 (PC)', 'url' => $url],
            ['id' => str_repeat('b', 24), 'year' => 2010, 'label' => 'Jan 2010', 'url' => null],
        ]]));
        $rows = array_map(fn ($i) => ['row' => $i, 'cells' => ['Area '.$i, 0, null]], range(1, 101));
        $body = json_encode(['source_url' => $url, 'tables' => [['name' => 'Results', 'rows' => $rows]]]);
        Storage::disk('local')->put($folder.$source, $body);
        Storage::disk('local')->put($folder.$file, $raw);
        Storage::disk('local')->put($folder.'manifest.json', json_encode(['status' => 'collected', 'files' => [
            ['file' => $file, 'name' => 'Official workbook', 'sha256' => hash('sha256', $raw), 'source_url' => $url],
        ], 'extractions' => [['file' => $source, 'sha256' => hash('sha256', $body), 'tables' => 1]]]));

        return compact('id', 'folder', 'file', 'source');
    }

    public function test_public_tables_keep_source_notes_zeroes_and_pagination(): void
    {
        $this->source();
        $this->get('/india/elections/by-elections')->assertOk()->assertSee('Area 100')->assertDontSee('Area 101')
            ->assertSee('†')->assertSee('>0<', false)->assertSee('Original')->assertSee('Official link');
        $this->get('/india/elections/by-elections?page=2')->assertOk()->assertSee('Area 101');
        $this->get('/india/elections/by-elections?page=3')->assertNotFound();
        $this->get('/india/elections/by-elections?year=2010')->assertOk()->assertSee('without a usable download link');
    }

    public function test_missing_or_corrupt_sources_are_not_served(): void
    {
        $item = $this->source();
        $url = '/india/elections/by-elections?edition='.$item['id'].'&file='.$item['file'];
        $this->get($url)->assertDownload($item['file']);
        Storage::disk('local')->put($item['folder'].$item['file'], 'changed');
        $this->get($url)->assertStatus(409);
        Storage::disk('local')->put($item['folder'].$item['source'], '{}');
        $this->get('/india/elections/by-elections')->assertStatus(503);
        $this->get('/india/elections/by-elections?file=other.xls')->assertNotFound();
    }

    public function test_missing_collection_displays_pending_state(): void
    {
        Storage::fake('local');
        $this->get('/india/elections/by-elections')->assertOk()->assertSee('not yet installed');
    }
}
