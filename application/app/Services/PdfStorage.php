<?php

namespace App\Services;

use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PdfStorage
{
    public const ROOTS = ['election-archive', 'census-archive', 'census-source-tables', 'election-batches', 'election-imports', 'official-imports', 'election-by-elections', 'polling-station-sources'];

    public function allowed(string $path): bool
    {
        return ! str_contains($path, '..') && ! str_contains($path, '\\') && ! str_contains($path, "\0")
            && in_array(explode('/', $path)[0], self::ROOTS, true) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function remote(int $profileId): FilesystemAdapter
    {
        $profile = DB::table('pdf_storage_profiles')->find($profileId);
        abort_unless($profile, 404);
        $credentials = json_decode(Crypt::decryptString($profile->credentials), true, 512, JSON_THROW_ON_ERROR);

        $disk = Storage::build(['driver' => 's3', 'key' => $credentials['key'], 'secret' => $credentials['secret'],
            'region' => $profile->region, 'bucket' => $profile->bucket, 'endpoint' => $profile->endpoint,
            'use_path_style_endpoint' => true, 'throw' => true, 'visibility' => 'private',
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 10, 'timeout' => 600]]);
        $disk->getClient()->getHandlerList()->appendInit(Middleware::mapCommand(function (CommandInterface $command): CommandInterface {
            unset($command['ACL']);

            return $command;
        }), 'pollmedia-private-bucket');

        return $disk;
    }

    public function hashStream(mixed $stream): string
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('PDF stream is unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public function scan(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach (self::ROOTS as $root) {
            foreach ($disk->allFiles($root) as $path) {
                if (! $this->allowed($path) || is_link($disk->path($path))) {
                    continue;
                }
                $stream = $disk->readStream($path);
                $signature = fread($stream, 5);
                fclose($stream);
                if ($signature !== '%PDF-') {
                    continue;
                }
                DB::table('pdf_storage_files')->insertOrIgnore(['path' => $path, 'path_hash' => hash('sha256', $path),
                    'sha256' => $this->hashStream($disk->readStream($path)), 'bytes' => $disk->size($path), 'created_at' => now(), 'updated_at' => now()]);
                $count++;
            }
        }

        return $count;
    }

    public function test(int $profile): void
    {
        $disk = $this->remote($profile);
        $key = DB::table('pdf_storage_profiles')->where('id', $profile)->value('prefix').'/connection-tests/'.Str::uuid();
        $body = 'Pollmedia storage test '.Str::random(40);
        try {
            $disk->put($key, $body);
            if (! hash_equals(hash('sha256', $body), $this->hashStream($disk->readStream($key))) || ! $disk->delete($key)) {
                throw new RuntimeException('Storage verification failed.');
            }
            DB::table('pdf_storage_profiles')->where('id', $profile)->update(['tested_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('pdf_storage_profiles')->where('id', $profile)->update(['tested_at' => null]);
            throw new RuntimeException('Connection test failed. Check bucket, endpoint and read/write/delete permissions.');
        }
    }

    public function transfer(int $transferId): void
    {
        $transfer = DB::table('pdf_storage_transfers')->find($transferId);
        if (! $transfer || $transfer->status === 'completed') {
            return;
        }
        Cache::lock('pdf-file-'.$transfer->file_id, 1800)->block(5, function () use ($transfer): void {
            if (DB::table('pdf_storage_transfers')->where('id', $transfer->id)->value('status') === 'completed') {
                return;
            }
            $file = DB::table('pdf_storage_files')->find($transfer->file_id);
            $local = Storage::disk('local');
            abort_unless($file && $this->allowed($file->path), 422);
            DB::table('pdf_storage_transfers')->where('id', $transfer->id)->update(['status' => 'running', 'message' => null, 'updated_at' => now()]);
            $sourceDisk = $file->profile_id ? $this->remote($file->profile_id) : $local;
            $sourceKey = $file->profile_id ? $file->object_key : $file->path;
            $targetDisk = $transfer->target_profile_id ? $this->remote($transfer->target_profile_id) : $local;
            $targetKey = $transfer->target_profile_id
                ? DB::table('pdf_storage_profiles')->where('id', $transfer->target_profile_id)->value('prefix').'/pdf/'.Str::uuid().'.pdf'
                : 'pdf-working-transfers/'.Str::uuid().'.pdf';
            DB::table('pdf_storage_transfers')->where('id', $transfer->id)->update(['source_profile_id' => $file->profile_id,
                'source_key' => $sourceKey, 'target_key' => $targetKey]);
            if (! hash_equals($file->sha256, $this->hashStream($sourceDisk->readStream($sourceKey)))) {
                throw new RuntimeException('Source verification failed. Original retained.');
            }
            if (! $transfer->target_profile_id && $local->exists($file->path)) {
                $targetKey = $file->path;
                if (! hash_equals($file->sha256, $this->hashStream($local->readStream($targetKey)))) {
                    throw new RuntimeException('A different local file exists. No file was replaced.');
                }
            } else {
                $stream = $sourceDisk->readStream($sourceKey);
                try {
                    if (! $targetDisk->put($targetKey, $stream)) {
                        throw new RuntimeException('Destination write failed.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            if ($targetDisk->size($targetKey) !== (int) $file->bytes || ! hash_equals($file->sha256, $this->hashStream($targetDisk->readStream($targetKey)))) {
                throw new RuntimeException('Destination verification failed. Original retained.');
            }
            if (! $transfer->target_profile_id && $targetKey !== $file->path) {
                if ($local->exists($file->path)) {
                    throw new RuntimeException('Local destination changed during transfer. Remote source retained.');
                }
                if (! $local->move($targetKey, $file->path)) {
                    throw new RuntimeException('Could not activate verified local copy.');
                }
            }
            if (! $transfer->target_profile_id) {
                DB::table('pdf_storage_transfers')->where('id', $transfer->id)->update(['target_key' => $file->path]);
            }
            DB::table('pdf_storage_files')->where('id', $file->id)->update(['profile_id' => $transfer->target_profile_id,
                'object_key' => $transfer->target_profile_id ? $targetKey : null, 'updated_at' => now()]);
            $message = 'Copy verified; source retained.';
            if ($transfer->remove_source && ! ($file->profile_id === null && $transfer->target_profile_id === null)) {
                try {
                    if (! hash_equals($file->sha256, $this->hashStream($sourceDisk->readStream($sourceKey)))) {
                        throw new RuntimeException('Source changed after copying.');
                    }
                    if (! $sourceDisk->delete($sourceKey)) {
                        throw new RuntimeException('Delete failed');
                    }
                    $message = 'Moved and verified.';
                    if ($transfer->target_profile_id && $file->profile_id && $local->exists($file->path)
                        && hash_equals($file->sha256, $this->hashStream($local->readStream($file->path)))) {
                        $local->delete($file->path);
                    }
                } catch (\Throwable $error) {
                    $message = 'Destination verified and active. Source cleanup failed; extra copy retained.';
                }
            }
            DB::table('pdf_storage_transfers')->where('id', $transfer->id)->update(['status' => 'completed', 'message' => $message, 'updated_at' => now()]);
        });
    }
}
