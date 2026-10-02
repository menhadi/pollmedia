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
        $segments = array_map(fn ($part) => mb_strtolower(trim($part)), explode(',', $query));
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
