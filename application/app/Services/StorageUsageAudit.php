<?php

namespace App\Services;

class StorageUsageAudit
{
    public function report(): ?array
    {
        $path = storage_path('app/storage-management/disk-audit.json');

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function measure(string $path): array
    {
        $bytes = $count = 0;
        if (! is_dir($path) || is_link($path)) {
            return ['bytes' => 0, 'files' => 0, 'complete' => ! is_link($path)];
        }
        $complete = true;
        $pending = [$path];
        while ($directory = array_pop($pending)) {
            $entries = @scandir($directory);
            if ($entries === false) {
                $complete = false;

                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $file = $directory.DIRECTORY_SEPARATOR.$entry;
                if (is_link($file)) {
                    continue;
                }
                if (is_dir($file)) {
                    $pending[] = $file;
                } elseif (is_file($file)) {
                    $size = @filesize($file);
                    if ($size === false) {
                        $complete = false;
                    } else {
                        $bytes += $size;
                        $count++;
                    }
                }
            }
        }

        return ['bytes' => $bytes, 'files' => $count, 'complete' => $complete];
    }

    public function run(): array
    {
        $app = base_path();
        $roots = [
            ['JJM snapshots', $app.'/storage/app/jjm/national/snapshots', 'Move eligible snapshot files using the JJM category. Active database excluded.'],
            ['Satellite evidence', $app.'/storage/app/satellite', 'Move using the satellite category.'],
            ['Soil sources', $app.'/storage/app/soil', 'Only archive packages are movable; working data and receipts remain local.'],
            ['Private source files', $app.'/storage/app/private', 'Eligible PDFs use PDF storage. Indexed tables and other working files remain local.'],
            ['Published maps', $app.'/public/maps', 'Only satellite assets are movable here. Original polygons and active map datasets remain local.'],
            ['Database fixtures', $app.'/database', 'Protected: active data and application migrations.'],
            ['Framework working files', $app.'/storage/framework', 'Protected: sessions, cache and compiled views.'],
            ['Application logs', $app.'/storage/logs', 'Protected: manage log retention separately.'],
        ];
        if (PHP_OS_FAMILY !== 'Windows') {
            $home = dirname($app, 2);
            foreach (['tmp' => 'Temporary/import staging', 'census-worker' => 'Census worker', 'app/releases' => 'Deployment history', 'backups' => 'Backups'] as $path => $label) {
                $roots[] = [$label, $home.'/'.$path, 'Review separately; excluded from moves because active jobs or recovery may depend on these files.'];
            }
        }
        $rows = [];
        foreach ($roots as [$label, $path, $note]) {
            $rows[] = ['label' => $label, 'note' => $note] + $this->measure($path);
        }
        $report = ['checked_at' => now()->toIso8601String(), 'free_bytes' => disk_free_space($app), 'total_bytes' => disk_total_space($app), 'rows' => $rows];
        $directory = storage_path('app/storage-management');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/disk-audit.json';
        file_put_contents($path.'.incoming', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($path.'.incoming', $path);

        return $report;
    }
}
