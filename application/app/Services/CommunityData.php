<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommunityData
{
    public const TOPICS = ['education' => 'Education', 'population' => 'Population', 'households' => 'Households', 'water' => 'Census water', 'health' => 'Health', 'work' => 'Work', 'facilities' => 'Facilities', 'other' => 'Other source fields'];

    public static function publicEditReturn(mixed $path): ?string
    {
        if (! is_string($path) || strlen($path) > 2000 || preg_match('/[\\\\\x00-\x20\x7f]/', $path) || str_contains($path, '%') && preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|5c|7f)/i', $path)) {
            return null;
        }

        return preg_match('~^/(?:india|places|data/census)(?:[/?]|$)~', $path) ? $path : null;
    }

    public function cachedRows(string $key, callable $query): Collection
    {
        $rows = Cache::remember('community.rows-v2:'.$key, 300, fn (): array => $query()->map(fn (object $row): array => (array) $row)->all());

        return collect($rows)->map(fn (array $row): object => (object) $row);
    }

    public function publishedRows(): Builder
    {
        return DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published');
    }

    public function record(int $id, bool $lock = false): object
    {
        $query = $this->publishedRows()->where('r.id', $id)->select('r.*', 'e.year', 'e.name as edition_name', 'e.source_key', 'e.fields', 'e.source_url', 'e.sha256', 'e.scope', 'e.import_run_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    public function fingerprint(object $record): string
    {
        return hash('sha256', json_encode([$record->id, $record->edition_id, $record->values, $record->flags, $record->name], JSON_THROW_ON_ERROR));
    }

    public function matchesOriginalValues(int $record, array $values, string $sha): bool
    {
        if (hash_equals($sha, hash('sha256', json_encode($values)))) {
            return true;
        }
        foreach (DB::table('community_data_proposals')->where('record_id', $record)->where('status', 'approved')->where('field', '!=', 'listing_name')->orderByDesc('reviewed_at')->orderByDesc('id')->get() as $change) {
            $approved = json_decode($change->approved_value ?? $change->proposed_value, true);
            if (! array_key_exists($change->field, $values) || $values[$change->field] !== $approved) {
                return false;
            }
            $values[$change->field] = json_decode($change->before_value, true);
        }

        return hash_equals($sha, hash('sha256', json_encode($values)));
    }

    public function sourceName(object $record): string
    {
        if (str_contains($record->flags ?? '', 'for listing_name.')) {
            $original = DB::table('community_data_proposals')->where('record_id', $record->id)->where('field', 'listing_name')->where('status', 'approved')->orderBy('reviewed_at')->orderBy('id')->value('before_value');
            if (is_string($name = json_decode($original ?? 'null', true))) {
                return $name;
            }
        }

        return $record->name ?? '';
    }

    public function editable(string $field): bool
    {
        return ! in_array(strtoupper($field), ['LITERACY', 'ST_CODE', 'STATE', 'STATE_CODE', 'DIST_CODE', 'DISTRICT', 'DISTRICT_CODE', 'THSIL_CODE', 'BLOCK_CODE', 'V_CT_CODE', 'VILL_NAME', 'TOWN_VILL', 'TOWN/VILLAGE', 'WARD', 'EB', 'SUB-DISTT', 'TAHSIL', 'SUBDISTT', 'NAME', 'LEVEL', 'TRU', 'YEAR', 'SOURCE_ROW', 'SOURCE_KEY', 'RECORD_KEY'], true);
    }

    public function fields(object $edition): array
    {
        $keys = json_decode($edition->fields, true) ?? [];
        $labels = Cache::remember('community.fields:'.$edition->import_run_id.':'.$edition->sha256, 3600, function () use ($edition): array {
            $raw = DB::table('import_runs')->where('id', $edition->import_run_id)->value('extracted->fields');
            $data = is_string($raw) ? json_decode($raw, true) : $raw;

            return is_array($data) ? $data : [];
        });
        if (isset($edition->geography) && preg_match('/source-only/', $edition->source_key)) {
            $sheet = json_decode($edition->geography, true)['SOURCE_SHEET'] ?? null;
            $headers = Cache::remember('community.original-headers:'.$edition->import_run_id, 3600, function () use ($edition): array {
                $raw = DB::table('import_runs')->where('id', $edition->import_run_id)->value('extracted->original_headers_by_sheet');

                return (is_string($raw) ? json_decode($raw, true) : $raw) ?? [];
            });
            foreach ($headers[$sheet] ?? [] as $index => $label) {
                $labels['COLUMN_'.($index + 1)] = $label === '' ? 'Unlabelled original column '.($index + 1) : $label;
            }
        }
        $known = ['TOT_P' => 'Population', 'TOT_M' => 'Male population', 'TOT_F' => 'Female population', 'P_06' => 'Children aged 0–6', 'M_06' => 'Boys aged 0–6', 'F_06' => 'Girls aged 0–6', 'P_LIT' => 'Literate persons', 'M_LIT' => 'Literate males', 'F_LIT' => 'Literate females', 'P_ILL' => 'Illiterate persons', 'No_HH' => 'Households', 'HH' => 'Households'];
        $known += ['M_ILL' => 'Illiterate males', 'F_ILL' => 'Illiterate females'];
        foreach (['P' => 'Persons', 'M' => 'Males', 'F' => 'Females'] as $sex => $label) {
            $known[$sex.'_SC'] = $label.' · Scheduled Castes';
            $known[$sex.'_ST'] = $label.' · Scheduled Tribes';
            foreach (['TOT_WORK' => 'Total workers', 'MAINWORK' => 'Main workers', 'MARGWORK' => 'Marginal workers', 'NON_WORK' => 'Non-workers', 'MAIN_CL' => 'Main cultivators', 'MAIN_AL' => 'Main agricultural labourers', 'MAIN_HH' => 'Main household industry workers', 'MAIN_OT' => 'Other main workers', 'MARG_CL' => 'Marginal cultivators', 'MARG_AL' => 'Marginal agricultural labourers', 'MARG_HH' => 'Marginal household industry workers', 'MARG_OT' => 'Other marginal workers'] as $prefix => $description) {
                $known[$prefix.'_'.$sex] = $description.' · '.strtolower($label);
            }
        }
        $result = [];
        if (count(array_intersect(['TOT_P', 'P_06', 'P_LIT'], $keys)) === 3) {
            $result['literacy'] = ['label' => 'Literacy (%) · ages 7+', 'topic' => 'education', 'derived' => true, 'editable' => false];
        }
        foreach ($keys as $key) {
            $label = $known[$key] ?? (is_string($labels[$key] ?? null) ? $labels[$key] : str_replace('_', ' ', $key));
            $text = strtoupper($key.' '.$label);
            $topic = match (true) {
                (bool) preg_match('/LIT|SCHOOL|SCH\b|EDUC|COLLEGE|PRI_S|MID_S|SEC_S|HIGH_S|TRAIN/', $text) => 'education',
                (bool) preg_match('/WATER|DRINK|WELL|HANDPUMP|TAP|RIVER|CANAL|SPRING|LAKE|TUBE|SOU_SUMM/', $text) => 'water',
                (bool) preg_match('/HEALTH|HOSP|MEDIC|DISPEN|PHC|CHC/', $text) => 'health',
                (bool) preg_match('/WORK|CULT|LABOUR|INDUSTR/', $text) => 'work',
                (bool) preg_match('/HOUSEHOLD|NO_HH|^HH /', $text) => 'households',
                (bool) preg_match('/POPULATION|TOT_|P_06|M_06|F_06|P_SC|M_SC|F_SC|P_ST|M_ST|F_ST/', $text) => 'population',
                (bool) preg_match('/FAC|POST|ROAD|BANK|POWER|ELECTR|BUS|PHONE|RAIL/', $text) => 'facilities',
                default => 'other',
            };
            $result[$key] = ['label' => $label, 'topic' => $topic, 'derived' => false, 'editable' => $this->editable($key) && $this->editable(str_replace(' ', '_', strtoupper($label))) && ! preg_match('/(?:state|district|village|town|tehsil|tahsil|block|ward).*\bcode\b/i', $label)];
        }

        if (isset($edition->name) && isset($edition->edition_id)) {
            $result['listing_name'] = ['label' => 'Place name', 'topic' => 'other', 'derived' => false, 'editable' => true];
        }

        return $result;
    }

    public function value(array $values, string $field): mixed
    {
        if ($field !== 'literacy') {
            return $values[$field] ?? null;
        }
        foreach (['TOT_P', 'P_06', 'P_LIT'] as $key) {
            if (! is_numeric($values[$key] ?? null) || $values[$key] < 0) {
                return null;
            }
        }
        $denominator = (float) $values['TOT_P'] - (float) $values['P_06'];

        return $denominator > 0 && $values['P_LIT'] <= $denominator ? round(100 * $values['P_LIT'] / $denominator, 2) : null;
    }

    public function quickFacts(Builder $query, string $field): array
    {
        if (DB::getDriverName() === 'pgsql' && preg_match('/^[A-Za-z0-9_]+$/D', $field)) {
            return $this->databaseFacts($query, $field);
        }
        $total = 0;
        $available = 0;
        $lowest = null;
        $highest = null;
        foreach ((clone $query)->select('r.id', 'r.name', 'r.values')->orderBy('r.id')->cursor() as $row) {
            $total++;
            $value = $this->value(json_decode($row->values, true), $field);
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                continue;
            }
            $available++;
            $entry = ['id' => $row->id, 'name' => $row->name, 'value' => (float) $value, 'ties' => 1];
            if ($lowest === null || $value < $lowest['value']) {
                $lowest = $entry;
            } elseif ((float) $value === $lowest['value']) {
                $lowest['ties']++;
            }
            if ($highest === null || $value > $highest['value']) {
                $highest = $entry;
            } elseif ((float) $value === $highest['value']) {
                $highest['ties']++;
            }
        }

        return compact('total', 'available', 'lowest', 'highest');
    }

    private function databaseFacts(Builder $query, string $field): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $keys = $field === 'literacy' ? ['TOT_P', 'P_06', 'P_LIT'] : [$field];
        $columns = [];
        $conditions = [];
        foreach ($keys as $key) {
            $column = $grammar->wrap('r.values->'.$key);
            $columns[$key] = "($column)::numeric";
            $conditions[] = "$column ~ '^[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'";
        }
        $valid = implode(' AND ', $conditions);
        if ($field === 'literacy') {
            $population = $columns['TOT_P'];
            $children = $columns['P_06'];
            $literate = $columns['P_LIT'];
            $expression = "CASE WHEN $valid THEN CASE WHEN $population > $children AND $literate <= ($population - $children) THEN ROUND(100 * $literate / NULLIF($population - $children,0),2) END END";
        } else {
            $expression = 'CASE WHEN '.$valid.' THEN '.$columns[$field].' END';
        }
        $total = (clone $query)->count();
        $usable = (clone $query)->whereRaw("($expression) IS NOT NULL");
        $available = (clone $usable)->count();
        $extremes = [];
        foreach (['lowest' => 'asc', 'highest' => 'desc'] as $name => $direction) {
            $row = (clone $usable)->select('r.id', 'r.name')->selectRaw("($expression) AS fact_value")->orderByRaw("($expression) $direction")->orderBy('r.id')->first();
            $extremes[$name] = $row ? ['id' => $row->id, 'name' => $row->name, 'value' => (float) $row->fact_value,
                'ties' => (clone $usable)->whereRaw("($expression) = ?", [$row->fact_value])->count()] : null;
        }

        return ['total' => $total, 'available' => $available, ...$extremes];
    }

    public function isRankable(string $field, array $definition): bool
    {
        return $field === 'literacy' || (bool) preg_match('/^(TOT_[PMF]|[PMF]_(06|LIT|ILL|SC|ST|WORK|MAIN|MARG|NON)|No_HH|HH$)/', $field)
            || (bool) preg_match('/number of|count|population|households/i', $definition['label']);
    }
}
