<?php

namespace App\Services;

class ElectoralMapCatalogue
{
    public function all(): array
    {
        return json_decode(file_get_contents(public_path('maps/electoral/catalogue.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function stateFromQuery(string $query): ?array
    {
        $aliases = ['madras' => 'tamil nadu', 'mysore' => 'karnataka', 'orrisa' => 'odisha', 'kerla' => 'kerala', 'gujrat' => 'gujarat', 'uttaranchal' => 'uttarakhand', 'pondicherry' => 'puducherry'];
        $segments = array_map(fn ($part) => $aliases[mb_strtolower(trim($part))] ?? mb_strtolower(trim($part)), explode(',', $query));
        foreach ($this->all()['states'] as $state) {
            $names = [mb_strtolower($state['name']), str_replace('-', ' ', $state['slug'])];
            if ($state['slug'] === 'odisha') {
                $names[] = 'orissa';
            }
            if (array_intersect($names, $segments)) {
                return $state;
            }
        }

        return null;
    }
}
