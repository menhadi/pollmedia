<?php

namespace App\Services;

class MyPlacePanchayat
{
    public function data(array $context, ?string $selection = null): array
    {
        $reports = $this->reports($context, $selection);
        $directory = [];
        $usesLocalDirectory = false;
        $source = $this->directorySource($context);
        if ($source !== []) {
            $usesLocalDirectory = true;
            $rows = collect($source['villages'] ?? [])->filter(fn ($row) => (string) ($row['district_code'] ?? '') === (string) $context['district']['code'])
                ->groupBy('code')->filter(fn ($group) => $group->count() === 1)->map(fn ($group) => $group->first());
            $allowed = null;
            if (! empty($context['village'])) {
                $links = $rows->get($context['village']['code'])['panchayats'] ?? [];
                $allowed = count($links) === 1 ? [(string) $links[0]['code']] : [];
            }
            $groups = [];
            foreach ($rows as $row) {
                foreach (count($row['panchayats'] ?? []) === 1 ? $row['panchayats'] : [] as $gp) {
                    $code = (string) $gp['code'];
                    if ($allowed !== null && ! in_array($code, $allowed, true)) {
                        continue;
                    }
                    $groups[$code]['names'][$gp['name']] = true;
                    $groups[$code]['villages'][(string) $row['code']] = ['code' => (string) $row['code'], 'name' => $row['name']];
                    foreach ($row['blocks'] ?? [] as $block) {
                        $groups[$code]['blocks'][$block['name']] = true;
                    }
                }
            }
            foreach ($groups as $code => $group) {
                if (count($group['names']) !== 1) {
                    continue;
                }
                $directory[] = ['code' => (string) $code, 'name' => array_key_first($group['names']), 'namespace' => 'LGD Gram Panchayat',
                    'blocks' => array_keys($group['blocks'] ?? []), 'villages' => array_values($group['villages']),
                    'profile_state' => 9, 'checked_on' => $source['checked_on'] ?? null, 'source_url' => $source['url'] ?? null];
            }
        }
        if ((string) $context['state']['code'] !== '9') {
            $directory = app(GramPanchayatDirectory::class)->myPlace($context, $selection);
            $usesLocalDirectory = $directory !== [] || ! empty($context['gp_subdistrict']);
        }
        if (! $usesLocalDirectory && $directory === [] && ! empty($context['village']) && $reports !== []
            && count(array_unique(array_column($reports, 'panchayat_code'))) === 1
            && count(array_unique(array_column($reports, 'name'))) === 1) {
            $report = $reports[0];
            $directory[] = ['code' => $report['panchayat_code'], 'name' => $report['name'], 'namespace' => 'eGramSwaraj Panchayat',
                'blocks' => $report['block_name'] !== null ? [$report['block_name']] : [],
                'villages' => [['code' => (string) $context['village']['code'],
                    'name' => $context['village']['name'] ?? (string) $context['village']['code']]],
                'checked_on' => null, 'source_url' => null];
        }
        if (empty($context['village']) && empty($context['gp_subdistrict'])) {
            foreach ($reports as $report) {
                if (! collect($directory)->contains('code', $report['panchayat_code'])) {
                    $directory[] = ['code' => $report['panchayat_code'], 'name' => $report['name'], 'namespace' => 'eGramSwaraj Panchayat',
                        'blocks' => [$report['block_name']], 'villages' => [], 'checked_on' => null, 'source_url' => null];
                }
            }
        }
        $directory = collect($directory)->sortBy('name')->values()->all();
        $selected = $selection ? collect($directory)->firstWhere('code', $selection) : (count($directory) === 1 ? $directory[0] : null);

        return ['directory' => $directory, 'selected' => $selected, 'reports' => $selected ? array_values(array_filter($reports, fn ($row) => $row['panchayat_code'] === $selected['code'])) : [],
            'allocations' => $selected ? $this->stateAllocations($context, $selected) : [],
            'payment_totals' => $selected ? app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $selected) : [],
            'additional_reports' => $this->sourceOnlyPanchayats($context),
            'reason' => ! empty($context['village']) ? 'village-link-unavailable' : 'district-records-unavailable'];
    }

    private function directorySource(array $context): array
    {
        $district = (string) ($context['district']['code'] ?? '');
        if ((string) ($context['state']['code'] ?? '') !== '9' || ! preg_match('/^\d+$/D', $district)) {
            return [];
        }
        if ($district === '173') {
            $path = config('services.egramswaraj.directory_path', database_path('fixtures/pilibhit-lgd.json'));
        } else {
            $index = config('services.egramswaraj.directory_manifest_path', database_path('fixtures/up-panchayat-directories.json'));
            if (! is_file($index) || filesize($index) > 100000) {
                return [];
            }
            $manifest = json_decode(file_get_contents($index), true);
            $entry = $manifest['districts'][$district] ?? [];
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['publication_enabled'] ?? false) !== true
                || ($entry['file'] ?? '') !== 'up-panchayat-directory-9-'.$district.'.json'
                || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) {
                return [];
            }
            $path = dirname($index).DIRECTORY_SEPARATOR.$entry['file'];
            if (! is_file($path) || filesize($path) > 2 * 1024 * 1024
                || dirname(realpath($path)) !== realpath(dirname($index))
                || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) {
                return [];
            }
        }
        if (! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return [];
        }
        $source = json_decode(file_get_contents($path), true);

        return is_array($source) ? $source : [];
    }

    private function stateAllocations(array $context, array $selected): array
    {
        if ((string) $context['state']['code'] !== '9' || (string) $context['district']['code'] !== '173'
            || $selected['namespace'] !== 'LGD Gram Panchayat'
            || ! preg_match('/^\d{5,6}$/D', $selected['code'])) {
            return [];
        }
        $root = config('services.egramswaraj.allocations_path', database_path('fixtures/up-state-allocations'));
        $index = $root.'/manifest.json';
        if (! is_file($index) || filesize($index) > 100000) {
            return [];
        }
        $manifest = json_decode(file_get_contents($index), true);
        $key = substr($selected['code'], -2);
        $path = $root.'/'.$key.'.json';
        if (($manifest['schema'] ?? null) !== 1 || ! isset($manifest['shards'][$key]) || ! is_file($path)
            || filesize($path) > 2 * 1024 * 1024 || hash_file('sha256', $path) !== $manifest['shards'][$key]) {
            return [];
        }
        $records = json_decode(file_get_contents($path), true)[$selected['code']] ?? [];
        $result = [];
        foreach (collect($records)->groupBy('source')->filter(fn ($rows) => $rows->count() === 1) as $sourceId => $rows) {
            $row = $rows->first();
            $source = $manifest['sources'][$sourceId] ?? [];
            if (! preg_match('/^202\d-202\d$/D', $source['year'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $source['sha256'] ?? '')
                || parse_url($source['url'] ?? '', PHP_URL_HOST) !== 'prdfinance.up.gov.in'
                || parse_url($source['url'] ?? '', PHP_URL_SCHEME) !== 'https'
                || ! is_array($row['amounts'] ?? null) || count($row['amounts']) !== count($source['labels'] ?? [])) {
                continue;
            }
            $result[] = ['year' => $source['year'], 'name' => $row['name'], 'district' => $row['district'], 'block' => $row['block'],
                'unit' => ($source['unit'] ?? null) === 'INR' ? 'INR' : null, 'source_url' => $source['url'],
                'source_sha256' => $source['sha256'], 'sheet' => $source['sheet'], 'row' => $row['row'],
                'captured_on' => $manifest['captured_on'],
                'installments' => array_map(fn ($label, $amount) => ['label' => $label, 'amount' => $amount], $source['labels'], $row['amounts'])];
        }

        return collect($result)->sortByDesc('year')->values()->all();
    }

    public function profileRecords(array $record, array $districts): array
    {
        $reports = $payments = $allocations = [];
        $code = (string) $record['gp'];
        $selected = ['code' => $code, 'name' => $record['name'], 'namespace' => 'LGD Gram Panchayat'];
        foreach (array_unique($districts) as $district) {
            $context = ['state' => ['code' => (string) $record['state']], 'district' => ['code' => (string) $district], 'village' => null];
            array_push($reports, ...$this->reports($context, $code));
            array_push($payments, ...app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $selected));
            array_push($allocations, ...$this->stateAllocations($context, $selected));
        }

        return [
            'reports' => collect($reports)->unique()->values()->all(),
            'payment_totals' => collect($payments)->unique()->groupBy('year')->filter(fn ($rows) => $rows->count() === 1)->map->first()->sortByDesc('year')->values()->all(),
            'allocations' => collect($allocations)->unique()->values()->all(),
        ];
    }

    public function reports(array $context, ?string $code = null): array
    {
        $path = config('services.egramswaraj.reports_path', database_path('fixtures/egramswaraj-accounts.json'));
        if (! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
            return [];
        }

        $package = json_decode(file_get_contents($path), true);
        if (! is_array($package) || ($package['schema'] ?? null) !== 1
            || ($package['publication']['enabled'] ?? false) !== true
            || empty($package['publication']['evidence']) || ! is_array($package['reports'] ?? null)) {
            return [];
        }

        $package['reports'] = $this->detailedReports($package['reports'], $context, $code, $path);
        $reports = [];
        foreach ($package['reports'] as $report) {
            if (! is_array($report) || ($code !== null && (string) ($report['panchayat_code'] ?? '') !== $code) || ! $this->matches($report, $context)) {
                continue;
            }
            $key = $report['panchayat_code'].':'.$report['financial_year'];
            $reports[$key][] = $report;
        }

        return collect($reports)->filter(fn ($group) => count($group) === 1)
            ->map(fn ($group) => $this->normalize($group[0]))->filter(fn ($report) => $report['sources'] !== [])
            ->sortBy('name')->values()->all();
    }

    private function detailedReports(array $base, array $context, ?string $code, string $basePath): array
    {
        $configured = config('services.egramswaraj.shard_manifest_path');
        if ($configured === null && $basePath !== database_path('fixtures/egramswaraj-accounts.json') && ((string) $context['district']['code'] === '173' || config('services.egramswaraj.district_shards_index_path') === null)) {
            return $base;
        }
        if ($code === null && empty($context['village'])) {
            return $base;
        }
        $path = $configured ?? ((string) $context['district']['code'] === '173'
            ? database_path('fixtures/egramswaraj-details/manifest.json') : $this->districtDetailsManifest($context));
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 1024 * 1024) {
            return $base;
        }
        $manifest = json_decode(file_get_contents($path), true);
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== 1
            || ($manifest['publication_enabled'] ?? false) !== true || ! is_array($manifest['reports'] ?? null)) {
            return $base;
        }
        $replacements = [];
        foreach ($manifest['reports'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['panchayat_code'] ?? null) || ! preg_match('/^[1-9][0-9]{4,6}$/D', $entry['panchayat_code'])
                || ! is_string($entry['financial_year'] ?? null) || ! preg_match('/^20[0-9]{2}-20[0-9]{2}$/D', $entry['financial_year'])
                || ! is_string($entry['state_lgd'] ?? null) || ! is_string($entry['district_lgd'] ?? null)
                || ! is_array($entry['village_lgd_codes'] ?? null)
                || ($code !== null && (string) $entry['panchayat_code'] !== $code)
                || (string) ($entry['state_lgd'] ?? '') !== (string) $context['state']['code']
                || (string) ($entry['district_lgd'] ?? '') !== (string) $context['district']['code']
                || (! empty($context['village']) && ! in_array((string) $context['village']['code'], $entry['village_lgd_codes'] ?? [], true))) {
                continue;
            }
            $expected = $entry['state_lgd'].'-'.$entry['district_lgd'].'-'.$entry['panchayat_code'].'-'.$entry['financial_year'].'.json';
            $file = dirname($path).DIRECTORY_SEPARATOR.$expected;
            if (($entry['file'] ?? null) !== $expected || ! is_string($entry['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])
                || ! is_file($file) || ! is_readable($file) || filesize($file) > 2 * 1024 * 1024
                || dirname(realpath($file)) !== realpath(dirname($path))
                || ! hash_equals($entry['sha256'], hash_file('sha256', $file))) {
                continue;
            }
            $data = json_decode(file_get_contents($file), true);
            $report = is_array($data) && ($data['schema'] ?? null) === 1 ? ($data['report'] ?? null) : null;
            if (! is_array($report) || ! $this->matches($report, $context)
                || $report['panchayat_code'] !== (string) $entry['panchayat_code']
                || $report['financial_year'] !== $entry['financial_year']
                || ($report['mapping']['village_lgd_codes'] ?? []) !== ($entry['village_lgd_codes'] ?? [])) {
                continue;
            }
            $key = $report['panchayat_code'].':'.$report['financial_year'];
            $replacements[$key][] = $report;
        }
        $verified = collect($replacements)->filter(fn ($rows) => count($rows) === 1)->map(fn ($rows) => $rows[0])->all();

        return array_merge(array_values(array_filter($base, fn ($row) => ! is_array($row)
            || ! isset($verified[($row['panchayat_code'] ?? '').':'.($row['financial_year'] ?? '')]))), array_values($verified));
    }

    private function districtDetailsManifest(array $context): ?string
    {
        $district = (string) ($context['district']['code'] ?? '');
        if ((string) ($context['state']['code'] ?? '') !== '9' || ! preg_match('/^[1-9][0-9]{0,5}$/D', $district)) {
            return null;
        }
        $index = config('services.egramswaraj.district_shards_index_path', database_path('fixtures/egramswaraj-details/up-districts.json'));
        if (! is_string($index) || ! is_file($index) || ! is_readable($index) || filesize($index) > 100000) {
            return null;
        }
        $data = json_decode(file_get_contents($index), true);
        $entry = is_array($data) && ($data['schema'] ?? null) === 1 && ($data['publication_enabled'] ?? false) === true
            ? ($data['districts'][$district] ?? null) : null;
        $expected = '9-'.$district.'/manifest.json';
        if (! is_array($entry) || ($entry['file'] ?? null) !== $expected || ! is_string($entry['sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])) {
            return null;
        }
        $root = dirname($index);
        $directory = $root.DIRECTORY_SEPARATOR.'9-'.$district;
        $path = $root.DIRECTORY_SEPARATOR.$expected;
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1024 * 1024
            || dirname(realpath($directory)) !== realpath($root) || dirname(realpath($path)) !== realpath($directory)
            || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) {
            return null;
        }

        return $path;
    }

    public function sourceOnlyPanchayats(array $context): array
    {
        if (! empty($context['village']) || ! empty($context['gp_subdistrict'])) {
            return [];
        }
        $path = $this->districtDetailsManifest($context);
        if ($path === null) {
            return [];
        }
        $manifest = json_decode(file_get_contents($path), true);
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['publication_enabled'] ?? false) !== true) {
            return [];
        }
        $result = [];
        foreach ($manifest['reports'] ?? [] as $entry) {
            if (! is_array($entry) || ($entry['source_only'] ?? false) !== true || ($entry['village_lgd_codes'] ?? null) !== []
                || ($entry['state_lgd'] ?? null) !== (string) $context['state']['code']
                || ($entry['district_lgd'] ?? null) !== (string) $context['district']['code']
                || ! preg_match('/^[1-9][0-9]{4,6}$/D', $entry['panchayat_code'] ?? '')
                || ! is_string($entry['name'] ?? null) || mb_strlen($entry['name']) > 300
                || ! is_string($entry['note'] ?? null) || mb_strlen($entry['note']) > 1000) {
                continue;
            }
            $result[] = ['code' => $entry['panchayat_code'], 'name' => $entry['name'], 'note' => $entry['note'],
                'url' => route('my-place.panchayat-records', ['state' => $context['state']['code'], 'district' => $context['district']['code'], 'panchayat' => $entry['panchayat_code']])];
        }

        return $result;
    }

    private function sourceReportedVouchers(array $report, array $sources, int $year): array
    {
        $result = [];
        foreach (is_array($report['source_reported_vouchers'] ?? null) ? $report['source_reported_vouchers'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $row['source_key'] ?? '';
            $source = $sources[$key] ?? null;
            if (! $source || ! str_starts_with($key, 'vouchers_') || ($row['source_note'] ?? null) !== 'report-date-discrepancy'
                || ! in_array($row['kind'] ?? null, ['receipts', 'payments', 'contra', 'journal'], true)
                || ! $this->date($row['date'] ?? null) || $row['date'] < $year.'-04-01' || $row['date'] > ($year + 1).'-03-31'
                || substr($row['date'], 0, 7) !== str_replace('_', '-', substr($key, 9, 7))
                || ! is_string($row['voucher'] ?? null) || $row['voucher'] === '' || $this->count($row['amount_paise'] ?? null) === null) {
                continue;
            }
            $result[] = ['date' => $row['date'], 'voucher' => $row['voucher'], 'kind' => $row['kind'], 'amount_paise' => $row['amount_paise'],
                'source_url' => $source['url'], 'report_date' => $source['report_date'],
                'note' => $row['date'] > $source['report_date'] ? 'Entry date is later than the source report date.' : 'This source report contains a date discrepancy.'];
        }

        return $result;
    }

    private function matches(array $report, array $context): bool
    {
        $mapping = $report['mapping'] ?? [];
        if (($mapping['verified'] ?? false) !== true || empty($mapping['evidence'])
            || (string) ($mapping['state_lgd'] ?? '') !== (string) $context['state']['code']
            || (string) ($mapping['district_lgd'] ?? '') !== (string) $context['district']['code']) {
            return false;
        }
        if (! empty($context['village']) && (! is_array($mapping['village_lgd_codes'] ?? null)
            || ! in_array((string) $context['village']['code'], array_map('strval', $mapping['village_lgd_codes']), true)
            || empty($mapping['village_evidence']))) {
            return false;
        }

        return preg_match('/^\d+$/D', (string) ($report['panchayat_code'] ?? '')) === 1
            && is_string($report['name'] ?? null) && $report['name'] !== ''
            && is_string($report['financial_year'] ?? null)
            && preg_match('/^20\d{2}-20\d{2}$/D', $report['financial_year']) === 1
            && (int) substr($report['financial_year'], 5, 4) === (int) substr($report['financial_year'], 0, 4) + 1;
    }

    private function normalize(array $report): array
    {
        $year = (int) substr($report['financial_year'], 0, 4);
        $sources = [];
        foreach (($report['sources'] ?? []) as $kind => $source) {
            if (! in_array($kind, ['monthly', 'payments', 'receipts', 'contra', 'journal', 'accounting', 'activities', 'activity_details', 'availability'], true)
                && ! preg_match('/^vouchers_20\d{2}_(0[1-9]|1[0-2])(?:_snapshot_[a-f0-9]{16})?$/D', $kind)) {
                continue;
            }
            if (is_array($source) && $this->date($source['report_date'] ?? null) && $this->source($source['url'] ?? null)) {
                $sources[$kind] = ['url' => $source['url'], 'report_date' => $source['report_date']];
            }
        }
        $months = collect(isset($sources['monthly']) && is_array($report['months'] ?? null) ? $report['months'] : [])->filter(fn ($row) => is_array($row)
            && is_string($row['month'] ?? null)
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $row['month'] ?? '') === 1
            && $row['month'] >= $year.'-04' && $row['month'] <= ($year + 1).'-03'
            && $row['month'] <= substr($sources['monthly']['report_date'], 0, 7))
            ->groupBy('month')->filter(fn ($group) => $group->count() === 1)
            ->map(fn ($group) => ['month' => $group[0]['month'], 'receipts' => $this->count($group[0]['receipts'] ?? null), 'payments' => $this->count($group[0]['payments'] ?? null), 'contra' => $this->count($group[0]['contra'] ?? null), 'journal' => $this->count($group[0]['journal'] ?? null)])
            ->sortBy('month')->values()->all();
        $transactions = [];
        foreach (['payments', 'receipts', 'contra', 'journal'] as $kind) {
            $transactions[$kind] = $this->transactions($report, $sources, $year, $kind);
        }
        foreach (['payments', 'receipts'] as $kind) {
            $isCancellation = fn ($entry) => preg_match('/\b(?:Receipt|Payment)\s+Cancellation\b/i', $entry['voucher_type'] ?? '') === 1;
            $transactions[$kind.'_cancellations'] = array_values(array_filter($transactions[$kind], $isCancellation));
            $transactions[$kind] = array_values(array_filter($transactions[$kind], fn ($entry) => ! $isCancellation($entry)));
            $isReversal = fn ($entry) => preg_match('/\brevers(?:e[ds]?|al)\b/i', $entry['voucher_type'] ?? '') === 1;
            $transactions[$kind.'_reversals'] = array_values(array_filter($transactions[$kind], $isReversal));
            $transactions[$kind] = array_values(array_filter($transactions[$kind], fn ($entry) => ! $isReversal($entry)));
            $isAdjustment = fn ($entry) => preg_match('/\bOB\s+Cancellation\b/i', $entry['voucher_type'] ?? '') === 1;
            $transactions[$kind.'_adjustments'] = array_values(array_filter($transactions[$kind], $isAdjustment));
            $transactions[$kind] = array_values(array_filter($transactions[$kind], fn ($entry) => ! $isAdjustment($entry)));
        }

        return [
            'name' => $report['name'], 'native_name' => is_string($report['native_name'] ?? null) ? $report['native_name'] : null,
            'block_name' => is_string($report['block_name'] ?? null) ? $report['block_name'] : null, 'panchayat_code' => $report['panchayat_code'],
            'financial_year' => $report['financial_year'], 'sources' => $sources, 'months' => $months, ...$transactions,
            'financial_summary' => $this->financialSummary($transactions),
            'source_notes' => array_values(array_filter(is_array($report['source_notes'] ?? null) ? $report['source_notes'] : [], fn ($note) => is_string($note) && $note !== '' && mb_strlen($note) <= 1000)),
            'source_reported_vouchers' => $this->sourceReportedVouchers($report, $sources, $year),
            'activities' => isset($sources['activities']) ? $this->activities($report) : [],
            'opening_balance_entered' => isset($sources['accounting']) && is_bool($report['opening_balance_entered'] ?? null) ? $report['opening_balance_entered'] : null,
            'month_book_closed' => isset($sources['accounting']) && is_bool($report['month_book_closed'] ?? null) ? $report['month_book_closed'] : null,
            'year_book_closed' => isset($sources['accounting']) && is_bool($report['year_book_closed'] ?? null) ? $report['year_book_closed'] : null,
        ];
    }

    private function activities(array $report): array
    {
        return collect(is_array($report['activities'] ?? null) ? $report['activities'] : [])
            ->filter(function ($row) use ($report) {
                if (! is_array($row) || ! is_string($row['code'] ?? null) || ! preg_match('/^\d+$/D', $row['code'])) {
                    return false;
                }
                foreach (['name', 'focus_area', 'type', 'status', 'output_type', 'plan_type'] as $field) {
                    if (! is_string($row[$field] ?? null) || trim($row[$field]) === '' || mb_strlen($row[$field]) > 4000) {
                        return false;
                    }
                }

                return ! array_key_exists('source_evidence', $row) || $this->activitySource($row, $report) !== null;
            })->groupBy('code')->filter(fn ($group) => $group->count() === 1)
            ->map(function ($group) use ($report) {
                $row = $group[0];
                $record = array_intersect_key($row, array_flip(['code', 'name', 'focus_area', 'type', 'status', 'output_type', 'plan_type']));
                $record['cost'] = is_string($row['cost'] ?? null) && preg_match('/^\d{1,15}(\.\d{1,4})?$/D', $row['cost']) ? $row['cost'] : null;
                $record['details'] = $this->activityDetails($row, $report);
                if ($source = $this->activitySource($row, $report)) {
                    $record['source_url'] = $source['url'];
                    $record['source_date'] = $source['retrieved_on'];
                    if ($record['details'] === null && is_array($row['section_details'] ?? null)) {
                        $record['details'] = $this->verifiedSectionDetails($row['section_details'], $source);
                    }
                }

                return $record;
            })->values()->all();
    }

    private function activitySource(array $activity, array $report): ?array
    {
        $source = $activity['source_evidence'] ?? null;
        if (! is_array($source) || ! $this->source($source['url'] ?? null)
            || parse_url($source['url'], PHP_URL_PATH) !== '/activitySectionData.do'
            || ($source['activity_code'] ?? null) !== ($activity['code'] ?? null)
            || ($source['panchayat_code'] ?? null) !== (string) $report['panchayat_code']
            || ($source['financial_year'] ?? null) !== $report['financial_year']
            || ! is_string($source['plan_id'] ?? null) || ! preg_match('/^[1-9][0-9]*$/D', $source['plan_id'])
            || ! is_string($source['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $source['sha256'])
            || ! $this->date($source['retrieved_on'] ?? null)
            || $source['retrieved_on'] > ($report['sources']['activities']['report_date'] ?? '')
            || $source['retrieved_on'] > now('Asia/Kolkata')->toDateString()) {
            return null;
        }
        parse_str(parse_url($source['url'], PHP_URL_QUERY) ?? '', $query);

        return ($query['activityCd'] ?? null) === $activity['code'] ? $source : null;
    }

    private function verifiedSectionDetails(array $detail, array $source): array
    {
        $result = ['retrieved_on' => $source['retrieved_on'], 'plan_id' => $source['plan_id'],
            'technical_status' => null, 'administrative_status' => null, 'progress_status' => null,
            'expenditure_status' => null, 'asset' => [], 'training' => [], 'funding' => []];
        foreach (['description', 'theme'] as $field) {
            $result[$field] = is_string($detail[$field] ?? null) && mb_strlen($detail[$field]) <= 4000 && trim($detail[$field]) !== '' ? $detail[$field] : null;
        }

        return $result;
    }

    private function activityDetails(array $activity, array $report): ?array
    {
        $detail = $activity['details'] ?? null;
        $source = $report['sources']['activity_details'] ?? [];
        if (! is_array($detail) || ! $this->source($source['url'] ?? null)
            || ! $this->date($source['report_date'] ?? null)
            || ($detail['source_url'] ?? null) !== $source['url']
            || ! $this->date($detail['retrieved_on'] ?? null)
            || $detail['retrieved_on'] > $source['report_date']
            || ($detail['activity_code'] ?? null) !== $activity['code']
            || ($detail['panchayat_code'] ?? null) !== (string) $report['panchayat_code']
            || ($detail['financial_year'] ?? null) !== $report['financial_year']
            || ! is_string($detail['source_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $detail['source_sha256'])
            || ! is_string($detail['plan_id'] ?? null) || ! preg_match('/^\d+$/D', $detail['plan_id'])) {
            return null;
        }
        $result = ['retrieved_on' => $detail['retrieved_on'], 'plan_id' => $detail['plan_id']];
        foreach (['description', 'theme', 'technical_status', 'administrative_status', 'progress_status', 'expenditure_status'] as $field) {
            $result[$field] = is_string($detail[$field] ?? null) && mb_strlen($detail[$field]) <= 4000 && trim($detail[$field]) !== '' ? $detail[$field] : null;
        }
        if ($result['description'] === null) {
            return null;
        }
        foreach (['asset' => ['Asset Type', 'Asset Category', 'Asset Sub Category', 'Coverage Area', 'Asset Unit Type', 'Asset No. Of Unit', 'Asset Unit Cost', 'Asset Location'],
            'training' => ['Training Category', 'Organized By', 'Subject of Training', 'Total Trainees', 'Total Duration']] as $kind => $fields) {
            $result[$kind] = [];
            foreach ($fields as $field) {
                $value = $detail[$kind][$field] ?? null;
                if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 1000) {
                    $result[$kind][$field] = $value;
                }
            }
        }
        $result['funding'] = [];
        foreach (is_array($detail['funding'] ?? null) ? $detail['funding'] : [] as $funding) {
            if (! is_array($funding) || ! is_string($funding['scheme'] ?? null) || trim($funding['scheme']) === ''
                || ! is_string($funding['component'] ?? null) || mb_strlen($funding['scheme']) > 1000 || mb_strlen($funding['component']) > 1000) {
                continue;
            }
            $amount = is_string($funding['amount'] ?? null) && preg_match('/^\d{1,15}(\.\d{1,4})?$/D', $funding['amount']) ? $funding['amount'] : null;
            $result['funding'][] = ['scheme' => $funding['scheme'], 'component' => $funding['component'], 'amount' => $amount,
                'unit' => ($funding['unit'] ?? null) === 'INR' && ! empty($funding['unit_evidence']) ? 'INR' : null];
        }

        return $result;
    }

    private function financialSummary(array $transactions): array
    {
        $totals = [];
        $months = [];
        foreach ($transactions as $kind => $entries) {
            if ($entries === []) {
                continue;
            }
            $dates = array_column($entries, 'date');
            $totals[$kind] = ['count' => count($entries), 'amount_paise' => $this->entryTotal($entries), 'from' => min($dates), 'to' => max($dates)];
            if (! in_array($kind, ['receipts', 'payments'], true)) {
                continue;
            }
            foreach (collect($entries)->groupBy(fn ($entry) => substr($entry['date'], 0, 7)) as $month => $rows) {
                $months[$month] ??= ['month' => $month, 'receipts' => null, 'payments' => null];
                $months[$month][$kind] = $this->entryTotal($rows->all());
            }
        }
        ksort($months);

        return ['totals' => $totals, 'months' => array_values($months)];
    }

    private function entryTotal(array $entries): ?int
    {
        if ($entries === [] || collect($entries)->contains(fn ($entry) => $entry['amount_paise'] === null)) {
            return null;
        }
        $sum = array_sum(array_column($entries, 'amount_paise'));

        return is_int($sum) ? $sum : null;
    }

    private function transactions(array $report, array $sources, int $year, string $kind): array
    {
        return collect(is_array($report[$kind] ?? null) ? $report[$kind] : [])->filter(function ($row) use ($sources, $year, $kind) {
            if (! is_array($row) || ! is_string($row['source_key'] ?? $kind)) {
                return false;
            }
            $key = $row['source_key'] ?? $kind;
            $source = $sources[$key] ?? null;
            if (! $source || ($key !== $kind && ! str_starts_with($key, 'vouchers_'))) {
                return false;
            }

            return $this->date($row['date'] ?? null) !== null && $row['date'] <= $source['report_date']
                && $row['date'] >= $year.'-04-01' && $row['date'] <= ($year + 1).'-03-31'
                && (! str_starts_with($key, 'vouchers_') || substr($row['date'], 0, 7) === str_replace('_', '-', substr($key, 9, 7)))
                && is_string($row['voucher'] ?? null) && $row['voucher'] !== '';
        })->groupBy('voucher')->flatMap(function ($group) use ($report, $kind) {
            if ($group->count() === 1) {
                return $group->all();
            }
            $identities = $group->map(fn ($row) => $this->sourceRecordIdentity($row, $report, $kind));

            return $identities->contains(null) || $identities->unique()->count() !== $group->count() ? [] : $group->all();
        })
            ->map(fn ($row) => ['date' => $row['date'], 'voucher' => $row['voucher'], 'amount_paise' => $this->count($row['amount_paise'] ?? null),
                'voucher_type' => is_string($row['voucher_type'] ?? null) ? trim($row['voucher_type']) : null,
                'source_record_id' => $this->sourceRecordIdentity($row, $report, $kind),
                'source_key' => $row['source_key'] ?? $kind, 'report_date' => $sources[$row['source_key'] ?? $kind]['report_date']])
            ->sortByDesc('date')->values()->all();
    }

    private function sourceRecordIdentity(array $row, array $report, string $kind): ?string
    {
        $evidence = $row['record_source'] ?? null;
        $path = ['receipts' => '/receiptVoucherDetail.do', 'payments' => '/paymentVoucherDetail.do'][$kind] ?? null;
        if (! $path || ! is_array($evidence) || ! $this->source($evidence['url'] ?? null)
            || parse_url($evidence['url'], PHP_URL_PATH) !== $path
            || ! is_string($evidence['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $evidence['sha256'])
            || ($evidence['voucher'] ?? null) !== $row['voucher'] || ($evidence['date'] ?? null) !== $row['date']
            || $this->count($evidence['amount_paise'] ?? null) === null || $evidence['amount_paise'] !== ($row['amount_paise'] ?? null)) {
            return null;
        }
        parse_str(parse_url($evidence['url'], PHP_URL_QUERY) ?? '', $query);
        if (! is_string($query['voucherID'] ?? null) || ! preg_match('/^\d+$/D', $query['voucherID'])
            || ($query['PRIEntity_code'] ?? null) !== (string) $report['panchayat_code']
            || ($query['finYear'] ?? null) !== $report['financial_year']
            || ($query['stateCode'] ?? null) !== (string) ($report['mapping']['state_lgd'] ?? '')) {
            return null;
        }

        return $query['voucherID'];
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }

    private function source(mixed $url): ?string
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'egramswaraj.gov.in'
            && parse_url($url, PHP_URL_USER) === null ? $url : null;
    }
}
