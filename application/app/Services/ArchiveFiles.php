<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private function jsonRecord(string $path): ?object
    {
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")
            || ! preg_match('~^(election-archive|election-by-elections)/[a-zA-Z0-9_./-]+\.json$~', $path)
            || ! Schema::hasTable('archive_json_files')) {
            return null;
        }

        return DB::table('archive_json_files')->where('path_hash', hash('sha256', $path))->first();
    }

    private function originalRecord(string $path): ?object
    {
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")
            || ! preg_match('~^(election-archive|election-by-elections)/[a-f0-9]{24}/[A-Za-z0-9_.-]+\.(?:html|xls|xlsx|zip)$~', $path)
            || ! Schema::hasTable('archive_original_files')) {
            return null;
        }

        return DB::table('archive_original_files')->where('path_hash', hash('sha256', $path))->first();
    }

    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path) || ($this->record($path)?->profile_id !== null)
            || $this->jsonRecord($path) !== null || $this->originalRecord($path) !== null;
    }

    public function readStream(string $path): mixed
    {
        $local = Storage::disk('local');
        if ($local->exists($path)) {
            return $local->readStream($path);
        }
        $json = $this->jsonRecord($path);
        if ($json) {
            if ($json->path !== $path || (int) $json->bytes !== strlen($json->body)
                || ! hash_equals($json->sha256, hash('sha256', $json->body))) {
                throw new RuntimeException('Archived JSON checksum differs.');
            }
            $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
            fwrite($stream, $json->body);
            rewind($stream);

            return $stream;
        }
        $record = $this->record($path) ?: $this->originalRecord($path);
        if (! $record?->profile_id) {
            throw new RuntimeException('Archived file is missing.');
        }
        try {
            return $this->pdfs->remote($record->profile_id)->readStream($record->object_key);
        } catch (\Throwable $error) {
            throw new RuntimeException('Archived original is temporarily unavailable from bucket storage.');
        }
    }

    public function get(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            $body = stream_get_contents($stream);
            $record = $this->record($path) ?: $this->originalRecord($path);
            if ($record && ((int) $record->bytes !== strlen($body)
                || ! hash_equals($record->sha256, hash('sha256', $body)))) {
                throw new RuntimeException('Archived file checksum differs.');
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    public function path(string $path): string
    {
        $local = Storage::disk('local');
        if ($local->exists($path)) {
            return $local->path($path);
        }
        $record = $this->jsonRecord($path) ?: $this->record($path) ?: $this->originalRecord($path);
        if (! $record || (property_exists($record, 'profile_id') && ! $record->profile_id)) {
            return $local->path($path);
        }
        if (isset($this->temporary[$path]) && is_file($this->temporary[$path])) {
            return $this->temporary[$path];
        }
        $directory = storage_path('app/pdf-working');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $temporary = tempnam($directory, 'pdf-');
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $named = $temporary.'.'.$extension;
            if (! rename($temporary, $named)) {
                unlink($temporary);
                throw new RuntimeException('Could not prepare the archived working file.');
            }
            $temporary = $named;
        }
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
            throw new RuntimeException('Archived file checksum differs.');
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
        $record = $this->record($path) ?: $this->originalRecord($path);
        if ($record && ! $this->verify($path, $record->sha256)) {
            abort(409, 'Archived file checksum differs.');
        }
        $headers += ['Content-Type' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf'
            ? 'application/pdf' : 'application/octet-stream'];

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
            $files = DB::table('pdf_storage_files')->whereNotNull('profile_id')->get()->all();
            if (Schema::hasTable('archive_original_files')) {
                array_push($files, ...DB::table('archive_original_files')->get()->all());
            }
            foreach ($files as $file) {
                if (! str_starts_with($file->path, rtrim($directory, '/').'/') || $disk->exists($file->path)) {
                    continue;
                }
                $lock = Cache::lock(property_exists($file, 'id') ? 'pdf-file-'.$file->id : 'archive-original-'.$file->path_hash, 1800);
                $lock->block(5);
                $locks[] = $lock;
                $temporary = $this->path($file->path);
                $stage = 'pdf-working-transfers/'.Str::uuid().'.'.pathinfo($file->path, PATHINFO_EXTENSION);
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
