<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchiveFiles
{
    private array $temporary = [];

    public function __construct(private PdfStorage $pdfs) {}

    public function __call(string $method, array $arguments): mixed
    {
        return Storage::disk('local')->$method(...$arguments);
    }

    private function record(string $path): ?object
    {
        return $this->pdfs->allowed($path) ? DB::table('pdf_storage_files')->where('path_hash', hash('sha256', $path))->first() : null;
    }

    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path) || ($this->record($path)?->profile_id !== null);
    }

    public function readStream(string $path): mixed
    {
        $local = Storage::disk('local');
        if ($local->exists($path)) {
            return $local->readStream($path);
        }
        $record = $this->record($path);
        if (! $record?->profile_id) {
            throw new RuntimeException('Archived file is missing.');
        }
        try {
            return $this->pdfs->remote($record->profile_id)->readStream($record->object_key);
        } catch (\Throwable $error) {
            throw new RuntimeException('Archived PDF is temporarily unavailable from bucket storage.');
        }
    }

    public function get(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function path(string $path): string
    {
        $local = Storage::disk('local');
        if ($local->exists($path) || ! $this->record($path)?->profile_id) {
            return $local->path($path);
        }
        if (isset($this->temporary[$path]) && is_file($this->temporary[$path])) {
            return $this->temporary[$path];
        }
        $record = $this->record($path);
        $directory = storage_path('app/pdf-working');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $temporary = tempnam($directory, 'pdf-');
        $this->temporary[$path] = $temporary;
        $input = $this->readStream($path);
        $output = fopen($temporary, 'wb');
        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
        if (! hash_equals($record->sha256, hash_file('sha256', $temporary))) {
            unlink($temporary);
            throw new RuntimeException('Archived PDF checksum differs.');
        }
        $this->temporary[$path] = $temporary;

        return $temporary;
    }

    public function verify(string $path, string $sha256): bool
    {
        if (! $this->exists($path)) {
            return false;
        }

        return hash_equals($sha256, $this->pdfs->hashStream($this->readStream($path)));
    }

    public function download(string $path, ?string $name = null, array $headers = []): StreamedResponse
    {
        $record = $this->record($path);
        if ($record && ! $this->verify($path, $record->sha256)) {
            abort(409, 'Archived PDF checksum differs.');
        }

        return response()->streamDownload(function () use ($path): void {
            $stream = $this->readStream($path);
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $name ?? basename($path), $headers);
    }

    public function cleanup(): void
    {
        foreach ($this->temporary as $temporary) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->temporary = [];
    }

    public function withDirectory(string $directory, callable $callback): mixed
    {
        $disk = Storage::disk('local');
        $restored = [];
        $staged = [];
        $locks = [];
        try {
            $files = DB::table('pdf_storage_files')->whereNotNull('profile_id')->get();
            foreach ($files as $file) {
                if (! str_starts_with($file->path, rtrim($directory, '/').'/') || $disk->exists($file->path)) {
                    continue;
                }
                $lock = Cache::lock('pdf-file-'.$file->id, 1800);
                $lock->block(5);
                $locks[] = $lock;
                $temporary = $this->path($file->path);
                $stage = 'pdf-working-transfers/'.Str::uuid().'.pdf';
                $staged[] = $stage;
                $stream = fopen($temporary, 'rb');
                try {
                    if (! $disk->put($stage, $stream)) {
                        throw new RuntimeException('Insufficient working storage for PDF extraction.');
                    }
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($file->sha256, $this->pdfs->hashStream($disk->readStream($stage))) || $disk->exists($file->path)) {
                    throw new RuntimeException('Working PDF could not be verified or its path changed.');
                }
                if (! $disk->move($stage, $file->path)) {
                    throw new RuntimeException('Working PDF could not be activated.');
                }
                $restored[$file->path] = $file->sha256;
            }

            return $callback();
        } finally {
            foreach ($staged as $stage) {
                $disk->delete($stage);
            }
            foreach ($restored as $path => $sha) {
                if ($disk->exists($path) && hash_equals($sha, $this->pdfs->hashStream($disk->readStream($path)))) {
                    $disk->delete($path);
                }
            }
            $this->cleanup();
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }
}
