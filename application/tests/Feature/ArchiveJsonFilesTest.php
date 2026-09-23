<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArchiveJsonFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserved_archive_json_can_be_read_from_the_database(): void
    {
        Storage::fake('local');
        $path = 'election-by-elections/structured/example.json';
        $body = '{"source_url":"https://eci.gov.in/example","rows":[["candidate",123]]}';
        $sha = hash('sha256', $body);
        DB::table('archive_json_files')->insert([
            'path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-by-elections',
            'sha256' => $sha, 'bytes' => strlen($body), 'body' => $body,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $files = app(ArchiveFiles::class);
        $this->assertTrue($files->exists($path));
        $this->assertSame($body, $files->get($path));
        $this->assertTrue($files->verify($path, $sha));
        $this->assertSame($body, file_get_contents($files->path($path)));
        $files->cleanup();
    }

    public function test_database_body_must_still_match_its_preserved_checksum(): void
    {
        Storage::fake('local');
        $path = 'election-archive/'.str_repeat('a', 24).'/extraction.json';
        DB::table('archive_json_files')->insert([
            'path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive',
            'sha256' => str_repeat('b', 64), 'bytes' => 2, 'body' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        app(ArchiveFiles::class)->get($path);
    }
}
