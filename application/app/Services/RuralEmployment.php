<?php

namespace App\Services;

class RuralEmployment
{
    public const WORKER_LOOKUP = 'https://mnregaweb4.nic.in/netnrega/workers/wrkinfo.aspx?lang=en';

    public function forVillage(int $state, int $village): ?array
    {
        if ($state !== 9) {
            return null;
        }
        $gp = app(GramPanchayatDirectory::class)->forVillage($state, $village);
        $report = $gp ? $this->forPanchayat($state, (int) $gp['gp']) : null;

        return $report && in_array((string) $village, $report['villages'], true) ? $report : null;
    }

    public function forPanchayat(int $state, int $gp): ?array
    {
        if ($state !== 9) {
            return null;
        }
        $path = config('services.rural_employment.path', database_path('fixtures/pilibhit-rural-employment.json'));
        if (! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return null;
        }
        $package = json_decode(file_get_contents($path), true);
        if (($package['schema'] ?? null) !== 1 || ($package['publication']['enabled'] ?? false) !== true || empty($package['publication']['basis'])) {
            return null;
        }
        $matches = collect($package['reports'] ?? [])->filter(fn ($row) => is_array($row) && (string) ($row['state'] ?? '') === (string) $state && (string) ($row['gp'] ?? '') === (string) $gp);
        if ($matches->count() !== 1) {
            return null;
        }
        $row = $matches->first();
        if (($row['district'] ?? null) !== '173' || ($row['mapping']['verified'] ?? false) !== true || empty($row['mapping']['method'])
            || ! $this->hash($row['mapping']['civil_directory_sha256'] ?? null) || ! $this->hash($row['mapping']['employment_directory_capture_sha256'] ?? null)
            || ! $this->hash($row['finance_capture_sha256'] ?? null) || ! $this->source($row['finance_source'] ?? null)
            || ($row['unit'] ?? '') !== 'INR_lakh_rounded_2dp' || ($row['scheme'] ?? '') !== 'VB-G RAM G'
            || ! preg_match('/^3126001[0-9]{3}$/D', $row['employment_gp'] ?? '') || ! $this->period($row)) {
            return null;
        }
        if (! is_string($row['name'] ?? null) || trim($row['name']) === '' || ! is_string($row['block'] ?? null) || ! is_array($row['villages'] ?? null) || $row['villages'] === []) {
            return null;
        }
        $finance = [];
        foreach (['unskilled_wage_expenditure', 'skilled_wage_expenditure', 'material_expenditure', 'total_expenditure', 'unskilled_wage_due', 'skilled_wage_due', 'material_due', 'total_due'] as $field) {
            $value = $row['finance'][$field] ?? null;
            if ($value !== null && (! is_string($value) || ! preg_match('/^[0-9]{1,12}(\.[0-9]{1,2})?$/D', $value))) {
                return null;
            }
            $finance[$field] = $value;
        }
        $works = [];
        if (! empty($row['works']) && $this->source($row['work_source'] ?? null) && $this->hash($row['work_capture_sha256'] ?? null)) {
            $codes = array_column($row['works'], 'code');
            if (count($codes) !== count(array_unique($codes))) {
                return null;
            }
            foreach ($row['works'] as $work) {
                if (! is_string($work['name'] ?? null) || mb_strlen($work['name']) > 2000
                    || ! preg_match('~^'.preg_quote($row['employment_gp'], '~').'/[A-Za-z0-9/_.-]+$~D', $work['code'] ?? '')
                    || ! is_int($work['amount_paise'] ?? null) || $work['amount_paise'] < 0 || empty($work['musters'])) {
                    return null;
                }
                $musters = [];
                foreach ($work['musters'] as $muster) {
                    $date = $muster['reported_payment_date'] ?? '';
                    if (! preg_match('/^[0-9]+$/D', $muster['number'] ?? '') || ! $this->date($date)
                        || $date < substr($row['financial_year'], 0, 4).'-04-01' || $date > substr($row['financial_year'], 5).'-03-31'
                        || $date > $row['as_of'] || ! is_int($muster['amount_paise'] ?? null) || $muster['amount_paise'] < 0) {
                        return null;
                    }
                    $musters[] = array_intersect_key($muster, array_flip(['number', 'reported_payment_date', 'amount_paise']));
                }
                if (count(array_unique(array_column($musters, 'number'))) !== count($musters) || array_sum(array_column($musters, 'amount_paise')) !== $work['amount_paise']) {
                    return null;
                }
                $name = preg_match('/REG\.?\s*NO|MMAYG|UP-[0-9]/i', $work['name']) ? 'Individual housing work (PMAY-G)' : $work['name'];
                $works[] = ['code' => $work['code'], 'name' => $name, 'amount_paise' => $work['amount_paise'], 'musters' => $musters];
            }
        }

        return array_intersect_key($row, array_flip(['state', 'district', 'gp', 'name', 'block', 'financial_year', 'scheme', 'as_of', 'captured_on', 'finance_source', 'work_source', 'villages'])) + ['finance' => $finance, 'works' => $works, 'lookup_url' => self::WORKER_LOOKUP];
    }

    private function period(array $row): bool
    {
        $year = $row['financial_year'] ?? '';

        return preg_match('/^20[0-9]{2}-20[0-9]{2}$/D', $year)
            && (int) substr($year, 5) === (int) substr($year, 0, 4) + 1
            && $this->date($row['as_of'] ?? '') && $this->date($row['captured_on'] ?? '')
            && $row['as_of'] >= substr($year, 0, 4).'-04-01'
            && $row['as_of'] <= $row['captured_on'] && $row['captured_on'] <= now('Asia/Kolkata')->toDateString();
    }

    private function date(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $match) && checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    private function source(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $url = parse_url($value);

        return ($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'vbgramgrep.dord.gov.in'
            && ! isset($url['user']) && ! isset($url['pass']) && str_starts_with($url['path'] ?? '', '/VBGRAMG/');
    }
}
