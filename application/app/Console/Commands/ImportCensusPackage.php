<?php

namespace App\Console\Commands;

use App\Services\ArchiveFiles;
use App\Services\CensusCatalogue;
use App\Services\OfficialImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZipArchive;

class ImportCensusPackage extends Command
{
    protected $signature = 'census:import-package {package} {--sha256= : Expected package checksum} {--publish : Publish new editions with discrepancy notes} {--check : Verify package contents without importing}';

    protected $description = 'Import a checksummed Census package while preserving sources and local database IDs';

    public function handle(CensusCatalogue $catalogue, OfficialImport $importer): int
    {
        $lock = Cache::lock('census-national-collection', 3600);
        if (! $lock->get()) {
            $this->error('Census collection is already running.');

            return self::FAILURE;
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            $path = $this->argument('package');
            $expected = $this->option('sha256');
            abort_unless(is_string($expected) && preg_match('/^[a-f0-9]{64}$/', $expected)
                && is_file($path) && hash_equals($expected, hash_file('sha256', $path)), 422, 'Package checksum mismatch or missing --sha256.');
            abort_unless($zip->open($path) === true, 422, 'Cannot open package.');
            $opened = true;
            $manifest = json_decode($this->member($zip, 'manifest.json', 1000000), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($manifest['version'] ?? null) === 1 && ! empty($manifest['sources']) && count($manifest['sources']) <= 20, 422, 'Invalid package manifest.');
            $items = [];
            foreach ($manifest['sources'] as $item) {
                $key = $item['key'];
                abort_unless(is_string($key) && preg_match('/^[a-z0-9-]+$/', $key) && ! isset($items[$key]), 422, 'Invalid or repeated source key.');
                $source = config('census-sources.'.$key);
                abort_unless($source && ! ($source['archive_only'] ?? false)
                    && $source['url'] === $item['source_url']
                    && ($source['filter_value'] ?? null) === ($item['options']['filter_value'] ?? null), 422, 'Package source or scope differs from configured source.');
                $raw = $this->member($zip, $key.'.'.$source['format'], 20000000);
                $extracted = $this->member($zip, $key.'.json', 50000000);
                abort_unless(hash_equals($item['sha256'], hash('sha256', $raw))
                    && hash_equals($item['extracted_sha256'], hash('sha256', $extracted)), 422, 'Source or extraction checksum mismatch.');
                $data = json_decode($extracted, true, 512, JSON_THROW_ON_ERROR);
                abort_unless(isset($data['headers'], $data['rows'], $data['scope']) && count($data['rows']) === $item['row_count']
                    && $item['row_count'] > 0 && $item['row_count'] <= 20000, 422, 'Extraction row count differs from manifest.');
                $items[$key] = compact('item', 'source');
                unset($raw, $extracted, $data);
            }
            if ($this->option('check')) {
                $this->info('Verified '.count($items).' sources and their extraction checksums; no database changes.');

                return self::SUCCESS;
            }
            DB::transaction(function () use ($items, $catalogue, $importer, $zip): void {
                foreach ($items as $key => $entry) {
                    $item = $entry['item'];
                    $source = $entry['source'];
                    $extracted = $this->member($zip, $key.'.json', 50000000);
                    $existing = DB::table('census_editions')->join('import_runs', 'import_runs.id', '=', 'census_editions.import_run_id')
                        ->where('census_editions.source_key', $key)->where('census_editions.sha256', $item['sha256'])
                        ->select('census_editions.id', 'census_editions.status', 'import_runs.extracted')->get()
                        ->first(fn ($row) => hash('sha256', $row->extracted) === $item['extracted_sha256']);
                    if ($existing) {
                        $edition = $existing->id;
                    } else {
                        $connector = DB::table('import_connectors')->insertGetId([
                            'name' => $source['name'], 'url' => $source['url'], 'format' => $source['format'],
                            'record_key' => 'source_record_key', 'options' => json_encode($item['options']), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $rawPath = 'official-imports/census-package/'.$item['sha256'].'.'.$source['format'];
                        abort_unless(app(ArchiveFiles::class)->put($rawPath, $this->member($zip, $key.'.'.$source['format'], 20000000)), 500, 'Could not preserve original file.');
                        $data = json_decode($extracted, true, 512, JSON_THROW_ON_ERROR);
                        $summary = json_encode($importer->compare($data, null, 'source_record_key'));
                        unset($data);
                        $run = DB::table('import_runs')->insertGetId([
                            'import_connector_id' => $connector, 'origin' => 'upload', 'source_url' => $source['url'],
                            'status' => 'needs_review', 'sha256' => $item['sha256'], 'raw_path' => $rawPath,
                            'extracted' => $extracted, 'summary' => $summary,
                            'created_at' => $item['retrieved_at'],
                        ]);
                        unset($extracted);
                        $edition = $catalogue->prepare($run);
                    }
                    $record = DB::table('census_editions')->find($edition);
                    if ($this->option('publish') && $record->status === 'draft') {
                        $current = (int) (DB::table('census_publications')->where('source_key', $key)->value('edition_id') ?? 0);
                        abort_unless($current === 0, 409, 'An edition is already published for '.$key.'. Review replacements in admin.');
                        $catalogue->publish($edition, null, 0);
                    }
                    $this->line($key.': '.$record->row_count.' records; '.$record->flag_count.' discrepancy notes.');
                }
            });
            $this->info('Census package import completed. Existing published or withdrawn editions were preserved.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if ($opened) {
                $zip->close();
            }
            $lock->release();
        }
    }

    private function member(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= $limit, 422, 'Missing or oversized package member: '.$name);
        $body = $zip->getFromName($name);
        abort_unless(is_string($body), 422, 'Unreadable package member: '.$name);

        return $body;
    }
}
