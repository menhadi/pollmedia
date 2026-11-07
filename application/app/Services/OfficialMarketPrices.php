<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OfficialMarketPrices
{
    public function forPlace(array $context, ?string $commodity = null, int $offset = 0): array
    {
        $empty = ['records' => [], 'reason' => 'not_configured', 'retrieved_at' => null, 'source_updated_at' => null];
        $key = config('my_place.agmarknet.api_key');
        $resource = config('my_place.agmarknet.resource_id');
        if (! $key || ! is_string($resource) || ! Str::isUuid($resource)) {
            return $empty;
        }
        $sourceState = $this->sourceStateName($context);
        $scope = $this->reportScope($context, $commodity, $offset);

        return Cache::remember('my-place-markets:v5:'.$scope, 1800, function () use ($context, $key, $resource, $empty, $commodity, $offset, $scope, $sourceState): array {
            try {
                $sources = array_values(array_unique([$resource, '35985678-0d79-46b4-9ed6-6f13308a1d24']));
                $successful = 0;
                $used = [];
                $latest = [];
                $next = null;
                $updated = null;
                foreach ($sources as $source) {
                    $archive = $source === '35985678-0d79-46b4-9ed6-6f13308a1d24';
                    $label = $archive ? 'historical' : 'daily';
                    $district = $archive && $context['state']['code'] === '9' && $context['district']['code'] === '173' ? 'Pillibhit' : $context['district']['name'];
                    try {
                        $response = Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'force_ip_resolve' => 'v4'])->get('https://api.data.gov.in/resource/'.$source, array_merge([
                            'api-key' => $key, 'format' => 'json', 'limit' => 1000, 'offset' => $offset,
                            'filters['.($archive ? 'State' : 'state').']' => $sourceState,
                            'filters['.($archive ? 'District' : 'district').']' => $district,
                        ], $archive ? ['sort[Arrival_Date]' => 'desc'] : [], $commodity ? ['filters['.($archive ? 'Commodity' : 'commodity').']' => $commodity] : []));
                    } catch (\Throwable) {
                        continue;
                    }
                    if (! $response->successful() || ! is_array($response->json('records')) || $response->json('status') === 'error') {
                        continue;
                    }
                    $successful++;
                    $raw = $response->json('records');
                    $rows = array_map(fn ($row) => is_array($row) ? array_change_key_case($row, CASE_LOWER) : [], $raw);
                    foreach ($this->verifiedRows($rows, $context) as $record) {
                        if ($commodity && strcasecmp($record['commodity'], $commodity) !== 0) {
                            continue;
                        }
                        $identity = json_encode([$record['market'], $record['commodity'], $record['variety'], $record['grade']]);
                        $record['source'] = $label;
                        if (! isset($latest[$identity]) || $record['date'] > $latest[$identity]['date']) {
                            $latest[$identity] = $record;
                        }
                        $used[$label] = true;
                    }
                    $date = $response->json('updated_date');
                    if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $date)) {
                        $updated = $updated === null || $date > $updated ? $date : $updated;
                    }
                    if (count($raw) && $offset + count($raw) < (int) $response->json('total')) {
                        $next = max($next ?? 0, $offset + count($raw));
                    }
                }
                $records = array_values($latest);
                usort($records, fn ($a, $b) => strcmp($b['date'], $a['date']));
                $records = app(OfficialMarketCatalog::class)->decorate($records);
                $result = ['records' => $records, 'reason' => $records ? null : 'no_matching_records', 'retrieved_at' => now()->toIso8601String(),
                    'source_updated_at' => $updated, 'source' => count($used) > 1 ? 'mixed' : (array_key_first($used) ?? 'historical'),
                    'latest_reported_date' => $records[0]['date'] ?? null, 'next_offset' => $next,
                    'partial_sources' => $successful < count($sources), 'catalog' => app(OfficialMarketCatalog::class)->all()];
                if ($records) {
                    Cache::put('my-place-last-prices:v4:'.$scope, $result, now()->addDays(7));

                    return $result;
                }
                $previous = Cache::get('my-place-last-prices:v4:'.$scope);

                return $previous ? array_replace($previous, ['cached_fallback' => true]) : ($successful ? $result : array_replace($empty, ['reason' => 'source_unavailable']));
            } catch (\Throwable) {
                return array_replace($empty, ['reason' => 'source_unavailable']);
            }
        });
    }

    public function verifiedRows(array $rows, array $context, bool $latestOnly = true): array
    {
        $records = [];
        $same = fn ($a, $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['arrival_date'] ?? null)) {
                continue;
            }
            $districtMatches = $same($row['district'] ?? '', $context['district']['name']) || ($context['state']['code'] === '9' && $context['district']['code'] === '173' && $same($row['district'] ?? '', 'Pillibhit'));
            $stateMatches = $same($row['state'] ?? '', $context['state']['name']) || ((string) $context['state']['code'] === '22' && $same($row['state'] ?? '', 'Chattisgarh'));
            if (! $stateMatches || ! $districtMatches) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $row['arrival_date'] ?? '');
            if (! $date || $date->format('d/m/Y') !== ($row['arrival_date'] ?? '') || $date->format('Y-m-d') > now('Asia/Kolkata')->toDateString()) {
                continue;
            }
            $min = $row['min_price'] ?? null;
            $modal = $row['modal_price'] ?? null;
            $max = $row['max_price'] ?? null;
            $min = is_numeric($min) && $min > 0 ? (float) $min : null;
            $max = is_numeric($max) && $max > 0 ? (float) $max : null;
            if (! is_numeric($modal) || $modal <= 0 || ($min !== null && $modal < $min) || ($max !== null && $modal > $max) || empty($row['market']) || empty($row['commodity'])) {
                continue;
            }
            $records[] = ['market' => (string) $row['market'], 'commodity' => (string) $row['commodity'], 'variety' => (string) ($row['variety'] ?? ''),
                'grade' => (string) ($row['grade'] ?? ''), 'commodity_code' => $row['commodity_code'] ?? null,
                'unit' => $row['unit'] ?? null, 'min' => $min, 'modal' => (float) $modal, 'max' => $max, 'date' => $date->format('Y-m-d')];
        }
        usort($records, fn ($a, $b) => strcmp($b['date'], $a['date']));
        if (! $latestOnly) {
            return $records;
        }
        $latest = [];
        foreach ($records as $record) {
            $identity = json_encode([$record['market'], $record['commodity'], $record['variety'], $record['grade']]);
            $latest[$identity] ??= $record;
        }

        return array_values($latest);
    }

    public function history(array $context, string $commodity, string $variety, string $grade): array
    {
        $sourceState = $this->sourceStateName($context);
        $scopeParts = [$context['state']['code'], $context['district']['code'], $commodity, $variety, $grade, now('Asia/Kolkata')->format('Y-m-d')];
        if ($sourceState !== $context['state']['name']) {
            $scopeParts[] = $sourceState;
        }
        $cacheKey = 'market-history:v2:'.hash('sha256', json_encode($scopeParts));

        return Cache::remember($cacheKey, 3600, function () use ($context, $commodity, $variety, $grade, $sourceState): array {
            $empty = ['months' => [], 'reason' => 'source_unavailable', 'comparison' => null];
            $key = config('my_place.agmarknet.api_key');
            if (! $key) {
                return $empty;
            }
            $today = now('Asia/Kolkata');
            $start = $today->copy()->startOfMonth()->subYear();
            $district = $context['state']['code'] === '9' && $context['district']['code'] === '173' ? 'Pillibhit' : $context['district']['name'];
            $all = [];
            $complete = false;
            try {
                for ($offset = 0; $offset < 5000; $offset += 1000) {
                    $response = Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'force_ip_resolve' => 'v4'])->get('https://api.data.gov.in/resource/35985678-0d79-46b4-9ed6-6f13308a1d24', [
                        'api-key' => $key, 'format' => 'json', 'limit' => 1000, 'offset' => $offset, 'sort[Arrival_Date]' => 'desc',
                        'filters[State]' => $sourceState, 'filters[District]' => $district,
                        'filters[Commodity]' => $commodity, 'filters[Variety]' => $variety, 'filters[Grade]' => $grade,
                    ]);
                    if (! $response->successful() || $response->json('status') === 'error' || ! is_array($response->json('records'))) {
                        return $empty;
                    }
                    $raw = $response->json('records');
                    $rows = $this->verifiedRows(array_map(fn ($row) => is_array($row) ? array_change_key_case($row, CASE_LOWER) : [], $raw), $context, false);
                    $passedStart = false;
                    foreach ($rows as $row) {
                        if ($row['date'] < $start->toDateString()) {
                            $passedStart = true;

                            continue;
                        }
                        if (strcasecmp($row['commodity'], $commodity) || strcasecmp($row['variety'], $variety) || strcasecmp($row['grade'], $grade)) {
                            continue;
                        }
                        $identity = json_encode([$row['market'], $row['date']]);
                        if (array_key_exists($identity, $all) && ($all[$identity] === null || $all[$identity]['modal'] !== $row['modal'])) {
                            $all[$identity] = null;
                        } else {
                            $all[$identity] = $row;
                        }
                    }
                    if ($passedStart || ! count($raw) || $offset + count($raw) >= (int) $response->json('total')) {
                        $complete = true;
                        break;
                    }
                }
            } catch (\Throwable) {
                return $empty;
            }
            if (! $complete) {
                return array_replace($empty, ['reason' => 'history_limit_reached']);
            }
            $records = app(OfficialMarketCatalog::class)->decorate(array_values(array_filter($all)));
            $units = array_unique(array_column($records, 'unit'));
            if (count($units) !== 1 || ! $units[0]) {
                return array_replace($empty, ['reason' => $records ? 'unit_unverified' : 'no_matching_records']);
            }
            $buckets = [];
            foreach ($records as $row) {
                $month = substr($row['date'], 0, 7);
                $buckets[$month][] = $row;
            }
            $summarize = fn ($rows) => ['value' => count($rows) ? round(array_sum(array_column($rows, 'modal')) / count($rows), 2) : null, 'observations' => count($rows), 'days' => count(array_unique(array_column($rows, 'date')))];
            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $month = $today->copy()->startOfMonth()->subMonths($i)->format('Y-m');
                $months[] = ['month' => $month] + $summarize($buckets[$month] ?? []);
            }
            $currentMonth = $today->format('Y-m');
            $priorMonth = $today->copy()->subYear()->format('Y-m');
            $current = $summarize($buckets[$currentMonth] ?? []);
            $prior = $summarize(array_values(array_filter($buckets[$priorMonth] ?? [], fn ($row) => (int) substr($row['date'], 8, 2) <= $today->day)));

            return ['months' => $months, 'reason' => null, 'unit' => $units[0], 'commodity' => $commodity, 'variety' => $variety, 'grade' => $grade,
                'coverage' => 'selected_district_all_reporting_markets', 'retrieved_at' => now()->toIso8601String(),
                'comparison' => ['month' => $currentMonth, 'prior_month' => $priorMonth, 'through_day' => $today->day, 'current' => $current, 'previous' => $prior, 'previous_full_month' => $summarize($buckets[$priorMonth] ?? []),
                    'change_percent' => $current['value'] !== null && $prior['value'] > 0 ? round(($current['value'] / $prior['value'] - 1) * 100, 2) : null]];
        });
    }

    public function overviewRecords(array $context): array
    {
        $scope = $this->reportScope($context, null, 0);
        $report = Cache::get('my-place-markets:v5:'.$scope);
        if (empty($report['records'])) {
            if (Cache::add('market-preview-refresh:'.$scope, true, now()->addMinutes(30))) {
                \Illuminate\Support\defer(fn () => $this->forPlace($context));
            }
            $report = Cache::get('my-place-last-prices:v4:'.$scope);
        }

        return $report['records'] ?? [];
    }

    private function reportScope(array $context, ?string $commodity, int $offset): string
    {
        $parts = [$context['state']['code'], $context['district']['code'], $commodity, $offset];
        $sourceState = $this->sourceStateName($context);
        if ($sourceState !== $context['state']['name']) {
            $parts[] = $sourceState;
        }

        return hash('sha256', json_encode($parts));
    }

    private function sourceStateName(array $context): string
    {
        return (string) $context['state']['code'] === '22' ? 'Chattisgarh' : $context['state']['name'];
    }
}
