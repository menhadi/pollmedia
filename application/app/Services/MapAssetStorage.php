<?php

namespace App\Services;

use App\Jobs\TransferMapAsset;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MapAssetStorage
{
    public function __construct(private PdfStorage $profiles, private SiteSettings $settings) {}

    public function policy(): array
    {
        return array_replace(['profile_id' => null, 'delivery' => 'stream'], $this->settings->get('satellite.storage', []));
    }

    public function allowed(string $path): bool
    {
        return $this->category($path) !== null;
    }

    public const CATEGORIES = [
        'satellite' => 'Satellite images and evidence',
        'jjm' => 'JJM original source snapshots',
        'soil' => 'Soil source archive packages',
    ];

    public function category(string $path): ?string
    {
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }
        if (preg_match('~^(?:public/maps/satellite-[a-z0-9-]+/[a-zA-Z0-9_-]+\.(?:json|webp|png|jpg)|storage/app/satellite/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+\.(?:json|tar\.gz))$~D', $path)) {
            return 'satellite';
        }
        if (preg_match('~^storage/app/jjm/national/snapshots/[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+\.(?:html|json)$~D', $path)) {
            return 'jjm';
        }
        if (preg_match('~^storage/app/soil/shc-[a-zA-Z0-9_-]+\.tar\.gz$~D', $path)) {
            return 'soil';
        }

        return null;
    }

    public function categoryQuery(string $category): Builder
    {
        $query = DB::table('map_storage_files');

        return match ($category) {
            'satellite' => $query->where(fn ($q) => $q->where('path', 'like', 'public/maps/satellite-%')->orWhere('path', 'like', 'storage/app/satellite/%')),
            'jjm' => $query->where('path', 'like', 'storage/app/jjm/national/snapshots/%'),
            'soil' => $query->where('path', 'like', 'storage/app/soil/%'),
            default => throw new RuntimeException('Unsupported storage category.'),
        };
    }

    public function local(): FilesystemAdapter
    {
        return Storage::build(['driver' => 'local', 'root' => config('filesystems.map_asset_root', base_path()), 'throw' => true]);
    }

    public function remote(int $profile): FilesystemAdapter
    {
        return $this->profiles->remote($profile);
    }

    public function assertLocalPath(string $path): void
    {
        if (! $this->allowed($path)) {
            throw new RuntimeException('Unsupported map asset path.');
        }
        $current = rtrim($this->local()->path(''), DIRECTORY_SEPARATOR);
        foreach (explode('/', $path) as $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                throw new RuntimeException('Linked paths are not eligible for moves.');
            }
        }
    }

    public function scan(?string $release = null): array
    {
        if ($release !== null && ! preg_match('/^satellite-[a-z0-9-]+$/D', $release)) {
            throw new RuntimeException('Invalid release name.');
        }
        $disk = $this->local();
        $roots = $release ? ['public/maps/'.$release, 'storage/app/satellite/'.$release] : ['public/maps', 'storage/app/satellite', 'storage/app/jjm/national/snapshots', 'storage/app/soil'];
        $ids = [];
        foreach ($roots as $root) {
            foreach ($disk->allFiles($root) as $path) {
                if (! $this->allowed($path)) {
                    continue;
                }
                $this->assertLocalPath($path);
                $hash = hash('sha256', $path);
                if (! DB::table('map_storage_files')->where('path_hash', $hash)->exists()) {
                    DB::table('map_storage_files')->insertOrIgnore(['path' => $path, 'path_hash' => $hash,
                        'sha256' => $this->profiles->hashStream($disk->readStream($path)), 'bytes' => $disk->size($path),
                        'created_at' => now(), 'updated_at' => now()]);
                }
                $ids[] = DB::table('map_storage_files')->where('path_hash', $hash)->value('id');
            }
        }

        return $ids;
    }

    public function queue(int $id, ?int $target, ?int $user = null): void
    {
        if ($target && ! DB::table('pdf_storage_profiles')->where('id', $target)->whereNotNull('tested_at')->exists()) {
            throw new RuntimeException('Test the destination bucket before moving files.');
        }
        Cache::lock('map-asset-'.$id, 1800)->block(3, function () use ($id, $target, $user): void {
            $file = DB::table('map_storage_files')->find($id);
            if (! $file) {
                throw new RuntimeException('Map asset no longer exists.');
            }
            $this->assertLocalPath($file->path);
            $active = DB::table('map_storage_transfers')->where('file_id', $id)->whereIn('status', ['queued', 'running', 'cleanup_pending'])->first();
            if ($active) {
                if ($active->status === 'cleanup_pending') {
                    TransferMapAsset::dispatch($active->id);
                }

                return;
            }
            if ($file->profile_id === $target) {
                return;
            }
            $transfer = DB::table('map_storage_transfers')->insertGetId(['file_id' => $id, 'target_profile_id' => $target,
                'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
            TransferMapAsset::dispatch($transfer);
        });
    }

    private function verified(FilesystemAdapter $disk, string $key, object $file): bool
    {
        return $disk->size($key) === (int) $file->bytes
            && hash_equals($file->sha256, $this->profiles->hashStream($disk->readStream($key)));
    }

    private function cleanup(object $transfer, object $file): void
    {
        $destination = $file->profile_id ? $this->remote($file->profile_id) : $this->local();
        $destinationKey = $file->profile_id ? $file->object_key : $file->path;
        if ($file->profile_id !== $transfer->target_profile_id || ! $this->verified($destination, $destinationKey, $file)) {
            throw new RuntimeException('Active destination must be verified before removing the source.');
        }
        $source = $transfer->source_profile_id ? $this->remote($transfer->source_profile_id) : $this->local();
        if (! $transfer->source_profile_id) {
            $this->assertLocalPath($transfer->source_key);
        }
        if ($source->exists($transfer->source_key)) {
            if (! $this->verified($source, $transfer->source_key, $file) || ! $source->delete($transfer->source_key)) {
                throw new RuntimeException('Source cleanup failed; verified destination remains active.');
            }
        }
        DB::table('map_storage_transfers')->where('id', $transfer->id)->update(['status' => 'completed', 'message' => 'Moved and verified. Source removed.', 'updated_at' => now()]);
    }

    public function transfer(int $id): void
    {
        $initial = DB::table('map_storage_transfers')->find($id);
        if (! $initial || $initial->status === 'completed') {
            return;
        }
        Cache::lock('map-asset-'.$initial->file_id, 1800)->block(5, function () use ($id): void {
            $transfer = DB::table('map_storage_transfers')->find($id);
            $file = DB::table('map_storage_files')->find($transfer->file_id);
            if ($transfer->status === 'completed') {
                return;
            }
            $this->assertLocalPath($file->path);
            if ($transfer->status === 'cleanup_pending') {
                $this->cleanup($transfer, $file);

                return;
            }
            if ($file->profile_id === $transfer->target_profile_id) {
                throw new RuntimeException('Destination is already active; no source removed.');
            }
            if ($transfer->target_profile_id && ! DB::table('pdf_storage_profiles')->where('id', $transfer->target_profile_id)->whereNotNull('tested_at')->exists()) {
                throw new RuntimeException('Destination connection needs testing.');
            }
            $source = $file->profile_id ? $this->remote($file->profile_id) : $this->local();
            $sourceKey = $file->profile_id ? $file->object_key : $file->path;
            $target = $transfer->target_profile_id ? $this->remote($transfer->target_profile_id) : $this->local();
            $targetKey = $transfer->target_key ?: ($transfer->target_profile_id
                ? DB::table('pdf_storage_profiles')->where('id', $transfer->target_profile_id)->value('prefix').'/satellite/'.$file->path_hash.'/'.Str::uuid().'/'.basename($file->path)
                : $file->path.'.incoming-'.$transfer->id);
            DB::table('map_storage_transfers')->where('id', $id)->update(['status' => 'running', 'source_profile_id' => $file->profile_id,
                'source_key' => $sourceKey, 'target_key' => $targetKey, 'message' => null, 'updated_at' => now()]);
            if (! $this->verified($source, $sourceKey, $file)) {
                throw new RuntimeException('Source checksum changed. Original retained.');
            }
            if (! $transfer->target_profile_id) {
                if (is_link($target->path($targetKey))) {
                    throw new RuntimeException('Linked staging path rejected.');
                }
                $free = disk_free_space($target->path(''));
                if ($free === false || $free < $file->bytes + 1073741824) {
                    throw new RuntimeException('Not enough server space; 1 GB reserve required.');
                }
                if ($target->exists($file->path) && ! $this->verified($target, $file->path, $file)) {
                    throw new RuntimeException('Different local file exists. Nothing overwritten.');
                }
            }
            $stream = $source->readStream($sourceKey);
            try {
                if (! $target->put($targetKey, $stream)) {
                    throw new RuntimeException('Destination write failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $this->verified($target, $targetKey, $file)) {
                throw new RuntimeException('Destination checksum failed. Original retained.');
            }
            if (! $transfer->target_profile_id) {
                if ($target->exists($file->path)) {
                    if (! $this->verified($target, $file->path, $file)) {
                        throw new RuntimeException('Local destination changed. Remote original retained.');
                    }
                    $target->delete($targetKey);
                } elseif (! $target->move($targetKey, $file->path)) {
                    throw new RuntimeException('Could not activate local destination.');
                }
                $targetKey = $file->path;
            }
            DB::transaction(function () use ($file, $transfer, $targetKey): void {
                DB::table('map_storage_files')->where('id', $file->id)->update(['profile_id' => $transfer->target_profile_id,
                    'object_key' => $transfer->target_profile_id ? $targetKey : null, 'updated_at' => now()]);
                DB::table('map_storage_transfers')->where('id', $transfer->id)->update(['status' => 'cleanup_pending', 'target_key' => $targetKey,
                    'message' => 'Destination verified and active; source removal pending.', 'updated_at' => now()]);
            });
            $this->cleanup(DB::table('map_storage_transfers')->find($id), DB::table('map_storage_files')->find($file->id));
        });
    }
}
