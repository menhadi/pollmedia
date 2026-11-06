<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MyPlaceFacts
{
    public function district(?array $identity): array
    {
        $facts = ['population' => null, 'households' => null, 'literacy' => null, 'census_source' => null, 'census_name' => null, 'water' => null, 'water_source' => null, 'census_status' => 'no_identity', 'scope' => 'district'];
        if (! $identity) {
            return $facts;
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published')->where('e.year', 2011)
            ->where('c.state_code', $identity['state'])->where('c.district_code', $identity['district'])
            ->where('c.level', 'DISTRICT')->where('c.residence', 'Total')
            ->select('c.name', 'c.values', 'e.source_url')->get();
        $values = [];
        $conflicting = false;
        foreach (['TOT_P', 'No_HH', 'P_LIT', 'P_06'] as $field) {
            $candidates = $rows->map(fn ($row) => json_decode($row->values, true)[$field] ?? null)
                ->filter(fn ($value) => is_numeric($value) && $value >= 0)->map(fn ($value) => (float) $value)->unique()->values();
            $values[$field] = $candidates->count() === 1 ? $candidates[0] : null;
            $conflicting = $conflicting || $candidates->count() > 1;
        }
        $facts['population'] = $values['TOT_P'];
        $facts['households'] = $values['No_HH'];
        $facts['literacy'] = CensusAreaPresentation::rates($values)['literacy'];
        $facts['census_name'] = $rows->first()?->name;
        $facts['census_source'] = $rows->first()?->source_url;
        $facts['census_status'] = $rows->isEmpty() ? 'no_record' : ($conflicting ? 'conflicting_values' : 'available');

        return $facts;
    }

    public function village(array $village, string $state): array
    {
        $facts = ['population' => null, 'households' => null, 'literacy' => null, 'census_source' => null, 'census_name' => null, 'water' => null, 'water_source' => null, 'census_status' => 'no_record'];
        try {
            $water = app(WaterMapData::class)->records([['feature_index' => 0, 'state' => $state, 'lgd' => $village['code']]])[0] ?? null;
        } catch (HttpExceptionInterface|\UnexpectedValueException) {
            $water = null;
        }
        $facts['water'] = $water;
        $facts['water_source'] = $water ? ['url' => $water['source_url'] ?? null, 'retrieved_at' => $water['retrieved_at'] ?? ''] : null;
        $code = (string) ($village['census2011'] ?? '');
        if (! ctype_digit($code) || (int) $code === 0 || (int) $code >= 999999) {
            $facts['census_status'] = 'no_identity';

            return $facts;
        }
        $states = match ($state) {
            '36' => ['28'], '37' => ['01'], '38' => ['25', '26'],
            default => [str_pad($state, 2, '0', STR_PAD_LEFT)],
        };
        $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT DISTINCT lgd,state FROM directory WHERE CAST(census2011 AS INTEGER)=?');
        $query->execute([(int) $code]);
        $matches = array_filter($query->fetchAll(\PDO::FETCH_ASSOC), fn ($row) => in_array((string) $row['state'], [$state], true));
        if (count($matches) !== 1 || (string) reset($matches)['lgd'] !== (string) $village['code']) {
            $facts['census_status'] = 'conflicting_identity';

            return $facts;
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published')->where('e.year', 2011)->whereIn('c.state_code', $states)
            ->where(function ($query): void {
                $query->where(fn ($rural) => $rural->where('c.level', 'VILLAGE')->where('c.residence', 'Rural'))
                    ->orWhere(fn ($urban) => $urban->where('c.level', 'TOWN')->where('c.residence', 'Urban'));
            })->where('c.geography->Town/Village', str_pad($code, 6, '0', STR_PAD_LEFT))
            ->select('c.name', 'c.state_code', 'c.district_code', 'c.geography', 'c.values', 'e.source_url')->get();
        $identities = $rows->map(function ($row): string {
            $geo = json_decode($row->geography, true);

            return implode(':', [$row->state_code, $row->district_code, $geo['Subdistt'] ?? '', $geo['Town/Village'] ?? '']);
        })->unique();
        if ($identities->count() !== 1) {
            $facts['census_status'] = $rows->isEmpty() ? 'no_record' : 'conflicting_identity';

            return $facts;
        }
        $values = [];
        $conflicting = false;
        foreach (['TOT_P', 'No_HH', 'P_LIT', 'P_06'] as $field) {
            $candidates = $rows->map(fn ($row) => json_decode($row->values, true)[$field] ?? null)->filter(fn ($value) => is_numeric($value) && $value >= 0)->map(fn ($value) => (float) $value)->unique()->values();
            $values[$field] = $candidates->count() === 1 ? $candidates[0] : null;
            $conflicting = $conflicting || $candidates->count() > 1;
        }
        $facts['population'] = $values['TOT_P'];
        $facts['households'] = $values['No_HH'];
        $facts['literacy'] = CensusAreaPresentation::rates($values)['literacy'];
        $facts['census_name'] = $rows->first()->name;
        $facts['census_source'] = $rows->first()->source_url;
        $facts['census_status'] = $conflicting ? 'conflicting_values' : 'available';

        return $facts;
    }
}
