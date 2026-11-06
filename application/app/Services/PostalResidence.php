<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PostalResidence
{
    public function lookup(string $pincode): array
    {
        $empty = ['pincode' => $pincode, 'matches' => [], 'postal_regions' => [], 'retrieved_at' => null, 'reason' => 'source_unavailable'];
        $key = config('my_place.agmarknet.api_key');
        if (! $key) {
            return $empty;
        }

        $cacheKey = 'scheme-postal:v2:'.$pincode;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $result = (function () use ($key, $pincode, $empty): array {
            try {
                $response = Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'force_ip_resolve' => 'v4'])->get('https://api.data.gov.in/resource/5c2f62fe-5afa-4119-a499-fec9d604d5bd', ['api-key' => $key, 'format' => 'json', 'limit' => 1000, 'filters[pincode]' => $pincode]);
                $rows = $response->json('records');
                if (! $response->successful() || ! is_array($rows) || $response->json('status') === 'error') {
                    return $empty;
                }
                if (! $rows) {
                    return [...$empty, 'reason' => 'not_found'];
                }
                if (! is_numeric($response->json('total')) || (int) $response->json('total') !== count($rows)) {
                    return [...$empty, 'reason' => 'incomplete_source'];
                }
                $regions = [];
                foreach ($rows as $row) {
                    if (! is_array($row) || (string) ($row['pincode'] ?? '') !== $pincode || empty($row['statename']) || empty($row['district'])) {
                        return [...$empty, 'reason' => 'unverified_source'];
                    }
                    $region = ['state_name' => trim($row['statename']), 'district_name' => trim($row['district'])];
                    $regions[$this->normalise($region['state_name']).':'.$this->normalise($region['district_name'])] = $region;
                }
                $directory = app(MyPlaceDirectory::class);
                $matches = [];
                foreach ($regions as $region) {
                    $states = array_values(array_filter($directory->states(), fn (array $state): bool => $this->normalise($state['name']) === $this->normalise($region['state_name'])));
                    if (count($states) !== 1) {
                        continue;
                    }
                    $state = $states[0];
                    $districts = array_values(array_filter($directory->districts((string) $state['code']), fn (array $district): bool => $this->normalise($district['name']) === $this->normalise($region['district_name'])));
                    if (count($districts) === 1) {
                        $district = $districts[0];
                        $matches[$state['code'].':'.$district['code']] = ['state' => (string) $state['code'], 'district' => (string) $district['code'], 'state_name' => $state['name'], 'district_name' => $district['name']];
                    }
                }

                return ['pincode' => $pincode, 'matches' => array_values($matches), 'postal_regions' => array_values($regions), 'retrieved_at' => now()->toIso8601String(), 'reason' => count($regions) === 1 && count($matches) === 1 ? null : (count($regions) > 1 ? 'ambiguous' : 'manual_confirmation')];
            } catch (\Throwable) {
                return $empty;
            }
        })();
        if ($result['reason'] !== 'source_unavailable') {
            Cache::put($cacheKey, $result, 3600);
        }

        return $result;
    }

    private function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($name)));
    }
}
