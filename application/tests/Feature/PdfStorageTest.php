<?php

namespace Tests\Feature;

use App\Jobs\TransferPdf;
use App\Models\User;
use App\Services\ArchiveFiles;
use App\Services\PdfStorage;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PdfStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_s3_upload_uses_encrypted_profile_and_does_not_send_acl_headers(): void
    {
        $profile = $this->profile();
        $disk = (new PdfStorage)->remote($profile);
        $handler = new MockHandler([new Result(['ETag' => 'test'])]);
        $disk->getClient()->getHandlerList()->setHandler($handler);
        $this->assertTrue($disk->put('pollmedia/probe.pdf', '%PDF-evidence'));
        $this->assertSame('test-pdfs', $handler->getLastCommand()['Bucket']);
        $this->assertFalse($handler->getLastRequest()->hasHeader('x-amz-acl'));
        DB::table('pdf_storage_profiles')->where('id', $profile)->update(['provider' => 'r2', 'region' => 'auto', 'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com']);
        $r2 = (new PdfStorage)->remote($profile);
        $r2Handler = new MockHandler([new Result(['ETag' => 'test'])]);
        $r2->getClient()->getHandlerList()->setHandler($r2Handler);
        $this->assertTrue($r2->put('pollmedia/probe.pdf', '%PDF-evidence'));
        $this->assertSame(str_repeat('a', 32).'.r2.cloudflarestorage.com', $r2Handler->getLastRequest()->getUri()->getHost());
        $this->assertFalse($r2Handler->getLastRequest()->hasHeader('x-amz-acl'));
    }

    public function test_existing_public_download_url_works_after_pdf_is_moved(): void
    {
        $service = $this->service();
        $entry = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true)['entries'][0];
        $id = substr(hash('sha256', $entry['url']), 0, 24);
        $path = 'election-archive/'.$id.'/source.pdf';
        $body = '%PDF-1.7 preserved source';
        Storage::disk('local')->put($path, $body);
        Storage::disk('local')->put('election-archive/'.$id.'/manifest.json', json_encode(['url' => $entry['url'], 'files' => [
            ['file' => 'source.pdf', 'name' => 'official-report.pdf', 'sha256' => hash('sha256', $body)],
        ]]));
        $service->scan();
        $file = DB::table('pdf_storage_files')->first();
        $service->transfer($this->transfer($file, $this->profile()));
        Storage::disk('local')->assertMissing($path);
        $this->get(route('elections.assembly-source-files', ['archive' => $id, 'file' => 'source.pdf']))
            ->assertOk()->assertDownload('official-report.pdf');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function profile(): int
    {
        return DB::table('pdf_storage_profiles')->insertGetId(['name' => 'Test bucket', 'provider' => 's3', 'bucket' => 'test-pdfs', 'region' => 'us-east-1', 'prefix' => 'pollmedia',
            'credentials' => Crypt::encryptString(json_encode(['key' => 'key', 'secret' => 'secret'])), 'tested_at' => now()]);
    }

    private function service(): PdfStorage
    {
        Storage::fake('local');
        Storage::fake('cloud-test');
        $service = Mockery::mock(PdfStorage::class)->makePartial();
        $service->shouldReceive('remote')->andReturn(Storage::disk('cloud-test'));
        $this->app->instance(PdfStorage::class, $service);
        $this->app->forgetInstance(ArchiveFiles::class);

        return $service;
    }

    private function file(PdfStorage $service): object
    {
        Storage::disk('local')->put('election-archive/example/report.pdf', '%PDF-1.7 source evidence');
        $service->scan();

        return DB::table('pdf_storage_files')->first();
    }

    private function transfer(object $file, ?int $target, bool $remove = true): int
    {
        return DB::table('pdf_storage_transfers')->insertGetId(['file_id' => $file->id, 'target_profile_id' => $target,
            'remove_source' => $remove, 'created_by' => $this->admin()->id]);
    }

    public function test_settings_require_admin_and_encrypt_credentials_without_flashing_them(): void
    {
        $this->get('/admin/pdf-storage')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/pdf-storage/profiles')->assertForbidden();
        $this->actingAs($this->admin());
        $values = ['name' => 'R2 archive', 'provider' => 'r2', 'bucket' => 'pollmedia-pdfs', 'region' => 'auto', 'prefix' => 'pollmedia',
            'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'access_key' => 'private-access-key', 'secret_key' => 'private-secret-key'];
        $this->post('/admin/pdf-storage/profiles', $values)->assertSessionHasNoErrors();
        $profile = DB::table('pdf_storage_profiles')->first();
        $this->assertStringNotContainsString('private-secret-key', $profile->credentials);
        $this->assertStringContainsString('private-secret-key', Crypt::decryptString($profile->credentials));
        $this->get('/admin/pdf-storage')->assertOk()->assertDontSee('private-secret-key')->assertDontSee('private-access-key');
        $this->post('/admin/pdf-storage/profiles', array_replace($values, ['endpoint' => 'https://127.0.0.1']))
            ->assertSessionHasErrors('endpoint')->assertSessionMissing('_old_input.secret_key')->assertSessionMissing('_old_input.access_key');
    }

    public function test_inventory_only_registers_supported_actual_pdfs_and_keeps_hashes(): void
    {
        $service = $this->service();
        Storage::disk('local')->put('election-archive/a/invalid.pdf', '<html>error</html>');
        Storage::disk('local')->put('citizen-issues/private.pdf', '%PDF-private');
        $file = $this->file($service);
        $service->scan();
        $this->assertDatabaseCount('pdf_storage_files', 1);
        $this->assertSame(hash('sha256', '%PDF-1.7 source evidence'), $file->sha256);
    }

    public function test_round_trip_preserves_reader_paths_content_and_cleans_temporary_files(): void
    {
        $service = $this->service();
        $file = $this->file($service);
        $profile = $this->profile();
        $service->transfer($this->transfer($file, $profile));
        Storage::disk('local')->assertMissing($file->path);
        $reader = app(ArchiveFiles::class);
        $this->assertTrue($reader->exists($file->path));
        $this->assertTrue($reader->verify($file->path, $file->sha256));
        $temporary = $reader->path($file->path);
        $this->assertSame($file->sha256, hash_file('sha256', $temporary));
        $reader->cleanup();
        $this->assertFileDoesNotExist($temporary);
        $remote = DB::table('pdf_storage_files')->find($file->id);
        $service->transfer($this->transfer($file, null));
        Storage::disk('local')->assertExists($file->path);
        Storage::disk('cloud-test')->assertMissing($remote->object_key);
        $this->assertNull(DB::table('pdf_storage_files')->value('profile_id'));
        $this->assertSame($file->sha256, hash_file('sha256', Storage::disk('local')->path($file->path)));
    }

    public function test_source_corruption_does_not_remove_or_activate_any_copy(): void
    {
        $service = $this->service();
        $file = $this->file($service);
        Storage::disk('local')->put($file->path, '%PDF-corrupted');
        $id = $this->transfer($file, $this->profile());
        (new TransferPdf($id))->handle($service);
        Storage::disk('local')->assertExists($file->path);
        $this->assertDatabaseHas('pdf_storage_transfers', ['id' => $id, 'status' => 'failed']);
        $this->assertNull(DB::table('pdf_storage_files')->value('profile_id'));
    }

    public function test_destination_corruption_preserves_local_source(): void
    {
        $service = $this->service();
        $file = $this->file($service);
        $broken = Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('put')->andReturn(true);
        $broken->shouldReceive('size')->andReturn((int) $file->bytes);
        $broken->shouldReceive('readStream')->andReturnUsing(function () {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, 'corrupted');
            rewind($stream);

            return $stream;
        });
        $service = Mockery::mock(PdfStorage::class)->makePartial();
        $service->shouldReceive('remote')->andReturn($broken);
        $id = $this->transfer($file, $this->profile());
        (new TransferPdf($id))->handle($service);
        $this->assertDatabaseHas('pdf_storage_transfers', ['id' => $id, 'status' => 'failed']);
        Storage::disk('local')->assertExists($file->path);
        $this->assertNull(DB::table('pdf_storage_files')->value('profile_id'));
    }

    public function test_queued_transfer_is_deduplicated_and_uses_isolated_worker(): void
    {
        Queue::fake();
        $service = $this->service();
        $file = $this->file($service);
        $profile = $this->profile();
        $this->actingAs($this->admin());
        $input = ['files' => [$file->id], 'target' => $profile, 'remove_source' => 1];
        $this->post('/admin/pdf-storage/transfers', $input)->assertSessionHasNoErrors();
        $this->post('/admin/pdf-storage/transfers', $input)->assertSessionHasNoErrors();
        Queue::assertPushed(TransferPdf::class, 1);
        Queue::assertPushed(TransferPdf::class, fn ($job) => $job->connection === 'pdf_storage' && $job->queue === 'pdf-storage');
    }

    public function test_extractor_directory_temporarily_restores_remote_sources(): void
    {
        $service = $this->service();
        $file = $this->file($service);
        $service->transfer($this->transfer($file, $this->profile()));
        app(ArchiveFiles::class)->withDirectory('election-archive/example', function () use ($file): void {
            Storage::disk('local')->assertExists($file->path);
            $this->assertSame($file->sha256, hash_file('sha256', Storage::disk('local')->path($file->path)));
        });
        Storage::disk('local')->assertMissing($file->path);
        $this->assertTrue(app(ArchiveFiles::class)->verify($file->path, $file->sha256));
    }

    public function test_keep_source_and_move_between_profiles_retains_working_reader(): void
    {
        $service = $this->service();
        $file = $this->file($service);
        $service->transfer($this->transfer($file, $this->profile(), false));
        Storage::disk('local')->assertExists($file->path);
        $first = DB::table('pdf_storage_files')->find($file->id)->object_key;
        $second = $this->profile();
        $service->transfer($this->transfer($file, $second));
        Storage::disk('cloud-test')->assertMissing($first);
        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame($second, DB::table('pdf_storage_files')->find($file->id)->profile_id);
        $this->assertTrue(app(ArchiveFiles::class)->verify($file->path, $file->sha256));
    }
}
