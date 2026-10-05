<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportSirRecords extends Command
{
    protected $signature = 'sir:import-records {file} {--sha256=}';

    protected $description = 'Import a checksummed, reviewed SIR record extraction';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || filesize($file) > 50000000 || ! preg_match('/^[a-f0-9]{64}$/', $this->option('sha256') ?? '') || ! hash_equals($this->option('sha256'), hash_file('sha256', $file))) {
            $this->error('Missing file, invalid size or checksum mismatch.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'edition_key' => 'required|regex:/^[a-f0-9]{64}$/', 'state_code' => 'required|string|max:10',
                'ac_code' => 'required|string|max:10', 'ac_name' => 'required|string|max:255',
                'year' => 'nullable|integer|between:1800,2100', 'edition' => 'required|string|max:255',
                'document_date' => 'required|date_format:Y-m-d', 'source_url' => 'required|url:https|max:2000',
                'records' => 'required|array|min:1|max:100000', 'records.*.part' => 'required|integer|min:1',
                'records.*.station' => 'required|string|max:255', 'records.*.serial' => 'required|integer|min:1',
                'records.*.name' => 'required|string|max:255', 'records.*.relative_name' => 'required|string|max:255',
                'records.*.relationship' => 'required|in:Father,Mother,Husband,Wife,Other',
                'records.*.pdf_page' => 'required|integer|min:1',
            ])->validate();
            $host = strtolower(parse_url($data['source_url'], PHP_URL_HOST) ?? '');
            if (! (str_ends_with($host, '.gov.in') || str_ends_with($host, '.nic.in'))) {
                throw new \RuntimeException('An official government source URL is required.');
            }
            $keys = [];
            $rows = [];
            foreach ($data['records'] as $record) {
                $key = $record['part'].'|'.$record['serial'];
                if (isset($keys[$key])) {
                    throw new \RuntimeException('Duplicate part/serial in extraction.');
                }
                $keys[$key] = true;
                $rows[] = array_merge(array_intersect_key($data, array_flip(['edition_key', 'state_code', 'ac_code', 'ac_name', 'year', 'edition', 'document_date', 'source_url'])), array_intersect_key($record, array_flip(['part', 'station', 'serial', 'name', 'relative_name', 'relationship', 'pdf_page'])));
            }
            DB::transaction(function () use ($data, $rows) {
                DB::table('sir_records')->where('edition_key', $data['edition_key'])->delete();
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('sir_records')->insert($chunk);
                }
            });
            $this->info('Imported '.count($rows).' SIR records.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
