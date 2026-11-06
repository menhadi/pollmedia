<?php

namespace App\Console\Commands;

use App\Services\JjmWaterSnapshot;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportJjmWaterSnapshot extends Command
{
    protected $signature = 'water:import-jjm {snapshot} {original}';

    protected $description = 'Import a dated Pilibhit JJM source report without creating unverified village links';

    public function handle(): int
    {
        $payload = file_get_contents($this->argument('snapshot'));
        $snapshot = JjmWaterSnapshot::validate(json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        if ($snapshot['source_key'] !== 'jjm-pilibhit-j1' || ! hash_equals($snapshot['original_sha256'], hash_file('sha256', $this->argument('original')))) {
            $this->error('Source key or original report checksum mismatch.');

            return self::FAILURE;
        }
        $release = DB::transaction(function () use ($snapshot, $payload): int {
            $source = DB::table('data_sources')->where('key', $snapshot['source_key'])->first();
            $sourceId = $source?->id ?? DB::table('data_sources')->insertGetId([
                'key' => $snapshot['source_key'], 'publisher' => $snapshot['publisher'], 'url' => $snapshot['source_url'],
                'access_mode' => 'public', 'reuse_status' => 'review_required', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $version = 'sha256:'.$snapshot['original_sha256'];
            $existing = DB::table('source_releases')->where('data_source_id', $sourceId)->where('version_key', $version)->first();
            if ($existing) {
                if (json_decode($existing->payload, true) !== json_decode($payload, true)) {
                    throw new \UnexpectedValueException('An existing report version cannot be overwritten.');
                }

                return $existing->id;
            }

            return DB::table('source_releases')->insertGetId([
                'data_source_id' => $sourceId, 'version_key' => $version, 'sha256' => $snapshot['original_sha256'],
                'url' => $snapshot['source_url'], 'published_on' => null,
                'retrieved_at' => Carbon::parse($snapshot['retrieved_at'])->utc()->format('Y-m-d H:i:s'),
                'status' => 'accepted', 'payload' => $payload, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->info('JJM source report release '.$release.'; 1291 native JJM records; village crosswalk pending.');

        return self::SUCCESS;
    }
}
