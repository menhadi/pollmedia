<?php

namespace App\Services;

class ElectionPlaceIdentity
{
    private static function labels(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/eci-state-search-labels.json')), true, 512, JSON_THROW_ON_ERROR)['labels'];
    }

    public static function stateSql(): string
    {
        $sql = 'CASE LOWER(TRIM(state_label))';
        foreach (self::labels() as $code => $label) {
            $sql .= " WHEN '".str_replace("'", "''", $code)."' THEN '".str_replace("'", "''", mb_strtolower($label))."'";
        }

        return $sql.' ELSE LOWER(TRIM(state_label)) END';
    }

    public static function state(string $state): string
    {
        $key = mb_strtolower(trim($state));
        $labels = self::labels();
        if (isset($labels[$key])) {
            return $labels[$key];
        }
        foreach ($labels as $label) {
            if (mb_strtolower($label) === $key) {
                return $label;
            }
        }

        return app(ElectionGeographySummary::class)->states()->first(fn ($row) => mb_strtolower($row['name']) === $key)['name'] ?? trim($state);
    }
}
