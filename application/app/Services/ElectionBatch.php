<?php

namespace App\Services;

use App\Jobs\ProcessElectionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ElectionBatch
{
    public function reference(int $year): array
    {
        abort_unless(in_array($year, [2012, 2017, 2022], true), 422, 'Unsupported election edition.');

        return $year === 2022
            ? json_decode(file_get_contents(database_path('fixtures/pilibhit-assembly.json')), true, 512, JSON_THROW_ON_ERROR)[0]
            : json_decode(file_get_contents(database_path('fixtures/up-assembly-'.$year.'-source.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function create(int $user, int $year = 2022): string
    {
        $reference = $this->reference($year);
        $extension = $year === 2012 ? 'pdf' : 'xlsx';
        $detail = base_path('../pilot/raw/elections/'.$year.'-up-detailed.'.$extension);
        $summary = base_path('../pilot/raw/elections/'.$year.'-up-summary.'.$extension);
        foreach ([$detail => $reference['sha256'], $summary => $reference['totals_sha256']] as $path => $hash) {
            abort_unless(is_file($path) && hash_equals($hash, hash_file('sha256', $path)), 422, 'The selected verified UP report pair is missing or changed. This adapter requires the archived official edition.');
        }
        $fingerprint = hash('sha256', 'eci-up-'.$year.'-v1'.$reference['sha256'].$reference['totals_sha256']);

        return DB::transaction(function () use ($user, $reference, $detail, $summary, $fingerprint, $year, $extension): string {
            $existing = DB::table('election_import_batches')->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = (string) Str::ulid();
            $detailPath = 'election-batches/'.$id.'/detailed.'.$extension;
            $summaryPath = 'election-batches/'.$id.'/summary.'.$extension;
            foreach ([$detailPath => $detail, $summaryPath => $summary] as $destination => $source) {
                $stream = fopen($source, 'rb');
                try {
                    abort_unless(Storage::disk('local')->put($destination, $stream), 500, 'Could not archive the official workbook.');
                } finally {
                    fclose($stream);
                }
            }
            DB::table('election_import_batches')->insert(['id' => $id, 'year' => $year, 'adapter' => 'eci-up-'.$year.'-v1', 'fingerprint' => $fingerprint, 'detail_path' => $detailPath, 'summary_path' => $summaryPath, 'detail_sha256' => $reference['sha256'], 'summary_sha256' => $reference['totals_sha256'], 'source_url' => $reference['url'], 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
            ProcessElectionBatch::dispatch($id)->onConnection('database')->onQueue('imports')->afterCommit();

            return $id;
        });
    }

    public function extract(object $batch): array
    {
        $process = new Process([config('imports.python'), base_path(match ($batch->year) {
            2012 => '../pilot/extract_state_election_2012.py', 2017 => '../pilot/extract_state_election_2017.py', default => '../pilot/extract_state_election.py'
        }), Storage::disk('local')->path($batch->detail_path), Storage::disk('local')->path($batch->summary_path)]);
        $process->setTimeout(45);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || isset($result['error'])) {
            throw ValidationException::withMessages(['batch' => $result['error'] ?? 'Statewide extraction failed.']);
        }

        return $result;
    }

    public function verifyFiles(object $batch): void
    {
        foreach ([$batch->detail_path => $batch->detail_sha256, $batch->summary_path => $batch->summary_sha256] as $path => $hash) {
            abort_unless(Storage::disk('local')->exists($path) && hash_equals($hash, hash_file('sha256', Storage::disk('local')->path($path))), 422, 'Archived workbook integrity check failed.');
        }
    }

    public function process(string $id): void
    {
        $claimed = DB::table('election_import_batches')->where('id', $id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $batch = DB::table('election_import_batches')->find($id);
        $this->verifyFiles($batch);
        $rows = $this->extract($batch);
        abort_unless(array_column($rows, 'code') === range(1, 403), 422, 'The batch must contain exactly one record for every UP AC code 1-403.');
        $this->verifyFiles($batch);
        DB::transaction(function () use ($rows, $batch): void {
            $ready = 0;
            $invalid = 0;
            foreach ($rows as $row) {
                $error = $row['error'] ?? null;
                $payload = $row['payload'] ?? null;
                if (! $error) {
                    try {
                        if (! is_array($payload) || ($payload['code'] ?? null) !== $row['code'] || ($payload['year'] ?? null) !== $batch->year || ($payload['name'] ?? null) !== $row['name']) {
                            throw ValidationException::withMessages(['batch' => 'Extracted constituency identity is inconsistent.']);
                        }
                        app(ElectionPublication::class)->validate($payload);
                    } catch (ValidationException $exception) {
                        $error = implode(' ', $exception->validator->errors()->all());
                    }
                }
                $status = $error ? 'invalid' : 'ready';
                $error ? $invalid++ : $ready++;
                DB::table('election_import_batch_rows')->updateOrInsert(['batch_id' => $batch->id, 'code' => $row['code']], ['name' => $row['name'], 'status' => $status, 'payload' => $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : null, 'error' => $error]);
            }
            DB::table('election_import_batches')->where('id', $batch->id)->update(['status' => $invalid ? 'needs_attention' : 'ready', 'ready_count' => $ready, 'invalid_count' => $invalid, 'error' => null, 'finished_at' => now(), 'updated_at' => now()]);
        });
    }

    public function fail(string $id, string $message): void
    {
        DB::table('election_import_batches')->where('id', $id)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'error' => $message, 'finished_at' => now(), 'updated_at' => now()]);
    }

    public function retry(string $id): void
    {
        DB::transaction(function () use ($id): void {
            abort_unless(DB::table('election_import_batches')->where('id', $id)->where('status', 'failed')->update(['status' => 'queued', 'error' => null, 'started_at' => null, 'finished_at' => null, 'updated_at' => now()]), 409, 'Only a failed batch can be retried.');
            ProcessElectionBatch::dispatch($id)->onConnection('database')->onQueue('imports')->afterCommit();
        });
    }
}
