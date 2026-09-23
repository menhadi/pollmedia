<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class LinkArchivedR2Receipts extends Command
{
    protected $signature = 'archive:link-r2 {--receipts= : Verified archived PDF receipt JSONL}
        {--sha256= : Expected SHA-256 of the complete receipt file} {--profile= : Tested R2 storage profile id}';

    protected $description = 'Register verified archived PDFs and their official source evidence in private R2';

    public function handle(): int
    {
        $path = $this->option('receipts');
        $expected = $this->option('sha256');
        $profileId = filter_var($this->option('profile'), FILTER_VALIDATE_INT);
        if (! is_string($path) || ! is_file($path) || ! is_string($expected)
            || ! preg_match('/^[a-f0-9]{64}$/', $expected)
            || ! hash_equals($expected, hash_file('sha256', $path))
            || $profileId === false || $profileId < 1) {
            $this->error('Provide checksum-verified --receipts and a positive --profile id.');

            return self::FAILURE;
        }
        $profile = DB::table('pdf_storage_profiles')->where('id', $profileId)
            ->where('provider', 'r2')->whereNotNull('tested_at')->first();
        if (! $profile) {
            $this->error('Choose a tested R2 storage profile.');

            return self::FAILURE;
        }

        try {
            [$linked, $alreadyLinked] = DB::transaction(function () use ($path, $profile): array {
                $linked = $alreadyLinked = 0;
                $seen = [];
                $receipts = new SplFileObject($path, 'r');
                foreach ($receipts as $lineNumber => $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $receipt = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $pdfPath = $receipt['source_id'] ?? null;
                    $category = $receipt['category'] ?? null;
                    $sha256 = $receipt['sha256'] ?? null;
                    $url = $receipt['source_url'] ?? null;
                    $evidence = $receipt['recovery_evidence'] ?? null;
                    if (! is_string($pdfPath) || ! preg_match('~^(election-archive|election-by-elections|census-archive)/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+\.pdf$~', $pdfPath)
                        || str_contains($pdfPath, '..') || isset($seen[$pdfPath])
                        || ! is_string($category) || ! str_starts_with($pdfPath, $category.'/')
                        || ! is_string($sha256) || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
                        || ! is_int($receipt['bytes'] ?? null) || $receipt['bytes'] < 5
                        || ! is_string($url) || ! preg_match('~^https://(?:old\.|www\.)?(?:eci\.gov\.in|censusindia\.gov\.in)/~', $url)
                        || ($evidence !== null && (! is_array($evidence) || $category !== 'election-archive'))
                        || ($receipt['bucket'] ?? null) !== $profile->bucket
                        || ($receipt['endpoint'] ?? null) !== $profile->endpoint
                        || ($receipt['object_key'] ?? null) !== $profile->prefix.'/'.$pdfPath) {
                        throw new RuntimeException('Unsafe or inconsistent archived receipt line '.($lineNumber + 1));
                    }
                    $seen[$pdfPath] = true;
                    $pathHash = hash('sha256', $pdfPath);
                    $prior = DB::table('pdf_storage_files')->where('path_hash', $pathHash)->first();
                    if ($prior && ($prior->path !== $pdfPath || $prior->sha256 !== $sha256
                        || (int) $prior->bytes !== $receipt['bytes']
                        || ($prior->profile_id === null && $prior->object_key !== null)
                        || ($prior->profile_id !== null && ((int) $prior->profile_id !== $profile->id
                            || $prior->object_key !== $receipt['object_key'])))) {
                        throw new RuntimeException('PDF inventory conflicts with archived receipt: '.$pdfPath);
                    }
                    if (DB::table('pdf_storage_files')->where('profile_id', $profile->id)
                        ->where('object_key', $receipt['object_key'])->where('path_hash', '!=', $pathHash)->exists()) {
                        throw new RuntimeException('R2 object is already linked to another PDF: '.$pdfPath);
                    }
                    $source = DB::table('archive_pdf_sources')->where('path_hash', $pathHash)->first();
                    $evidenceBody = $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR);
                    if ($source && ($source->category !== $category || $source->source_url !== $url
                        || ($source->recovery_evidence === null ? null : json_decode($source->recovery_evidence, true, 512, JSON_THROW_ON_ERROR)) != $evidence)) {
                        throw new RuntimeException('Official source evidence conflicts: '.$pdfPath);
                    }
                    if ($prior && (int) $prior->profile_id === $profile->id && $source) {
                        $alreadyLinked++;

                        continue;
                    }
                    DB::table('pdf_storage_files')->upsert([[
                        'path_hash' => $pathHash, 'path' => $pdfPath, 'sha256' => $sha256,
                        'bytes' => $receipt['bytes'], 'profile_id' => $profile->id,
                        'object_key' => $receipt['object_key'], 'created_at' => now(), 'updated_at' => now(),
                    ]], ['path_hash'], ['path', 'sha256', 'bytes', 'profile_id', 'object_key', 'updated_at']);
                    if (! $source) {
                        DB::table('archive_pdf_sources')->insert([
                            'path_hash' => $pathHash, 'category' => $category, 'source_url' => $url,
                            'recovery_evidence' => $evidenceBody, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $linked++;
                }

                return [$linked, $alreadyLinked];
            });
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked {$linked} archived PDFs; {$alreadyLinked} already linked.");

        return self::SUCCESS;
    }
}
