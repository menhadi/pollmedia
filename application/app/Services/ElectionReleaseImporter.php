<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class ElectionReleaseImporter
{
    private const ROOT = 'election-catalogue-monitor';

    public function run(): array
    {
        $lock = Cache::lock('election-release-import', 21600);
        if (! $lock->get()) {
            throw new RuntimeException('An election release import is already running.');
        }

        try {
            $catalogue = app(ElectionCatalogueMonitor::class)->check();
            $result = ['checked_at' => now()->toIso8601String(), 'source_sha256' => $catalogue['source_sha256'], 'imported' => [], 'needs_review' => []];
            foreach ($catalogue['pending'] as $entry) {
                try {
                    if (($entry['kind'] ?? null) !== 'ac' || ! preg_match('~^https://www\.eci\.gov\.in/statistical-report/ae/(20\d{2})/\d+$~', $entry['url'] ?? '', $match)
                        || (int) ($entry['year'] ?? 0) !== (int) $match[1]) {
                        throw new RuntimeException('This official edition needs a verified result-table adapter.');
                    }
                    $state = $this->canonicalState($entry['state_label'] ?? '');
                    if ($state === null) {
                        throw new RuntimeException('The official state label does not match a verified state identity.');
                    }
                    $this->resourcesAvailable();
                    $this->importAssembly($entry, $state);
                    $result['imported'][] = ['kind' => 'ac', 'state' => $state, 'year' => $entry['year'], 'url' => $entry['url']];
                } catch (\Throwable $error) {
                    $result['needs_review'][] = ['kind' => $entry['kind'], 'year' => $entry['year'], 'url' => $entry['url'], 'reason' => $error->getMessage()];
                }
                $this->saveResult($result);
            }
            $this->saveResult($result);
            app(ManagedTasks::class)->record('election_sync', $result['needs_review'] ? 'attention' : 'success', json_encode(['imported' => count($result['imported']), 'needs_review' => count($result['needs_review'])], JSON_THROW_ON_ERROR));

            return $result;
        } catch (\Throwable $error) {
            app(ManagedTasks::class)->record('election_sync', 'failed', $error->getMessage());
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function latest(): ?array
    {
        $disk = Storage::disk('local');
        $path = self::ROOT.'/sync-latest.json';

        return $disk->exists($path) ? json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function saveResult(array $result): void
    {
        Storage::disk('local')->put(self::ROOT.'/sync-latest.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalState(string $label): ?string
    {
        $states = collect(app(ElectionArchive::class)->nationalAssemblyEntries())->pluck('state')->unique();

        return $states->first(fn (string $state): bool => mb_strtolower($state) === mb_strtolower(trim($label)));
    }

    private function resourcesAvailable(): void
    {
        $free = disk_free_space(storage_path());
        if ($free === false || $free < 10 * 1024 ** 3) {
            throw new RuntimeException('Less than 10 GiB disk reserve is available for source preservation.');
        }
        if (is_file('/proc/meminfo')) {
            preg_match('/^MemAvailable:\s+(\d+) kB/m', file_get_contents('/proc/meminfo'), $match);
            if (! isset($match[1]) || (int) $match[1] < 1280 * 1024) {
                throw new RuntimeException('Less than 1.25 GiB RAM is available for one extraction worker.');
            }
        }
    }

    private function importAssembly(array $entry, string $state): void
    {
        $url = $entry['url'];
        $year = (int) $entry['year'];
        $id = substr(hash('sha256', $url), 0, 24);
        $disk = Storage::disk('local');
        $cataloguePath = self::ROOT.'/work/'.$id.'.json';
        $disk->put($cataloguePath, json_encode(['entries' => [['state' => $state, 'year' => $year, 'label' => (string) $year, 'url' => $url]]], JSON_THROW_ON_ERROR));
        $root = app(ArchiveFiles::class)->path('election-archive');
        $this->python([base_path('../pilot/collect_election_archive.py'), $disk->path($cataloguePath), $root, '--kind', 'ac', '--state', $state, '--year', (string) $year, '--workers', '1']);
        $folder = $root.DIRECTORY_SEPARATOR.$id;
        $manifestBody = file_get_contents($folder.DIRECTORY_SEPARATOR.'manifest.json');
        $manifest = json_decode($manifestBody, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['url'] ?? null) !== $url || ($manifest['status'] ?? null) !== 'collected' || count($manifest['files'] ?? []) < 2) {
            throw new RuntimeException('Official report collection is incomplete.');
        }
        foreach ($manifest['files'] as $file) {
            $name = $file['file'] ?? '';
            $path = $folder.DIRECTORY_SEPARATOR.$name;
            if (basename($name) !== $name || ! is_file($path) || ! hash_equals($file['sha256'] ?? '', hash_file('sha256', $path))) {
                throw new RuntimeException('An original report checksum differs.');
            }
        }
        $this->resourcesAvailable();
        $this->python([base_path('../pilot/extract_assembly_modern.py'), $disk->path($cataloguePath), $root, '--year', (string) $year]);
        $extractionBody = file_get_contents($folder.DIRECTORY_SEPARATOR.'extraction.json');
        $extraction = json_decode($extractionBody, true, 512, JSON_THROW_ON_ERROR);
        if (($extraction['source_url'] ?? null) !== $url || ($extraction['kind'] ?? null) !== 'ac'
            || ($extraction['year'] ?? null) !== $year || empty($extraction['records'])) {
            throw new RuntimeException('Extracted tables do not match the official edition.');
        }
        $sources = collect($manifest['files'])->pluck('sha256', 'file');
        if ($sources->get($extraction['source_file'] ?? null) !== ($extraction['source_sha256'] ?? null)) {
            throw new RuntimeException('Extracted source checksum differs from the manifest.');
        }
        foreach ($extraction['additional_sources'] ?? [] as $source) {
            if ($sources->get($source['file'] ?? null) !== ($source['sha256'] ?? null)) {
                throw new RuntimeException('Additional source checksum differs from the manifest.');
            }
        }
        $this->storeNewJson('election-archive/'.$id.'/manifest.json', $manifestBody, $url);
        $this->storeNewJson('election-archive/'.$id.'/extraction.json', $extractionBody, $url);
        app(ElectionRuntimeCatalogue::class)->stage($entry, $state);
        DB::transaction(function () use ($id, $url): void {
            if (Artisan::call('archive:index-constituencies', ['--edition' => $id]) !== 0) {
                throw new RuntimeException('The extracted edition did not pass constituency indexing.');
            }
            app(ElectionRuntimeCatalogue::class)->publish($url);
        });
    }

    private function python(array $arguments): void
    {
        $process = new Process([config('imports.python'), ...$arguments]);
        $process->setTimeout(3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The official-source adapter needs review: '.mb_substr(trim($process->getErrorOutput().' '.$process->getOutput()), 0, 500));
        }
    }

    private function storeNewJson(string $path, string $body, string $url): void
    {
        $sha = hash('sha256', $body);
        DB::transaction(function () use ($path, $body, $url, $sha): void {
            $prior = DB::table('archive_json_files')->where('path_hash', hash('sha256', $path))->lockForUpdate()->first();
            if ($prior) {
                if ($prior->path !== $path || ! hash_equals($prior->sha256, hash('sha256', $prior->body)) || (int) $prior->bytes !== strlen($prior->body)) {
                    throw new RuntimeException('Archived JSON has a checksum conflict: '.$path);
                }
                if ($prior->sha256 !== $sha || $prior->body !== $body) {
                    $snapshotPath = substr($path, 0, -5).'-'.$prior->sha256.'.json';
                    $snapshot = DB::table('archive_json_files')->where('path_hash', hash('sha256', $snapshotPath))->first();
                    if ($snapshot && ($snapshot->path !== $snapshotPath || $snapshot->sha256 !== $prior->sha256 || $snapshot->body !== $prior->body)) {
                        throw new RuntimeException('Archived JSON snapshot conflicts: '.$snapshotPath);
                    }
                    if (! $snapshot) {
                        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $snapshotPath), 'path' => $snapshotPath,
                            'category' => 'election-archive', 'sha256' => $prior->sha256, 'bytes' => $prior->bytes,
                            'source_url' => $url, 'body' => $prior->body, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('archive_json_files')->where('path_hash', hash('sha256', $path))->update([
                        'sha256' => $sha, 'bytes' => strlen($body), 'source_url' => $url, 'body' => $body, 'updated_at' => now(),
                    ]);
                }

                return;
            }
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path,
                'category' => 'election-archive', 'sha256' => $sha, 'bytes' => strlen($body),
                'source_url' => $url, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
