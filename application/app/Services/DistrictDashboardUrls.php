<?php

namespace App\Services;

class DistrictDashboardUrls
{
    private ?array $catalogue = null;

    private ?array $paths = null;

    public function all(): array
    {
        return $this->catalogue ??= json_decode(file_get_contents(database_path('fixtures/district-dashboard-urls.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function identity(string $state, string $district): ?array
    {
        return $this->all()[str_pad($state, 2, '0', STR_PAD_LEFT).':'.str_pad($district, 3, '0', STR_PAD_LEFT)] ?? null;
    }

    public function resolve(string $state, string $district): ?array
    {
        $path = '/india/districts/'.$state.'/'.$district;
        $this->paths ??= array_column($this->all(), null, 'path');

        return $this->paths[$path] ?? null;
    }
}
