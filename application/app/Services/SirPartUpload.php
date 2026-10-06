<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SirPartUpload
{
    private function folder(): string
    {
        return storage_path('app/private/sir-upload-parts');
    }

    public function start(int $user, string $kind, int $size, string $sha): string
    {
        File::ensureDirectoryExists($this->folder());
        foreach (File::files($this->folder()) as $file) {
            if (preg_match('/^[a-f0-9-]{36}\.part$/', $file->getFilename()) && $file->getMTime() < time() - 3600) {
                File::delete($file->getPathname());
            }
        }
        $token = (string) Str::uuid();
        File::put($this->folder().'/'.$token.'.part', '');
        Cache::put('sir-upload:'.$token, ['user' => $user, 'kind' => $kind, 'size' => $size, 'sha256' => $sha, 'offset' => 0], now()->addMinutes(30));

        return $token;
    }

    public function append(int $user, string $token, int $offset, UploadedFile $chunk): int
    {
        $lock = Cache::lock('sir-upload-lock:'.$token, 30);
        abort_unless($lock->get(), 409, 'Upload chunk is already being saved.');
        try {
            $meta = $this->metadata($user, $token);
            abort_unless($offset === $meta['offset'], 409, 'Upload offset changed. Start the upload again.');
            $bytes = file_get_contents($chunk->getRealPath());
            abort_unless(strlen($bytes) > 0 && strlen($bytes) <= 524288 && $offset + strlen($bytes) <= $meta['size'], 422, 'Invalid upload chunk size.');
            $path = $this->folder().'/'.$token.'.part';
            clearstatcache(true, $path);
            abort_unless(is_file($path) && filesize($path) === $offset, 409, 'Upload file no longer matches its progress.');
            abort_unless(file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX) === strlen($bytes), 500, 'Unable to save upload chunk.');
            $meta['offset'] += strlen($bytes);
            Cache::put('sir-upload:'.$token, $meta, now()->addMinutes(30));

            return $meta['offset'];
        } finally {
            $lock->release();
        }
    }

    private function metadata(int $user, string $token): array
    {
        abort_unless(Str::isUuid($token), 422, 'Invalid upload reference.');
        $meta = Cache::get('sir-upload:'.$token);
        abort_unless(is_array($meta) && $meta['user'] === $user, 404, 'Upload expired or is unavailable to this administrator.');

        return $meta;
    }

    public function resolve(int $user, string $token, string $kind): UploadedFile
    {
        $meta = $this->metadata($user, $token);
        $path = $this->folder().'/'.$token.'.part';
        clearstatcache(true, $path);
        abort_unless($meta['kind'] === $kind && $meta['offset'] === $meta['size'] && is_file($path) && filesize($path) === $meta['size'], 422, 'Upload is incomplete or belongs to a different field.');
        abort_unless(hash_equals($meta['sha256'], hash_file('sha256', $path)), 422, 'Uploaded file checksum mismatch.');

        return new UploadedFile($path, $kind === 'pdf' ? 'original.pdf' : 'records.json', null, null, true);
    }

    public function discard(int $user, string $token): void
    {
        $this->metadata($user, $token);
        File::delete($this->folder().'/'.$token.'.part');
        Cache::forget('sir-upload:'.$token);
    }
}
