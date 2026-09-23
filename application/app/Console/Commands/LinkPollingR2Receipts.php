<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class LinkPollingR2Receipts extends Command
{
    protected $signature = 'polling:link-r2 {--receipts= : JSONL receipts verified after R2 upload}
        {--profile= : Tested R2 storage profile id} {--state= : Limit to one imported state}';

    protected $description = 'Link imported polling PDFs to verified R2 objects without reimporting page data';

    public function handle(): int
    {
        $path = $this->option('receipts');
        $profileId = filter_var($this->option('profile'), FILTER_VALIDATE_INT);
        $state = $this->option('state');
        if (! is_string($path) || ! is_file($path) || $profileId === false || $profileId < 1) {
            $this->error('Provide an existing --receipts JSONL file and a positive --profile id.');

            return self::FAILURE;
        }
        $profile = DB::table('pdf_storage_profiles')->where('id', $profileId)
            ->where('provider', 'r2')->whereNotNull('tested_at')->first();
        if (! $profile) {
            $this->error('Choose a tested R2 storage profile.');

            return self::FAILURE;
        }

        try {
            [$linked, $alreadyLinked, $missing] = DB::transaction(function () use ($path, $profile, $state): array {
                $linked = $alreadyLinked = $missing = 0;
                $seen = [];
                $receipts = new SplFileObject($path, 'r');
                foreach ($receipts as $lineNumber => $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $receipt = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $id = $receipt['source_id'] ?? null;
                    if (! is_string($id) || ! preg_match('/^[a-f0-9]{24}$/', $id) || isset($seen[$id])) {
                        throw new RuntimeException('Invalid or duplicate source id on receipt line '.($lineNumber + 1));
                    }
                    $seen[$id] = true;
                    if ($state !== null && ($receipt['state'] ?? null) !== $state) {
                        continue;
                    }
                    $document = DB::table('polling_source_documents')->where('id', $id)->first();
                    if (! $document) {
                        if ($state !== null) {
                            throw new RuntimeException('Imported document missing for receipt '.$id);
                        }
                        $missing++;

                        continue;
                    }
                    $metadata = json_decode($document->metadata, true, 512, JSON_THROW_ON_ERROR);
                    $folder = $metadata['folder'] ?? null;
                    $file = $metadata['file'] ?? null;
                    $sha256 = $document->sha256;
                    if (! is_string($folder) || ! preg_match('/^[a-f0-9]{24}$/', $folder)
                        || $file !== $sha256.'.pdf' || ($receipt['state'] ?? null) !== $document->state
                        || ($receipt['sha256'] ?? null) !== $sha256
                        || ($receipt['bucket'] ?? null) !== $profile->bucket
                        || ($receipt['endpoint'] ?? null) !== $profile->endpoint
                        || ($receipt['object_key'] ?? null) !== $profile->prefix.'/polling-station-sources/'.$folder.'/'.$file
                        || ! is_int($receipt['bytes'] ?? null) || $receipt['bytes'] < 5) {
                        throw new RuntimeException('Receipt does not match imported source '.$id);
                    }
                    $pdfPath = 'polling-station-sources/'.$folder.'/'.$file;
                    $pathHash = hash('sha256', $pdfPath);
                    $existing = DB::table('pdf_storage_files')->where('path_hash', $pathHash)->first();
                    if ($existing && ($existing->path !== $pdfPath || $existing->sha256 !== $sha256
                        || (int) $existing->bytes !== $receipt['bytes']
                        || ($existing->profile_id !== null && ((int) $existing->profile_id !== $profile->id
                            || $existing->object_key !== $receipt['object_key'])))) {
                        throw new RuntimeException('PDF inventory conflicts with receipt for '.$id);
                    }
                    if ($existing && (int) $existing->profile_id === $profile->id) {
                        $alreadyLinked++;

                        continue;
                    }
                    DB::table('pdf_storage_files')->upsert([[
                        'path_hash' => $pathHash, 'path' => $pdfPath, 'sha256' => $sha256,
                        'bytes' => $receipt['bytes'], 'profile_id' => $profile->id,
                        'object_key' => $receipt['object_key'], 'created_at' => now(), 'updated_at' => now(),
                    ]], ['path_hash'], ['path', 'sha256', 'bytes', 'profile_id', 'object_key', 'updated_at']);
                    $linked++;
                }

                return [$linked, $alreadyLinked, $missing];
            });
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked {$linked} PDFs; {$alreadyLinked} already linked; {$missing} receipts await imported documents.");

        return self::SUCCESS;
    }
}
