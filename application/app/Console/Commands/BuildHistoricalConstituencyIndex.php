<?php

namespace App\Console\Commands;

use App\Services\ElectionArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BuildHistoricalConstituencyIndex extends Command
{
    protected $signature = 'archive:index-constituencies {--check : Validate imported extraction JSON without writing the index}';

    protected $description = 'Index edition-specific historical PC and AC records without publishing accepted election results';

    public function handle(ElectionArchive $archives): int
    {
        $catalogue = $this->catalogue($archives);
        $editions = $records = 0;

        try {
            DB::table('archive_json_files')->where('category', 'election-archive')
                ->where('path', 'like', 'election-archive/%/extraction.json')
                ->orderBy('path_hash')->chunkById(5, function ($files) use ($catalogue, &$editions, &$records): void {
                    foreach ($files as $file) {
                        if (! preg_match('~^election-archive/([a-f0-9]{24})/extraction\.json$~', $file->path, $match)
                            || (int) $file->bytes !== strlen($file->body)
                            || ! hash_equals($file->sha256, hash('sha256', $file->body))) {
                            throw new RuntimeException('Extraction identity or checksum differs: '.$file->path);
                        }
                        $editionId = $match[1];
                        $edition = $catalogue[$editionId] ?? null;
                        $data = json_decode($file->body, true, 512, JSON_THROW_ON_ERROR);
                        if (! $edition || $edition['url'] !== ($data['source_url'] ?? null)
                            || $edition['kind'] !== ($data['kind'] ?? null)
                            || $edition['year'] !== ($data['year'] ?? null)
                            || ! preg_match('/^[a-f0-9]{64}$/', $data['source_sha256'] ?? '')
                            || ! is_array($data['records'] ?? null)) {
                            throw new RuntimeException('Extraction does not match the official edition catalogue: '.$file->path);
                        }
                        $manifestPath = 'election-archive/'.$editionId.'/manifest.json';
                        $manifestFile = DB::table('archive_json_files')->where('path_hash', hash('sha256', $manifestPath))->first();
                        if (! $manifestFile || $manifestFile->path !== $manifestPath
                            || (int) $manifestFile->bytes !== strlen($manifestFile->body)
                            || ! hash_equals($manifestFile->sha256, hash('sha256', $manifestFile->body))) {
                            throw new RuntimeException('Preserved source manifest is missing or differs: '.$manifestPath);
                        }
                        $manifest = json_decode($manifestFile->body, true, 512, JSON_THROW_ON_ERROR);
                        $sourceHashes = collect($manifest['files'] ?? [])->pluck('sha256', 'file');
                        if (($manifest['url'] ?? null) !== $edition['url']
                            || $sourceHashes->get($data['source_file'] ?? null) !== $data['source_sha256']) {
                            throw new RuntimeException('Extraction source does not match the manifest: '.$file->path);
                        }
                        foreach ($data['additional_sources'] ?? [] as $source) {
                            if ($sourceHashes->get($source['file'] ?? null) !== ($source['sha256'] ?? null)) {
                                throw new RuntimeException('Additional source does not match the manifest: '.$file->path);
                            }
                        }
                        $rows = [];
                        $seenCodes = [];
                        foreach ($data['records'] as $record) {
                            $code = $record['code'] ?? null;
                            $name = $record['constituency_name'] ?? $record['name'] ?? null;
                            $state = $record['state_name'] ?? $record['state_code'] ?? $edition['state'];
                            if (! is_int($code) || $code < 1 || $code > 999999 || isset($seenCodes[$code])
                                || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 160
                                || ($state !== null && (! is_string($state) || mb_strlen($state) > 100))
                                || ! in_array($record['status'] ?? null, ['validated', 'needs_review'], true)
                                || ! is_array($record['candidates'] ?? null) || count($record['candidates']) > 65535) {
                                throw new RuntimeException('Invalid or duplicate constituency identity: '.$file->path);
                            }
                            $seenCodes[$code] = true;
                            $rows[] = [
                                'edition_id' => $editionId,
                                'record_code' => $code,
                                'kind' => $edition['kind'],
                                'year' => $edition['year'],
                                'edition_label' => $edition['label'],
                                'state_label' => $state,
                                'constituency_name' => trim($name),
                                'status' => $record['status'],
                                'has_warning' => $record['status'] !== 'validated' || ! empty($record['error']),
                                'candidate_count' => count($record['candidates']),
                                'extraction_sha256' => $file->sha256,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                        if (! $this->option('check')) {
                            DB::transaction(function () use ($editionId, $rows): void {
                                DB::table('historical_constituency_index')->where('edition_id', $editionId)->delete();
                                foreach (array_chunk($rows, 200) as $chunk) {
                                    DB::table('historical_constituency_index')->insert($chunk);
                                }
                            });
                        }
                        $editions++;
                        $records += count($rows);
                    }
                }, 'path_hash');
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('check') ? 'Verified' : 'Indexed')." {$records} PC/AC constituency tables in {$editions} source editions. These are not accepted election contests.");

        return self::SUCCESS;
    }

    /** @return array<string, array{url: string, kind: string, year: int, label: string, state: ?string}> */
    private function catalogue(ElectionArchive $archives): array
    {
        $entries = [];
        foreach ($archives->catalogue() as $kind => $rows) {
            if (! in_array($kind, ['pc', 'ac'], true)) {
                continue;
            }
            foreach ($rows as [$label, $url]) {
                $entries[substr(hash('sha256', $url), 0, 24)] = [
                    'url' => $url, 'kind' => $kind, 'year' => (int) substr($label, 0, 4),
                    'label' => $kind === 'ac' ? $label.' Uttar Pradesh' : $label,
                    'state' => $kind === 'ac' ? 'Uttar Pradesh' : null,
                ];
            }
        }
        $national = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($national['entries'] as $entry) {
            $entries[substr(hash('sha256', $entry['url']), 0, 24)] = [
                'url' => $entry['url'], 'kind' => 'ac', 'year' => (int) $entry['year'],
                'label' => $entry['label'].' '.$entry['state'], 'state' => $entry['state'],
            ];
        }

        return $entries;
    }
}
