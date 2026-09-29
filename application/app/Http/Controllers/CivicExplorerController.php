<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CivicExplorerController extends Controller
{
    private function level(string $level): string
    {
        return in_array($level, ['SUBDISTRICT', 'TEHSIL'], true) ? 'SUB-DISTRICT' : $level;
    }

    private function codes(object $row): array
    {
        $geo = json_decode($row->geography, true);

        return [$row->state_code, $row->district_code, (string) ($geo['Subdistt'] ?? $geo['TAHSIL'] ?? ''),
            (string) ($geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? ''), (string) ($geo['Ward'] ?? $geo['WARD'] ?? '')];
    }

    private function scope(Builder $query, array $codes, int $depth, int $year): Builder
    {
        $columns = $year === 2001 ? ['state_code', 'district_code', 'geography->TAHSIL', 'geography->TOWN_VILL', 'geography->WARD']
            : ['state_code', 'district_code', 'geography->Subdistt', 'geography->Town/Village', 'geography->Ward'];
        foreach (array_slice($columns, 0, $depth) as $i => $column) {
            $query->where($column, $codes[$i]);
        }

        return $query;
    }

    private function depth(string $level): int
    {
        return match ($this->level($level)) {
            'STATE' => 1, 'DISTRICT' => 2, 'SUB-DISTRICT' => 3, 'TOWN', 'VILLAGE' => 4, 'WARD' => 5, default => 0,
        };
    }

    public function index(Request $request, ?int $record = null): View
    {
        $input = $request->validate(['year' => 'nullable|integer|min:1800|max:2100', 'edition' => 'nullable|integer',
            'residence' => 'nullable|in:Total,Rural,Urban', 'group' => 'nullable|in:population,households,literacy,work',
            'q' => 'nullable|string|max:100']);
        $editions = DB::table('census_editions')->where('status', 'published')->orderByDesc('year')->orderBy('id')->get();
        $years = $editions->pluck('year')->unique()->values();
        $anchor = $record ? DB::table('census_catalogue_rows')->where('id', $record)->whereIn('edition_id', $editions->pluck('id'))->first() : null;
        abort_if($record && ! $anchor, 404);
        $anchorEdition = $anchor ? $editions->firstWhere('id', $anchor->edition_id) : null;
        $year = (int) ($anchorEdition?->year ?? $input['year'] ?? $years->first() ?? 2011);
        abort_if($anchor && isset($input['year']) && (int) $input['year'] !== $year, 422, 'Choose another year from the Census explorer; historical place identities require a verified crosswalk.');
        $availableEditions = $editions->where('year', $year)->values();
        $edition = isset($input['edition']) ? $availableEditions->firstWhere('id', (int) $input['edition'])
            : ($anchorEdition ?? $availableEditions->firstWhere('source_key', 'india-basic-'.$year.'-total') ?? $availableEditions->first());
        abort_if(isset($input['edition']) && ! $edition, 404);
        $group = $input['group'] ?? 'population';
        $residence = $input['residence'] ?? $anchor?->residence ?? 'Total';
        $base = DB::table('census_catalogue_rows')->where('edition_id', $edition?->id ?? 0);
        $residenceOptions = (clone $base)->distinct()->pluck('residence');
        if (! $residenceOptions->contains($residence) && $residenceOptions->isNotEmpty()) {
            $residence = $residenceOptions->contains('Total') ? 'Total' : $residenceOptions->first();
        }
        $place = $anchor;
        $villageBrowse = null;
        if ($anchor && $anchor->state_code === '09' && (($year === 2011 && $anchor->district_code === '151') || ($year === 2001 && $anchor->district_code === '21'))) {
            $villageRelease = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                ->where('s.key', 'census-pilibhit-villages-'.$year)->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
            if ($villageRelease) {
                $dataset = json_decode($villageRelease, true);
                $subdistrict = $this->codes($anchor)[2];
                if ($anchor->level === 'DISTRICT' || collect($dataset['subdistricts'] ?? [])->contains('code', $subdistrict)) {
                    $villageBrowse = ['year' => $year];
                    if ($this->level($anchor->level) === 'SUB-DISTRICT') {
                        $villageBrowse['subdistrict'] = $subdistrict;
                    }
                }
            }
        }
        $records = collect();
        $parents = collect();
        $linked = collect();
        $matchedPlace = null;
        $codes = $anchor ? $this->codes($anchor) : [];
        $depth = $anchor ? $this->depth($anchor->level) : 0;
        abort_if($anchor && $depth === 0, 404);
        if ($anchor) {
            $identity = $this->scope(clone $base, $codes, $depth, $year)->whereIn('level', $this->level($anchor->level) === 'SUB-DISTRICT' ? ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'] : [$anchor->level]);
            $records = $identity->where('residence', $residence)->get();
            $parentLevels = ['STATE', 'DISTRICT', 'SUB-DISTRICT', 'TOWN'];
            for ($i = 1; $i < $depth; $i++) {
                $levels = $i === 3 ? ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'] : [$parentLevels[$i - 1]];
                $parent = $this->scope(DB::table('census_catalogue_rows')->where('edition_id', $anchor->edition_id), $codes, $i, $year)
                    ->whereIn('level', $levels)->orderByRaw("CASE WHEN residence = 'Total' THEN 0 ELSE 1 END")->first();
                if ($parent) {
                    $parents->push($parent);
                }
            }
            // This namespace is explicitly source-backed; names alone never establish joins.
            if ($year === 2011 && $anchor->state_code === '09' && $anchor->level === 'DISTRICT') {
                $matches = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                    ->where('r.status', 'accepted')->where('i.namespace', 'census:district:IN:UP')->where('i.version', '2011')
                    ->where('i.code', $anchor->district_code)->distinct()->pluck('i.place_id');
                if ($matches->count() === 1) {
                    $matchedPlace = DB::table('places')->find($matches->first());
                    $relations = DB::table('place_relationships as l')->join('source_releases as r', 'r.id', '=', 'l.source_release_id')
                        ->where('r.status', 'accepted')->where(fn ($q) => $q->where('l.from_place_id', $matchedPlace->id)->orWhere('l.to_place_id', $matchedPlace->id))
                        ->where(fn ($q) => $q->whereNull('l.valid_from')->orWhere('l.valid_from', '<=', today()->toDateString()))
                        ->where(fn ($q) => $q->whereNull('l.valid_to')->orWhere('l.valid_to', '>', today()->toDateString()))->select('l.*', 'r.url')->get();
                    foreach ($relations as $relation) {
                        $other = DB::table('places')->find($relation->from_place_id == $matchedPlace->id ? $relation->to_place_id : $relation->from_place_id);
                        if ($other) {
                            $linked->push(['place' => $other, 'source' => $relation->url]);
                        }
                    }
                }
            }
        }
        $linked = $linked->groupBy(fn ($item) => $item['place']->id)->map(fn ($items) => ['place' => $items->first()['place'], 'sources' => $items->pluck('source')->unique()->values()])->values();
        $childLevels = match ($anchor ? $this->level($anchor->level) : null) {
            null => ['STATE'], 'STATE' => ['DISTRICT'], 'DISTRICT' => ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'],
            'SUB-DISTRICT' => ['TOWN', 'VILLAGE'], 'TOWN' => ['WARD'], default => [],
        };
        $children = $this->scope(clone $base, $codes, $depth, $year)->whereIn('level', $childLevels)
            ->where('residence', $residence)->when($input['q'] ?? null, fn ($q, $value) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($value).'%']))
            ->orderBy('name')->orderBy('id')->paginate(30)->withQueryString();
        $measures = match ($group) {
            'households' => ['No_HH' => 'Households'], 'literacy' => ['P_LIT' => 'Literate persons', 'M_LIT' => 'Literate males', 'F_LIT' => 'Literate females'],
            'work' => ['TOT_WORK_P' => 'Total workers', 'MAINWORK_P' => 'Main workers', 'MARGWORK_P' => 'Marginal workers'],
            default => ['TOT_P' => 'Population', 'TOT_M' => 'Male', 'TOT_F' => 'Female', 'P_06' => 'Children aged 0–6'],
        };
        $title = $place?->name ?? 'India Census';

        return view('civic-explorer', compact('input', 'editions', 'availableEditions', 'years', 'year', 'edition', 'group', 'residence', 'place', 'records', 'parents', 'linked', 'matchedPlace', 'children', 'childLevels', 'measures', 'title', 'residenceOptions', 'villageBrowse'));
    }
}
