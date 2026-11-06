<?php

namespace App\Services;

class MyPlaceSchools
{
    public function data(array $context, array $filters = []): array
    {
        $directory = $this->json(config('services.schools.directory_path', database_path('fixtures/my-place-school-directory.json')));
        $catalogue = $this->json(config('services.schools.catalogue_path', database_path('fixtures/udise-profile-catalogue.json')));
        $districtBrowse = ($filters['browse'] ?? '') === 'district' || empty($context['village']);
        $named = collect($directory['schools'] ?? [])->filter(fn ($row) => $this->inDistrict($row, $context)
            && ($districtBrowse || in_array((string) $context['village']['code'], $row['village_lgd_codes'] ?? [], true)))
            ->filter(fn ($row) => empty($filters['pin']) || (! $row['pin_conflict'] && $row['pin'] === $filters['pin']))->values()->all();
        if (! empty($filters['block']) || ! empty($filters['source_village'])) {
            $named = [];
        }
        $datasets = collect($catalogue['datasets'] ?? [])->filter(fn ($row) => $this->inDistrict($row, $context))->values();
        $dataset = $datasets->count() === 1 ? $datasets->first() : null;
        $rows = $dataset ? $this->profiles($dataset) : [];
        $totalSource = count($rows);
        $blocks = collect($rows)->pluck('lgd_block_name')->filter()->unique()->sort()->values()->all();
        $scope = $districtBrowse ? 'district' : 'village-names';
        if (! $districtBrowse) {
            $rows = array_values(array_filter($rows, fn ($row) => $this->name($row['lgd_vill_name'] ?? '') === $this->name($context['village']['name'])
                && $this->name($row['lgd_block_name'] ?? '') === $this->name($context['village']['subdistrict'] ?? '')
                && $this->name($row['lgd_block_name'] ?? '') !== ''));
        }
        if (! empty($filters['block'])) {
            $rows = array_values(array_filter($rows, fn ($row) => $row['lgd_block_name'] === $filters['block']));
        }
        $villages = collect($rows)->pluck('lgd_vill_name')->filter()->unique()->sort()->values()->all();
        foreach (['source_village' => 'lgd_vill_name', 'pin' => 'pincode'] as $filter => $field) {
            if (! empty($filters[$filter])) {
                $rows = array_values(array_filter($rows, fn ($row) => $row[$field] === $filters[$filter]));
            }
        }
        $total = count($rows);
        $pages = max(1, (int) ceil($total / 12));
        $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));

        return compact('named', 'dataset', 'totalSource', 'blocks', 'villages', 'scope', 'total', 'pages', 'page', 'filters')
            + ['profiles' => array_slice($rows, ($page - 1) * 12, 12)];
    }

    public function value(string $field, mixed $value, array $dataset, string $language): string
    {
        if ($value === null || $value === '') {
            return $language === 'hi' ? 'उपलब्ध नहीं' : 'Unavailable';
        }
        if (in_array($field, $dataset['yes_no'] ?? [], true)) {
            return match ((string) $value) {
                '1' => $language === 'hi' ? 'हाँ' : 'Yes',
                '2' => $language === 'hi' ? 'नहीं' : 'No',
                default => ($language === 'hi' ? 'अपरिभाषित स्रोत कोड: ' : 'Undefined source code: ').$value,
            };
        }

        return (string) $value;
    }

    private function profiles(array $dataset): array
    {
        $filename = $dataset['csv'] ?? '';
        if (! is_string($filename) || basename($filename) !== $filename) {
            return [];
        }
        $path = config('services.schools.profiles_directory', database_path('fixtures')).'/'.$filename;
        if (! is_file($path) || filesize($path) > 8 * 1024 * 1024 || hash_file('sha256', $path) !== ($dataset['sha256'] ?? null)) {
            return [];
        }
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        if ($header !== array_keys($dataset['columns'] ?? [])) {
            fclose($handle);

            return [];
        }
        $rows = [];
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $values);
            if ($row['state'] === $dataset['source_state'] && $row['district'] === $dataset['source_district']) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return collect($rows)->groupBy('pseudocode')->filter(fn ($group, $key) => $key !== '' && $group->count() === 1)
            ->map(fn ($group) => $group->first())->values()->all();
    }

    private function inDistrict(array $row, array $context): bool
    {
        return (string) ($row['state_lgd'] ?? '') === (string) $context['state']['code']
            && (string) ($row['district_lgd'] ?? '') === (string) $context['district']['code'];
    }

    private function name(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    private function json(string $path): array
    {
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
