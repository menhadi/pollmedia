<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OfficialMarketCatalog
{
    public function all(): array
    {
        return Cache::remember('agmarknet-official-commodity-units:v1', 86400, function (): array {
            try {
                $data = Http::connectTimeout(3)->timeout(12)->get('https://api.agmarknet.gov.in/v1/commodities', ['page_size' => 1000]);
                if (! $data->successful() || $data->json('status') !== 'success' || ! is_array($data->json('data'))) {
                    return [];
                }
                $rows = [];
                foreach ($data->json('data') as $row) {
                    if (! is_string($row['cmdt_name'] ?? null) || ! in_array($row['price_unit_name'] ?? null, ['Quintal', 'Kilogram', 'Nos', 'Bundle'], true)) {
                        continue;
                    }
                    $hindi = collect($row['languages'] ?? [])->firstWhere('language', 2)['multi_lingual_cname'] ?? null;
                    $rows[(string) $row['id']] = ['name' => $row['cmdt_name'], 'unit' => $row['price_unit_name'],
                        'codes' => array_values(array_unique(array_map('strval', array_filter(array_merge([$row['comm_code'] ?? null], $row['cmdt_code_add'] ?? []), fn ($code) => $code !== null)))),
                        'hi' => is_string($hindi) && trim($hindi) && $hindi !== 'NULL' ? trim($hindi) : null];
                }
                usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

                return array_values($rows);
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function decorate(array $records): array
    {
        $catalog = $this->all();
        foreach ($records as &$record) {
            $matches = array_values(array_filter($catalog, fn ($row) => strcasecmp(trim($row['name']), trim($record['commodity'])) === 0));
            if (count($matches) === 1 && (empty($record['commodity_code']) || in_array((string) $record['commodity_code'], $matches[0]['codes'], true))) {
                $record['unit'] = $matches[0]['unit'];
                $record['commodity_hi'] = $matches[0]['hi'];
                $record['unit_basis'] = 'official_agmarknet_commodity_directory';
            }
        }

        return $records;
    }
}
