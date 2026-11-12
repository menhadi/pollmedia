<?php

namespace App\Services;

use Illuminate\Support\Str;

final class GramPanchayatUrls
{
    private ?array $states = null;

    /**
     * Canonical Gram Panchayat profile path: /india/gram-panchayats/{state}/{name}-{gp}.
     */
    public function link(int|string $state, ?string $name, int|string $gp, array $query = []): string
    {
        $path = '/india/gram-panchayats/'.$this->stateSlug($state).'/'.Str::slug((string) ($name ?: $gp)).'-'.$gp;
        $query = array_filter($query, fn ($value): bool => $value !== null && $value !== '');

        return url($path).($query === [] ? '' : '?'.http_build_query($query));
    }

    public function stateSlug(int|string $code): string
    {
        $row = $this->statesByCode()[(string) $code] ?? null;

        return $row ? Str::slug($row['name']) : (string) $code;
    }

    public function stateCode(string $slug): ?string
    {
        foreach ($this->statesByCode() as $code => $row) {
            if (Str::slug($row['name']) === $slug) {
                return (string) $code;
            }
        }

        return null;
    }

    private function statesByCode(): array
    {
        if ($this->states === null) {
            $this->states = [];
            foreach (app(MyPlaceDirectory::class)->states() as $row) {
                $this->states[(string) $row['code']] = $row;
            }
        }

        return $this->states;
    }
}
