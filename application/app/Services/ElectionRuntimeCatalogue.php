<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ElectionRuntimeCatalogue
{
    private const KEY = 'election_runtime_catalogue';

    public function entries(bool $publishedOnly = true): array
    {
        if (! RequestSchema::hasTable('site_settings')) {
            return [];
        }
        $setting = PublicRequestCache::setting(self::KEY, JSON_THROW_ON_ERROR);
        $rows = $setting['present'] ? $setting['value'] : [];

        return array_values(array_filter($rows, fn (array $row): bool => ! $publishedOnly || $row['status'] === 'published'));
    }

    public function stage(array $entry, string $state): void
    {
        $this->change(function (array $rows) use ($entry, $state): array {
            $prior = $rows[$entry['url']] ?? null;
            if ($prior && ($prior['kind'] !== 'ac' || $prior['state'] !== $state || $prior['year'] !== $entry['year'])) {
                throw new RuntimeException('Official edition identity changed.');
            }
            $rows[$entry['url']] = $prior ?? [
                'kind' => 'ac', 'state' => $state, 'year' => (int) $entry['year'],
                'label' => $entry['year'].' '.$state, 'url' => $entry['url'], 'status' => 'staged',
            ];

            return $rows;
        });
    }

    public function publish(string $url): void
    {
        $this->change(function (array $rows) use ($url): array {
            if (! isset($rows[$url])) {
                throw new RuntimeException('Official edition is not staged.');
            }
            $rows[$url]['status'] = 'published';

            return $rows;
        });
    }

    private function change(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            DB::table('site_settings')->insertOrIgnore(['key' => self::KEY, 'value' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            $record = DB::table('site_settings')->where('key', self::KEY)->lockForUpdate()->first();
            $rows = collect(json_decode($record->value, true, 512, JSON_THROW_ON_ERROR))->keyBy('url')->all();
            $updated = array_values($change($rows));
            DB::table('site_settings')->where('key', self::KEY)->update(['value' => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
    }
}
