<?php

namespace App\Services;

class MyPlacePanchayatPaymentTotals
{
    public function forSelection(array $context, array $selected): array
    {
        $district = (string) ($context['district']['code'] ?? '');
        if ((string) ($context['state']['code'] ?? '') !== '9'
            || ! preg_match('/^\d+$/D', $district)
            || ($selected['namespace'] ?? '') !== 'LGD Gram Panchayat'
            || ! preg_match('/^\d{5,6}$/D', (string) ($selected['code'] ?? ''))) {
            return [];
        }
        $path = config('services.egramswaraj.payment_totals_path', database_path('fixtures/up-payment-totals.json'));
        $package = $this->readPackage($path);
        if (($package['schema'] ?? null) === 2 && ($package['publication_enabled'] ?? false) === true) {
            $shards = $district === '173' ? ($package['shards'] ?? []) : ($package['district_shards'][$district] ?? []);
            $shard = $shards[substr($selected['code'], 0, 4)] ?? [];
            $file = $shard['file'] ?? '';
            if (! is_string($file) || $file !== 'up-payment-totals-9-'.$district.'-'.substr($selected['code'], 0, 4).'.json'
                || ! preg_match('/^[a-f0-9]{64}$/D', $shard['sha256'] ?? '')) {
                return [];
            }
            $shardPath = dirname($path).DIRECTORY_SEPARATOR.$file;
            if (! is_file($shardPath) || filesize($shardPath) > 2 * 1024 * 1024
                || dirname(realpath($shardPath)) !== realpath(dirname($path))
                || ! hash_equals($shard['sha256'], hash_file('sha256', $shardPath))) {
                return [];
            }
            $package = $this->readPackage($shardPath);
        }
        if (($package['schema'] ?? null) !== 1 || ($package['publication_enabled'] ?? false) !== true
            || ! is_array($package['records'] ?? null)) {
            return [];
        }
        $rows = collect($package['records'])->filter(fn ($row) => is_array($row)
            && (string) ($row['panchayat_code'] ?? '') === $selected['code']
            && (string) ($row['civil_state_lgd'] ?? '') === '9'
            && (string) ($row['civil_district_lgd'] ?? '') === $district);
        $result = [];
        foreach ($rows->groupBy('financial_year')->filter(fn ($group) => $group->count() === 1) as $year => $group) {
            $row = $group->first();
            $amount = $row['payment_total_source_text'] ?? null;
            $source = $package['sources'][$row['source_file'] ?? ''] ?? [];
            if (! preg_match('/^20\d{2}-20\d{2}$/D', $year)
                || (int) substr($year, 5) !== (int) substr($year, 0, 4) + 1
                || ! is_string($amount) || strlen($amount) > 40 || ! preg_match('/^-?,?\d[\d,]*\.\d{2}$/D', $amount)
                || str_replace(',', '', $amount) !== ($row['payment_total_decimal'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $source['sha256'] ?? '')
                || ($row['source_sha256'] ?? null) !== $source['sha256']
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', substr($row['capture_batch_date'] ?? '', 0, 10))
                || parse_url($row['official_detail_url'] ?? '', PHP_URL_SCHEME) !== 'https'
                || parse_url($row['official_detail_url'] ?? '', PHP_URL_HOST) !== 'egramswaraj.gov.in') {
                continue;
            }
            if (! empty($context['village']) && ! in_array((string) $context['village']['code'], $row['village_lgd_codes'] ?? [], true)) {
                continue;
            }
            $result[] = ['year' => $year, 'source_amount' => $amount, 'unit' => ($row['unit'] ?? null) === 'INR' ? 'INR' : null,
                'captured_on' => substr($row['capture_batch_date'], 0, 10), 'source_url' => $row['official_detail_url'],
                'source_generated_text' => is_string($row['source_generated_text'] ?? null) ? $row['source_generated_text'] : null,
                'source_sha256' => $source['sha256'], 'panchayat_code' => $selected['code']];
        }

        return collect($result)->sortByDesc('year')->values()->all();
    }

    private function readPackage(string $path): array
    {
        if (! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return [];
        }
        $package = json_decode(file_get_contents($path), true);

        return is_array($package) ? $package : [];
    }
}
