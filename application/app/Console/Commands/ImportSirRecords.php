<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class ImportSirRecords extends Command
{
    protected $signature = 'sir:import-records {file} {--sha256=} {--pdf=}';

    protected $description = 'Import a checksummed, reviewed SIR record extraction';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('Import file is missing or unreadable: '.$file.'. Upload the extracted JSON first; git pull only transfers code.');

            return self::FAILURE;
        }
        if (filesize($file) > 50000000) {
            $this->error('Import file exceeds the 50 MB limit.');

            return self::FAILURE;
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $this->option('sha256') ?? '') || ! hash_equals($this->option('sha256'), hash_file('sha256', $file))) {
            $this->error('Checksum mismatch. Use the exact extracted JSON and its supplied SHA-256.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'edition_key' => 'required|regex:/^[a-f0-9]{64}$/', 'state_code' => 'required|string|max:10',
                'ac_code' => 'required|string|max:10', 'ac_name' => 'required|string|max:255',
                'state_name' => 'nullable|string|max:255',
                'pc_code' => 'nullable|required_with:pc_name,pc_source_url|string|max:10',
                'pc_name' => 'nullable|required_with:pc_code,pc_source_url|string|max:255',
                'pc_source_url' => 'nullable|required_with:pc_code,pc_name|url:https|max:2000',
                'year' => 'nullable|integer|between:1800,2100', 'edition' => 'required|string|max:255',
                'document_type' => 'nullable|in:electoral_roll,uncollected_forms',
                'source_landing_url' => 'nullable|url:https|max:2000',
                'pdf_sha256' => 'nullable|regex:/^[a-f0-9]{64}$/',
                'printed_electors' => 'nullable|integer|min:1',
                'held_rows' => 'nullable|array',
                'document_date' => 'required|date_format:Y-m-d', 'source_url' => 'required|url:https|max:2000',
                'records' => 'required|array|min:1|max:100000', 'records.*.part' => 'required|integer|min:1',
                'records.*.station' => 'required|string|max:255', 'records.*.serial' => 'required|integer|min:1',
                'records.*.name' => 'required|string|max:255', 'records.*.relative_name' => 'required|string|max:255',
                'records.*.relationship' => 'required|in:Father,Mother,Husband,Wife,Other',
                'records.*.pdf_page' => 'required|integer|min:1',
                'records.*.extraction_status' => 'nullable|in:reviewed,ocr_candidate',
            ])->validate();
            $data['document_type'] ??= 'uncollected_forms';
            if (isset($data['printed_electors']) && count($data['records']) + count($data['held_rows'] ?? []) !== (int) $data['printed_electors']) {
                throw new \RuntimeException('Indexed and held records must reconcile with the printed elector total.');
            }
            $host = strtolower(parse_url($data['source_url'], PHP_URL_HOST) ?? '');
            $landingHost = strtolower(parse_url($data['source_landing_url'] ?? '', PHP_URL_HOST) ?? '');
            $officialLanding = str_ends_with($landingHost, '.gov.in') || str_ends_with($landingHost, '.nic.in');
            if (! (str_ends_with($host, '.gov.in') || str_ends_with($host, '.nic.in') || ($host === 'drive.google.com' && $officialLanding))) {
                throw new \RuntimeException('An official government source URL is required.');
            }
            if (! empty($data['pc_source_url'])) {
                $pcHost = strtolower(parse_url($data['pc_source_url'], PHP_URL_HOST) ?? '');
                if (! (str_ends_with($pcHost, '.gov.in') || str_ends_with($pcHost, '.nic.in'))) {
                    throw new \RuntimeException('PC grouping requires an official mapping source URL.');
                }
            }
            $keys = [];
            $rows = [];
            foreach ($data['records'] as $record) {
                $key = $record['part'].'|'.$record['serial'];
                if (isset($keys[$key])) {
                    throw new \RuntimeException('Duplicate part/serial in extraction.');
                }
                $keys[$key] = true;
                $rows[] = array_merge(array_intersect_key($data, array_flip(['edition_key', 'state_code', 'state_name', 'pc_code', 'pc_name', 'pc_source_url', 'ac_code', 'ac_name', 'year', 'edition', 'document_date', 'document_type', 'source_url', 'source_landing_url', 'pdf_sha256'])), array_intersect_key($record, array_flip(['part', 'station', 'serial', 'name', 'relative_name', 'relationship', 'pdf_page', 'extraction_status'])));
            }
            if ($data['document_type'] === 'electoral_roll') {
                $pdf = $this->option('pdf');
                if (! $pdf || ! is_file($pdf) || filesize($pdf) > 100000000 || empty($data['pdf_sha256']) || ! hash_equals($data['pdf_sha256'], hash_file('sha256', $pdf)) || file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
                    throw new \RuntimeException('A matching original PDF is required for electoral-roll imports. Supply --pdf=PATH.');
                }
                $folder = storage_path('app/private/sir-pdfs');
                File::ensureDirectoryExists($folder);
                $target = $folder.'/'.$data['pdf_sha256'].'.pdf';
                if (is_file($target) && ! hash_equals($data['pdf_sha256'], hash_file('sha256', $target))) {
                    throw new \RuntimeException('Preserved source PDF checksum mismatch.');
                }
                if (! is_file($target) && ! File::copy($pdf, $target)) {
                    throw new \RuntimeException('Unable to preserve original source PDF.');
                }
            }
            DB::transaction(function () use ($data, $rows) {
                DB::table('sir_records')->where('edition_key', $data['edition_key'])->delete();
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('sir_records')->insert($chunk);
                }
                DB::table('site_settings')->updateOrInsert(['key' => 'sir-roll-meta:'.$data['edition_key']], ['value' => json_encode(['indexed_records' => count($rows), 'printed_electors' => $data['printed_electors'] ?? null, 'held_records_count' => count($data['held_rows'] ?? [])], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            });
            $this->info('Imported '.count($rows).' SIR records.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
