<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class LinkArchiveOriginalsR2Receipts extends Command
{
    protected $signature = 'archive:link-originals {--receipts= : Verified source-original receipt JSONL}
        {--sha256= : Expected SHA-256 of the complete receipt file} {--profile= : Tested R2 storage profile id}';

    protected $description = 'Register verified archived HTML, workbook and ZIP originals in private R2';

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
                    $sourcePath = $receipt['source_id'] ?? null;
                    $category = $receipt['category'] ?? null;
                    $sha256 = $receipt['sha256'] ?? null;
                    $url = $receipt['source_url'] ?? null;
                    $scope = $receipt['url_scope'] ?? null;
                    if (! is_string($sourcePath)
                        || ! preg_match('~^(election-archive|election-by-elections)/[a-f0-9]{24}/[A-Za-z0-9_.-]+\.(?:html|xls|xlsx|zip)$~', $sourcePath)
                        || str_contains($sourcePath, '..') || isset($seen[$sourcePath])
                        || ! is_string($category) || ! str_starts_with($sourcePath, $category.'/')
                        || ! is_string($sha256) || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
                        || ! is_int($receipt['bytes'] ?? null) || $receipt['bytes'] < 1
                        || ! is_string($url) || ! preg_match('~^https://(?:old\.|www\.)?eci\.gov\.in/~', $url)
                        || ! in_array($scope, ['download', 'page', 'collection'], true)
                        || ($receipt['bucket'] ?? null) !== $profile->bucket
                        || ($receipt['endpoint'] ?? null) !== $profile->endpoint
                        || ($receipt['object_key'] ?? null) !== $profile->prefix.'/archive-source-originals/'.$sourcePath) {
                        throw new RuntimeException('Unsafe or inconsistent archived original receipt line '.($lineNumber + 1));
                    }
                    $seen[$sourcePath] = true;
                    $pathHash = hash('sha256', $sourcePath);
                    $prior = DB::table('archive_original_files')->where('path_hash', $pathHash)->first();
                    if ($prior && ($prior->path !== $sourcePath || $prior->category !== $category
                        || $prior->sha256 !== $sha256 || (int) $prior->bytes !== $receipt['bytes']
                        || (int) $prior->profile_id !== $profile->id || $prior->object_key !== $receipt['object_key']
                        || $prior->source_url !== $url || $prior->url_scope !== $scope)) {
                        throw new RuntimeException('Archived original inventory conflicts with receipt: '.$sourcePath);
                    }
                    if (DB::table('archive_original_files')->where('profile_id', $profile->id)
                        ->where('object_key', $receipt['object_key'])->where('path_hash', '!=', $pathHash)->exists()) {
                        throw new RuntimeException('R2 object is already linked to another original: '.$sourcePath);
                    }
                    if ($prior) {
                        $alreadyLinked++;

                        continue;
                    }
                    DB::table('archive_original_files')->insert([
                        'path_hash' => $pathHash, 'path' => $sourcePath, 'category' => $category,
                        'sha256' => $sha256, 'bytes' => $receipt['bytes'],
                        'profile_id' => $profile->id, 'object_key' => $receipt['object_key'],
                        'source_url' => $url, 'url_scope' => $scope,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $linked++;
                }

                return [$linked, $alreadyLinked];
            });
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked {$linked} archived originals; {$alreadyLinked} already linked.");

        return self::SUCCESS;
    }
}
