<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaceFactTables
{
    private const SOIL_CYCLE = '2025-26';

    public function state(string $slug, string $name): array
    {
        return Cache::remember('place-facts:v6:state:'.$slug, 300, function () use ($slug, $name): array {
            $sections = [];
            $code = $this->stateCode($slug);
            $landing = app(CensusProfileSummary::class)->landing();
            $parent = $code === null ? null : $landing['places']->first(fn (object $row): bool => (int) $row->state_code === (int) $code);
            $districts = $parent ? DB::table('census_catalogue_rows')->where('edition_id', $parent->edition_id)->where('state_code', $parent->state_code)->where('level', 'DISTRICT')->where('residence', 'Total')->orderBy('id')->get()->unique('district_code') : collect();
            $editions = DB::table('census_editions')->whereIn('id', $districts->pluck('edition_id'))->get(['id', 'year', 'source_url'])->keyBy('id');
            $districts = $districts->filter(fn (object $row): bool => (int) $editions->get($row->edition_id)?->year === 2011);
            if ($districts->isNotEmpty()) {
                $parent->source_url = $editions->get($parent->edition_id)?->source_url;
                $section = $this->ownCensus($this->censusRow($parent), $name, 'Census 2011');
                $section['note'] .= ' Source state: '.$parent->name.'. These are the original 2011 boundaries; current state and union-territory boundaries may differ.';
                $sections[] = $section;
                $rows = $districts->map(function (object $row) use ($editions): array {
                    $row->source_url = $editions->get($row->edition_id)?->source_url;

                    return $this->censusRow($row);
                })->all();
                $sections[] = $this->comparisons($rows, 'district', $name, 'Census 2011', [['population', 'population', 'people', 1], ['literacy', 'literacy rate (age 7+)', '%', 0]], 'Comparisons use available published Census 2011 district records and their original geography. They do not rank present-day districts or estimate current population.');
            } else {
                $sections[] = $this->unavailable('Population and education', 'A uniquely linked published Census state directory is unavailable. Historical state boundaries are not substituted.');
            }
            if ($code !== null && is_file(storage_path('app/jjm/national/linked.sqlite'))) {
                $db = new \PDO('sqlite:file:'.storage_path('app/jjm/national/linked.sqlite').'?mode=ro');
                $db->exec('PRAGMA query_only=ON');
                $query = $db->prepare("SELECT json_extract(payload,'$.district') AS name, COUNT(*) AS villages, SUM(json_extract(payload,'$.households')) AS households, SUM(json_extract(payload,'$.tap_connections')) AS taps, MIN(json_extract(payload,'$.retrieved_at')) AS first_date, MAX(json_extract(payload,'$.retrieved_at')) AS last_date, MIN(json_extract(payload,'$.source_url')) AS source_url FROM water WHERE state=? AND json_type(payload,'$.households') IN ('integer','real') AND json_type(payload,'$.tap_connections') IN ('integer','real') AND json_extract(payload,'$.households')>0 AND json_extract(payload,'$.tap_connections') BETWEEN 0 AND json_extract(payload,'$.households') GROUP BY json_extract(payload,'$.district')");
                $query->execute([$code]);
                $water = array_map(fn (array $row): array => $row + ['coverage' => round(100 * $row['taps'] / $row['households'], 2), 'period' => 'JJM retrieved '.$row['first_date'].' to '.$row['last_date']], $query->fetchAll(\PDO::FETCH_ASSOC));
                $sections[] = $this->comparisons($water, 'source district', $name, 'JJM stored snapshot', [['coverage', 'household tap-connection coverage', '%', 0]], 'Coverage is reported tap connections divided by reported households in available stored village observations. Groups retain JJM source geography. These are linked-record summaries, not certified complete district totals. Missing records are excluded, never zero-filled.');
                $manifest = $this->soilManifest();
                $file = $manifest['cycles'][self::SOIL_CYCLE]['summaries'] ?? null;
                if ($file) {
                    $summary = $this->soilJson($file);
                    $rows = array_values(array_filter($summary['districts'] ?? [], fn (array $row): bool => (string) ($row['source_ids']['state']['code'] ?? '') === $code));
                    $sections[] = $this->soilSection($rows, 'source district', $name);
                }
            } else {
                $sections[] = $this->unavailable('Water and soil comparisons', 'A unique official state identity or the stored source index is unavailable. No historical or neighbouring state is substituted.');
            }

            return ['name' => $name, 'scope' => 'state', 'sections' => $sections];
        });
    }

    public function district(array $data): array
    {
        $total = $data['totals']['Total'];

        return Cache::remember('place-facts:v6:district:'.$total['id'], 300, function () use ($data, $total): array {
            $name = $data['name'].' district';
            $period = 'Census '.$data['year'];
            $villages = array_map(fn (array $row): array => $row + ['entity_url' => route('civic.place', ['record' => $row['id']])], $data['census_villages']);
            $sections = [$this->ownCensus($total, $name, $period), $this->comparisons($villages, 'village', $name, $period, [['population', 'population among inhabited villages', 'people', 1], ['households', 'household count', 'households', 0], ['literacy', 'literacy rate (age 7+)', '%', 0]], count($villages).' available published rural village records. Rankings exclude missing values. Population comparisons exclude uninhabited villages. Equal values are ties, not a single winner.')];
            $sections[1]['title'] = $period.' · Village comparisons';
            foreach (['towns' => 'town', 'subdistricts' => 'subdistrict'] as $key => $entity) {
                $comparison = $this->comparisons($data[$key] ?? [], $entity, $name, $period, [['population', 'population', 'people', 1], ['households', 'household count', 'households', 0], ['literacy', 'literacy rate (age 7+)', '%', 0]], 'Available published '.$period.' '.$entity.' records only.');
                $comparison['title'] = $period.' · '.ucfirst($entity).' comparisons';
                $sections[] = $comparison;
            }
            $state = (string) DB::table('census_catalogue_rows')->where('id', $total['id'])->value('state_code');
            $identities = app(WaterMapData::class)->publishedIdentities(array_map(fn (array $row): array => ['feature_index' => $row['id'], 'census_code' => $row['code'], 'state_code' => $state, 'level' => 'VILLAGE', 'residence' => 'Rural'], $villages), app(ElectoralVillageMap::class)->database());
            $identities = WaterMapData::uniqueIdentities($identities);
            $water = [];
            foreach (app(WaterMapData::class)->records($identities) as $row) {
                $water[] = $row + ['coverage' => $row['coverage_pct'], 'period' => 'JJM retrieved '.($row['retrieved_at'] ?? 'date unavailable'), 'entity_url' => route('electoral-villages.village', ['code' => $row['lgd_village_code']])];
            }
            $water = array_values(array_filter($water, fn (array $row): bool => is_numeric($row['households'] ?? null) && $row['households'] > 0 && is_numeric($row['tap_connections'] ?? null) && $row['tap_connections'] >= 0 && $row['tap_connections'] <= $row['households'] && is_numeric($row['coverage'] ?? null) && $row['coverage'] >= 0 && $row['coverage'] <= 100));
            $sections[] = $this->comparisons($water, 'village', $name, 'JJM stored snapshot', [['coverage', 'household tap-connection coverage', '%', 0]], count($water).' stored observations among '.count($villages).' published Census rural village records; '.count($identities).' have a unique official Census-to-LGD correspondence. Names retain JJM source identity. JJM dates are independent of Census year. These compare available records, not complete current district coverage.');
            $sections[] = $this->soilSection($this->soilVillages($identities), 'village', $name);

            return ['name' => $name, 'scope' => 'district', 'sections' => $sections];
        });
    }

    public function village(array $village, ?array $water, array $codes, ?object $place = null, ?array $census = null): array
    {
        $name = $village['name'] ?? $place?->name ?? 'Village';
        $sections = [];
        if ($place && ($place->level ?? '') === 'VILLAGE') {
            $edition = DB::table('census_editions')->where('id', $place->edition_id)->first(['year', 'source_url']);
            $row = $this->censusRow($place);
            $row['source_url'] = $edition?->source_url;
            $sections[] = $this->ownCensus($row, $name, 'Census '.($edition?->year ?? 'year unavailable'));
        } elseif (isset($village['census'])) {
            $row = $village['census'] + ['source_url' => $village['census_source'] ?? null];
            $row['literacy'] = $row['literacy_rate'] ?? null;
            $sections[] = $this->ownCensus($row, $name, 'Census 2011');
        } elseif ($census && isset($village['population'])) {
            $row = $village + ['source_url' => $census['source_url'] ?? null];
            $denominator = isset($row['population'], $row['population_0_6']) ? $row['population'] - $row['population_0_6'] : null;
            $row['literacy'] = $denominator > 0 && isset($row['literate']) && $row['literate'] <= $denominator ? round(100 * $row['literate'] / $denominator, 2) : null;
            $sections[] = $this->ownCensus($row, $name, 'Census '.$census['year']);
        } else {
            $sections[] = $this->unavailable('Population and education', 'No uniquely linked published Census population and literacy record is available for this profile.');
        }
        $rows = [];
        foreach (['households' => ['JJM households', 'households'], 'tap_connections' => ['Household tap connections', 'connections'], 'coverage_pct' => ['Household tap coverage', '%']] as $key => [$question, $unit]) {
            $rows[] = $this->fact($question, $water[$key] ?? null, $unit, 'JJM retrieved '.($water['retrieved_at'] ?? 'date unavailable'), $water['source_url'] ?? null);
        }
        $sections[] = ['title' => 'Water facts', 'note' => $water ? 'JJM has its own retrieval date and is independent of Census year. Source village: '.($water['name'] ?? $name).'.' : (count(array_unique($codes)) === 1 ? 'No accepted stored JJM observation is linked to this village.' : 'No unique official village identity is available for this profile.').' Missing values remain unavailable, not zero.', 'rows' => $rows];
        $identities = [];
        if (count(array_unique($codes)) === 1) {
            $query = app(ElectoralVillageMap::class)->database()->prepare('SELECT lgd,state FROM directory WHERE lgd=?');
            $query->execute([(string) reset($codes)]);
            $identities = $query->fetchAll(\PDO::FETCH_ASSOC);
        }
        $soil = $this->soilVillages($identities);
        if (count($soil) === 1) {
            $row = $soil[0];
            $rows = [];
            foreach ($this->soilIndicators() as [$prefix, $categories, $category, $label]) {
                $metric = self::soilValue($row['counts'], $prefix, $categories, $category);
                $rows[] = $this->fact(ucfirst($label).' · tested samples', $metric['value'], '%', 'Cycle '.self::SOIL_CYCLE.' · '.($metric['samples'] ?? 'unknown').' tested samples · retrieved '.$row['retrieved_at'], $row['source_url']);
            }
            $sections[] = ['title' => 'Soil sample facts', 'note' => 'Soil Health Card '.self::SOIL_CYCLE.'. Counts describe tested samples, not all land or crop suitability. Each indicator has its own denominator.', 'rows' => $rows];
        } else {
            $sections[] = $this->unavailable('Soil facts', 'No unique officially linked Soil Health Card village record is available for the '.self::SOIL_CYCLE.' reporting cycle. This does not mean the soil is good or poor.');
        }

        return ['name' => $name, 'scope' => 'village', 'sections' => $sections];
    }

    public static function extremes(array $rows, string $key, float $minimum = 0): array
    {
        $valid = array_values(array_filter($rows, fn (array $row): bool => isset($row[$key]) && is_numeric($row[$key]) && is_finite((float) $row[$key]) && $row[$key] >= $minimum));
        usort($valid, fn (array $a, array $b): int => ($a[$key] <=> $b[$key]) ?: strcasecmp($a['name'], $b['name']));
        if ($valid === []) {
            return ['count' => 0, 'lowest' => [], 'highest' => []];
        }

        return ['count' => count($valid), 'lowest' => array_values(array_filter($valid, fn (array $row): bool => (float) $row[$key] === (float) $valid[0][$key])), 'highest' => array_values(array_filter($valid, fn (array $row): bool => (float) $row[$key] === (float) $valid[array_key_last($valid)][$key]))];
    }

    public static function soilValue(array $counts, string $prefix, array $categories, string $category): array
    {
        $total = 0;
        foreach ($categories as $name) {
            $value = $counts[$prefix.'_'.$name] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0) {
                return ['value' => null, 'samples' => null];
            }
            $total += $value;
        }

        return ['value' => $total > 0 ? round(100 * $counts[$prefix.'_'.$category] / $total, 2) : null, 'samples' => $total];
    }

    private function comparisons(array $rows, string $entity, string $name, string $period, array $metrics, string $note): array
    {
        $facts = [];
        foreach ($metrics as [$key, $label, $unit, $minimum]) {
            if ($unit === '%') {
                $rows = array_values(array_filter($rows, fn (array $row): bool => ! isset($row[$key]) || (is_numeric($row[$key]) && $row[$key] <= 100)));
            }
            $rank = self::extremes($rows, $key, $minimum);
            foreach (['highest', 'lowest'] as $direction) {
                $ties = $rank[$direction];
                $examples = array_slice($ties, 0, 3);
                $value = $ties[0][$key] ?? null;
                $answer = $value === null ? 'Unavailable: no comparable reported values.' : $this->formatted($value, $unit).' — '.implode(', ', array_column($examples, 'name')).(count($ties) > 3 ? ' and '.(count($ties) - 3).' other '.$entity.' records' : '').'. '.count($ties).' '.(count($ties) === 1 ? 'record has' : 'records share').' this value; '.$rank['count'].' records compared.';
                $factLabel = ucfirst($direction).' '.$label.' · '.$entity;
                if ($key === 'population') {
                    $factLabel = ($direction === 'highest' ? 'Largest ' : ($entity === 'village' ? 'Smallest inhabited ' : 'Smallest ')).$entity.' by population';
                }
                $placeNames = $value === null ? 'Unavailable' : (count($ties) === $rank['count'] && count($ties) > 1 ? 'All '.$rank['count'].' comparable '.$entity.'s' : implode(', ', array_column($examples, 'name')).(count($ties) > 3 ? ' + '.(count($ties) - 3).' tied' : ''));
                if ($direction === 'lowest' && $rank['count'] > 1 && count($ties) === $rank['count']) {
                    continue;
                }
                $facts[] = ['label' => $factLabel, 'place' => $placeNames, 'value' => $value === null ? 'Unavailable' : $this->formatted($value, $unit), 'question' => 'Which '.$entity.'s have the '.$direction.' '.$label.' in '.$name.' ('.$period.')?', 'answer' => $answer, 'period' => $examples[0]['period'] ?? $period, 'source_url' => $examples[0]['source_url'] ?? null, 'links' => array_values(array_filter(array_map(fn (array $row): array => ['name' => $row['name'], 'url' => $row['entity_url'] ?? null], $examples), fn (array $row): bool => $row['url'] !== null)), 'references' => array_map(fn (array $row): array => ['name' => $row['name'], 'period' => $row['period'] ?? $period, 'url' => $row['source_url'] ?? null], $examples)];
            }
        }

        return ['title' => $period.' comparisons', 'note' => $note, 'rows' => $facts];
    }

    private function ownCensus(array $row, string $name, string $period): array
    {
        return ['title' => 'Population and education facts', 'note' => 'These are dated Census figures. Literacy uses people aged seven and above; it is not a measure of school quality.', 'rows' => [$this->fact('Population', $row['population'] ?? null, 'people', $period, $row['source_url'] ?? null), $this->fact('Households', $row['households'] ?? null, 'households', $period, $row['source_url'] ?? null), $this->fact('Literacy rate (age 7+)', $row['literacy'] ?? null, '%', $period, $row['source_url'] ?? null)]];
    }

    private function censusRow(object $row): array
    {
        $metrics = VillageMapMetrics::values(json_decode($row->values, true));

        return ['name' => $row->name, 'population' => $metrics['population'], 'households' => $metrics['households'], 'literacy' => $metrics['literacy'], 'source_url' => $row->source_url ?? null, 'entity_url' => route('civic.place', ['record' => $row->id])];
    }

    private function fact(string $question, mixed $value, string $unit, string $period, ?string $source): array
    {
        return ['label' => $question, 'place' => null, 'value' => is_numeric($value) ? $this->formatted($value, $unit) : 'Unavailable', 'question' => $question, 'answer' => is_numeric($value) ? $this->formatted($value, $unit) : 'Unavailable: no verified reported value.', 'period' => $period, 'source_url' => $source, 'links' => []];
    }

    private function formatted(float|int|string $value, string $unit): string
    {
        if ((float) $value === 1.0) {
            $unit = match ($unit) {
                'people' => 'person', 'households' => 'household', 'connections' => 'connection',
                default => $unit,
            };
        }

        return number_format((float) $value, $unit === '%' ? 2 : 0).($unit === '%' ? '%' : ' '.$unit);
    }

    private function unavailable(string $title, string $reason): array
    {
        return ['title' => $title, 'note' => $reason, 'rows' => []];
    }

    private function stateCode(string $slug): ?string
    {
        $states = app(ElectoralVillageMap::class)->catalogue()['states'];
        $matches = array_values(array_filter($states, fn (array $row): bool => self::stateSlug($row['name']) === $slug));

        return count($matches) === 1 ? (string) $matches[0]['code'] : null;
    }

    public static function stateSlug(string $name): string
    {
        return Str::slug(preg_replace('/^the\s+/i', '', str_replace('&', 'and', $name)));
    }

    private function soilManifest(): array
    {
        return $this->soilJson('manifest.json');
    }

    private function soilJson(string $file): array
    {
        if (basename($file) !== $file) {
            return [];
        }
        $path = public_path('maps/soil-national-20261014/'.$file);

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function soilVillages(array $identities): array
    {
        $manifest = $this->soilManifest();
        $wanted = [];
        foreach ($identities as $row) {
            $wanted[(string) $row['lgd']] = (string) $row['state'];
        }
        $records = [];
        foreach (array_unique(array_map(fn (string $code): string => substr($code, 0, $manifest['prefix_length'] ?? 3), array_map('strval', array_keys($wanted)))) as $prefix) {
            $entry = $manifest['cycles'][self::SOIL_CYCLE]['shards'][$prefix] ?? null;
            if (! $entry) {
                continue;
            }
            foreach ($this->soilJson($entry['file'])['records'] ?? [] as $row) {
                $code = (string) ($row['lgd_village_code'] ?? '');
                if (isset($wanted[$code]) && (string) ($row['source_ids']['state']['code'] ?? '') === $wanted[$code] && (string) ($row['source_ids']['village']['code'] ?? '') === $code) {
                    $records[$code][] = $row + ['entity_url' => route('electoral-villages.village', ['code' => $code])];
                }
            }
        }

        return array_map(fn (array $rows): array => $rows[0], array_values(array_filter($records, fn (array $rows): bool => count($rows) === 1)));
    }

    private function soilIndicators(): array
    {
        return [['N', ['Low', 'Medium', 'High'], 'Low', 'low nitrogen'], ['OC', ['Low', 'Medium', 'High'], 'Low', 'low organic carbon'], ['P H', ['Acidic', 'Alkaline', 'Neutral'], 'Acidic', 'acidic soil'], ['EC', ['Saline', 'Non Saline'], 'Saline', 'saline soil']];
    }

    private function soilSection(array $rows, string $entity, string $name): array
    {
        $facts = [];
        $eligible = 0;
        foreach ($this->soilIndicators() as [$prefix, $categories, $category, $label]) {
            $comparable = [];
            foreach ($rows as $row) {
                $value = self::soilValue($row['counts'], $prefix, $categories, $category);
                if (($value['samples'] ?? 0) >= 10) {
                    $eligible++;
                    $comparable[] = $row + ['soil_share' => $value['value'], 'period' => 'Cycle '.self::SOIL_CYCLE.' · '.$value['samples'].' tested samples · retrieved '.$row['retrieved_at']];
                }
            }
            $section = $this->comparisons($comparable, $entity, $name, 'Soil Health Card '.self::SOIL_CYCLE, [['soil_share', 'share of tested samples reporting '.$label, '%', 0]], '');
            $facts = array_merge($facts, $section['rows']);
        }

        if ($eligible === 0) {
            return $this->unavailable('Soil sample comparisons · '.self::SOIL_CYCLE, 'No uniquely linked records have enough complete indicator counts for comparison in this cycle. At least 10 tested samples are required; missing or smaller samples are not ranked.');
        }

        return ['title' => 'Soil sample comparisons · '.self::SOIL_CYCLE, 'note' => 'Comparisons use the same stored 2025–26 cycle and require at least 10 tested samples for the indicator. Counts are shown for each answer. This does not rank all soil, agricultural productivity or crop suitability. Missing or ambiguous links are excluded; reporting may be partial.', 'rows' => $facts];
    }
}
