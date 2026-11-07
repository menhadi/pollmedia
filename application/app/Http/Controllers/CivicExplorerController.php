<?php

namespace App\Http\Controllers;

use App\Services\CensusAreaPresentation;
use App\Services\CensusProfileSummary;
use App\Services\DistrictDashboardUrls;
use App\Services\ElectoralVillageMap;
use App\Services\NamedPlaceUrls;
use App\Services\PlaceFactTables;
use App\Services\PlaceTopics;
use App\Services\VillageSummaryPage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        return [$row->state_code, $row->district_code, (string) ($geo['Subdistt'] ?? $geo['TAHSIL'] ?? $geo['SUB-DISTT'] ?? ''),
            (string) ($geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? ''), (string) ($geo['Ward'] ?? $geo['WARD'] ?? '')];
    }

    private function scope(Builder $query, array $codes, int $depth, int $year): Builder
    {
        $columns = $year === 2001 ? ['state_code', 'district_code', 'geography->TAHSIL', 'geography->TOWN_VILL', 'geography->WARD']
            : ['state_code', 'district_code', 'geography->Subdistt', 'geography->Town/Village', 'geography->Ward'];
        foreach (array_slice($columns, 0, $depth) as $i => $column) {
            if ($year === 2001 && $i === 2) {
                $query->where(fn (Builder $q) => $q->where('geography->TAHSIL', $codes[$i])->orWhere('geography->SUB-DISTT', $codes[$i]));
            } else {
                $query->where($column, $codes[$i]);
            }
        }

        return $query;
    }

    private function depth(string $level): int
    {
        return match ($this->level($level)) {
            'STATE' => 1, 'DISTRICT' => 2, 'SUB-DISTRICT' => 3, 'TOWN', 'VILLAGE' => 4, 'WARD' => 5, default => 0,
        };
    }

    public function state(Request $request, string $state, ?int $census = null): View|RedirectResponse
    {
        $year = $census ?? 2011;
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.status', 'published')->where('e.year', $year)->where('c.level', 'STATE')->where('c.residence', 'Total')
            ->select('c.*')->orderByRaw("CASE WHEN e.source_key LIKE 'india-basic-%-total' THEN 0 ELSE 1 END")->orderBy('c.id')->get()
            ->filter(fn ($row) => PlaceFactTables::stateSlug($row->name) === $state || ($state === 'delhi' && (int) $row->state_code === 7));
        abort_unless($rows->pluck('state_code')->unique()->count() === 1, 404);

        $canonicalSlug = PlaceFactTables::stateSlug($rows->first()->name);
        if ($state !== $canonicalSlug) {
            return redirect()->route('civic.state', ['state' => $canonicalSlug, 'census' => $census] + $request->query(), 301);
        }

        return $this->index($request, (int) $rows->first()->id);
    }

    public function named(Request $request, string $kind, string $state, string $district, string $slug, int $record): View|RedirectResponse
    {
        $row = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('c.id', $record)->where('e.status', 'published')->select('c.*', 'e.year as census_year')->first();
        abort_unless($row, 404);
        $expectedKind = match ($this->level($row->level)) {
            'SUB-DISTRICT' => 'subdistricts', 'VILLAGE' => 'villages', 'TOWN' => 'towns', default => null,
        };
        abort_unless($kind === $expectedKind, 404);
        $canonical = app(NamedPlaceUrls::class)->fromPublishedCensusRow($row, false);
        if ($request->getPathInfo() !== $canonical) {
            return redirect(url($canonical).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }

        return $this->index($request, $record);
    }

    public function index(Request $request, ?int $record = null): View|RedirectResponse
    {
        $input = $request->validate(['year' => 'nullable|integer|min:1800|max:2100', 'edition' => 'nullable|integer',
            'residence' => 'nullable|in:Total,Rural,Urban', 'group' => 'nullable|in:population,households,literacy,work',
            'state' => 'nullable|string|max:100', 'q' => 'nullable|string|max:100']);
        $anchor = $record ? DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('c.id', $record)->where('e.status', 'published')->select('c.*')->first() : null;
        abort_if($record && ! $anchor, 404);
        $anchorEdition = $anchor ? DB::table('census_editions')->where('id', $anchor->edition_id)->first() : null;
        if ($anchor?->level === 'STATE' && ! $request->routeIs('civic.state')) {
            return redirect()->route('civic.state', ['state' => PlaceFactTables::stateSlug($anchor->name), 'census' => (int) $anchorEdition->year === 2011 ? null : (int) $anchorEdition->year] + $request->except('edition', 'year'), 301);
        }

        if ($anchor && (int) $anchorEdition?->year === 2011 && ! isset($input['edition'])) {
            if ($anchor->level === 'DISTRICT') {
                $dashboard = app(DistrictDashboardUrls::class)->identity($anchor->state_code, $anchor->district_code);
                if ($dashboard) {
                    return redirect(url($dashboard['path']), 301);
                }
            }
            if (in_array($anchor->level, ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'], true)) {
                $identity = $this->codes($anchor);
                $canonical = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                    ->where('e.status', 'published')->where('e.year', 2011)->where('c.state_code', $anchor->state_code)->where('c.district_code', $anchor->district_code)
                    ->where('c.geography->Subdistt', $identity[2])->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])->where('c.residence', 'Total')
                    ->orderByRaw("CASE WHEN e.source_key LIKE 'district-pca-2011-%' THEN 0 ELSE 1 END")->orderBy('c.id')->value('c.id');
                if ($canonical && (int) $canonical !== (int) $anchor->id) {
                    return redirect()->route('civic.place', ['record' => $canonical] + $request->except('edition'), 301);
                }
            }
        }
        if ($anchor && in_array($anchor->level, ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL', 'VILLAGE', 'TOWN'], true)) {
            $canonicalPath = app(NamedPlaceUrls::class)->census($anchor, false);
            if ($request->getPathInfo() !== $canonicalPath) {
                return redirect(url($canonicalPath).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
            }
        }
        $detailView = $anchor && $anchorEdition && in_array($this->level($anchor->level), ['STATE', 'SUB-DISTRICT', 'VILLAGE', 'TOWN'], true);
        $editions = $detailView ? collect([$anchorEdition]) : DB::table('census_editions')->where('status', 'published')->orderByDesc('year')->orderBy('id')
            ->get(['id', 'name', 'year', 'source_key', 'status']);
        $years = $editions->pluck('year')->unique()->values();
        $selectedEdition = isset($input['edition']) ? ($detailView
            ? DB::table('census_editions')->where('status', 'published')->where('id', (int) $input['edition'])->first()
            : $editions->firstWhere('id', (int) $input['edition'])) : null;
        $year = (int) ($anchorEdition?->year ?? $selectedEdition?->year ?? $input['year'] ?? $years->first() ?? 2011);
        abort_if($anchor && isset($input['year']) && (int) $input['year'] !== $year, 422, 'Choose another year from the Census explorer; historical place identities require a verified crosswalk.');
        $availableEditions = $record ? $editions->where('year', $year)->values() : $editions;
        if ($detailView && isset($input['edition'])) {
            $availableEditions = collect($selectedEdition && (int) $selectedEdition->year === $year ? [$selectedEdition] : []);
        }
        $edition = isset($input['edition']) ? $availableEditions->firstWhere('id', (int) $input['edition'])
            : ($anchorEdition ?? $availableEditions->firstWhere('source_key', 'india-basic-'.$year.'-total') ?? $availableEditions->where('year', $year)->first());
        abort_if(isset($input['edition']) && ! $edition, 404);
        if ($anchor?->level === 'VILLAGE' && $edition && (int) $edition->id === (int) $anchorEdition->id) {
            $geo = json_decode($anchor->geography, true);
            $pilot = $year === 2011 && $anchor->state_code === '09' && $anchor->district_code === '151' && (string) ($geo['Town/Village'] ?? '') === '131191';
            $summary = app(VillageSummaryPage::class);
            if ($summary->enabled($pilot)) {
                return $summary->census($anchor, $year);
            }
        }

        if ($edition && $edition !== $anchorEdition) {
            $edition = DB::table('census_editions')->where('id', $edition->id)->first();
        }
        if (! $record && ! empty($input['state'])) {
            $matches = DB::table('census_catalogue_rows')->where('edition_id', $edition?->id ?? 0)->where('level', 'STATE')->get()
                ->filter(fn ($row) => Str::slug($row->name) === $input['state']);
            if ($matches->isEmpty() && $year === 2011) {
                $directoryStates = collect(app(ElectoralVillageMap::class)->catalogue()['states']);
                $official = $directoryStates->filter(fn ($row) => PlaceFactTables::stateSlug($row['name']) === $input['state']);
                $stateCode = $official->count() === 1 ? (string) $official->first()['code'] : ($input['state'] === 'delhi' ? '07' : null);
                if ($stateCode !== null) {
                    $matches = DB::table('census_catalogue_rows')->where('edition_id', $edition?->id ?? 0)->where('level', 'STATE')->whereIn('state_code', [$stateCode, str_pad($stateCode, 2, '0', STR_PAD_LEFT), (string) (int) $stateCode])->get();
                }
            }
            $candidate = $matches->firstWhere('residence', $input['residence'] ?? 'Total') ?? $matches->first();
            if ($candidate) {
                return redirect()->route('civic.place', ['record' => $candidate->id] + $request->except('state', 'year'));
            }
        }
        $group = $input['group'] ?? 'population';
        $residence = $input['residence'] ?? $anchor?->residence ?? 'Total';
        $base = DB::table('census_catalogue_rows')->where('edition_id', $edition?->id ?? 0);
        $residenceOptions = (clone $base)->distinct()->pluck('residence');
        if ($anchor?->level === 'STATE') {
            $districtResidences = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->where('e.status', 'published')->where('e.year', $year)->where('c.state_code', $anchor->state_code)
                ->where('c.level', 'DISTRICT')->whereIn('c.residence', ['Total', 'Rural', 'Urban'])->distinct()->pluck('c.residence');
            $residenceOptions = $residenceOptions->merge($districtResidences)->unique()->values();
        }

        $stateOptions = collect();
        if (! $anchor) {
            $districtCounts = DB::table('census_catalogue_rows')->whereIn('edition_id', $editions->where('year', $year)->pluck('id'))
                ->where('level', 'DISTRICT')->select('edition_id', 'state_code')->selectRaw('COUNT(*) as total')
                ->groupBy('edition_id', 'state_code')->get()->keyBy(fn ($row) => $row->edition_id.':'.$row->state_code);
            $stateOptions = DB::table('census_catalogue_rows')->whereIn('edition_id', $editions->where('year', $year)->pluck('id'))
                ->where('level', 'STATE')->where('residence', 'Total')->orderBy('name')->get()
                ->groupBy('state_code')->map(function ($rows) use ($districtCounts) {
                    $option = clone $rows->sortByDesc(fn ($row) => $districtCounts->get($row->edition_id.':'.$row->state_code)?->total ?? 0)->first();
                    $option->display_name = Str::title(trim(preg_replace('/[\s@*#†‡]+$/u', '', $option->name)));

                    return $option;
                })->sortBy('display_name')->values();
        }
        $districtOptions = $anchor && $anchor->level === 'STATE'
            ? DB::table('census_catalogue_rows')->where('edition_id', $anchor->edition_id)->where('state_code', $anchor->state_code)
                ->where('level', 'DISTRICT')->where('residence', 'Total')->orderBy('id')->get()->unique('district_code')->sortBy('name')->values()
            : collect();
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
        $electoralState = null;
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
            if ($year === 2011 && $anchor->state_code === '09' && $depth >= 2) {
                $matches = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                    ->where('r.status', 'accepted')->where('i.namespace', 'census:district:IN:UP')->where('i.version', '2011')
                    ->where('i.code', $anchor->district_code)->distinct()->pluck('i.place_id');
                if ($matches->count() === 1) {
                    $matchedPlace = DB::table('places')->find($matches->first());
                    $electoralState = 'Uttar Pradesh';
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
        $additionalMeasures = match ($group) {
            'households' => ['OCCUPIED_HOUSES' => 'Occupied residential houses'],
            'work' => ['TOT_WORK_M' => 'Male workers', 'TOT_WORK_F' => 'Female workers',
                'CULTIVATOR_P' => 'Cultivators', 'CULTIVATOR_M' => 'Male cultivators', 'CULTIVATOR_F' => 'Female cultivators'],
            default => [],
        };
        foreach ($additionalMeasures as $code => $label) {
            if ($records->contains(fn ($row) => array_key_exists($code, json_decode($row->values, true)))) {
                $measures[$code] = $label;
            }
        }
        $title = $place?->name ?? 'India Census';
        $censusSeries = app(CensusProfileSummary::class)->series($place, $records->all(), $year);
        if (! $place && $residence !== 'Total') {
            $censusSeries['rows'] = [];
        }
        $areaOptions = collect();
        if ($anchor && $depth >= 2 && $depth <= 3) {
            $areaOptions = $this->scope(clone $base, $codes, $depth, $year)->whereIn('level', $depth === 2 ? ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL', 'VILLAGE', 'TOWN'] : ['VILLAGE', 'TOWN'])->when($depth === 3 && $residence !== 'Total', fn ($query) => $query->where('residence', $residence))->orderByRaw("CASE WHEN residence = 'Total' THEN 0 ELSE 1 END")->orderBy('name')->get()->unique(fn ($row) => $row->level.':'.implode(':', $this->codes($row)))->values();
        }
        $authorities = collect();
        $adjacentPlaces = collect();
        if ($anchor && $depth >= 2) {
            $siblings = $this->scope(clone $base, $codes, $depth - 1, $year)->where('level', $anchor->level)->where('residence', $anchor->residence);
            $previous = (clone $siblings)->where('name', '<', $anchor->name)->orderByDesc('name')->first();
            $next = (clone $siblings)->where('name', '>', $anchor->name)->orderBy('name')->first();
            $adjacentPlaces = collect([$previous, $next])->filter()->values();
        }
        if ($matchedPlace) {
            $authorities = DB::table('office_assignments as a')->join('offices as o', 'o.id', '=', 'a.office_id')->leftJoin('people as p', 'p.id', '=', 'a.person_id')->join('office_jurisdictions as j', 'j.office_id', '=', 'o.id')->join('source_releases as r', 'r.id', '=', 'a.source_release_id')->join('source_releases as jr', 'jr.id', '=', 'j.source_release_id')
                ->where('j.place_id', $matchedPlace->id)->where('r.status', 'accepted')->where('jr.status', 'accepted')->whereNull('a.superseded_at')->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
                ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', today()->toDateString()))->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>', today()->toDateString()))
                ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))->select('o.title', 'p.display_name', 'r.url')->distinct()->get();
        }

        $areaDashboard = $place && in_array($this->level($place->level), ['STATE', 'SUB-DISTRICT', 'VILLAGE', 'TOWN'], true);
        $childResidence = $place?->level === 'STATE' && (clone $base)->where('state_code', $place->state_code)->where('level', 'DISTRICT')->where('residence', 'Total')->exists() ? 'Total' : $residence;
        $dashboardChildren = $areaDashboard && PlaceTopics::selected('census') === 'places' ? $this->scope(clone $base, $codes, $depth, $year)->whereIn('level', $childLevels)->when($depth !== 3 || $residence !== 'Total', fn ($query) => $query->where('residence', $childResidence))->orderBy('name')->orderBy('id')->get()->unique(fn ($row) => $this->level($row->level).':'.implode(':', $this->codes($row)))->values() : collect();

        $areaPresentation = ['values' => json_decode($records->first()?->values ?? ($place?->residence === $residence ? $place->values : '{}'), true), 'aggregated_fields' => [], 'expected_districts' => 0];
        $censusMapContext = null;
        if ($areaDashboard) {
            $presentation = app(CensusAreaPresentation::class);
            if ($place->level === 'STATE') {
                $districtRows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                    ->where('e.status', 'published')->where('e.year', $year)->where('c.state_code', $place->state_code)
                    ->where('c.level', 'DISTRICT')->where('c.residence', $residence)->select('c.district_code', 'c.values')->get();
                $areaPresentation = $presentation::stateValues($areaPresentation['values'], $districtRows->map(fn ($row) => ['code' => $row->district_code, 'values' => json_decode($row->values, true)])->all(), $dashboardChildren->pluck('district_code')->all());
                if ($childResidence !== $residence) {
                    $districtGroups = $districtRows->groupBy(fn ($row) => (int) $row->district_code);
                    $dashboardChildren = $dashboardChildren->map(function ($row) use ($districtGroups, $presentation, $residence) {
                        $row = clone $row;
                        $values = $presentation::stateValues([], $districtGroups->get((int) $row->district_code, collect())->map(fn ($candidate) => ['code' => $candidate->district_code, 'values' => json_decode($candidate->values, true)])->all(), [$row->district_code]);
                        $row->values = json_encode($values['values']);
                        $row->residence = $residence;

                        return $row;
                    });
                }
                if ($residence !== 'Total') {
                    $values = $areaPresentation['values'];
                    $censusSeries = ['source' => null, 'rows' => [['year' => $year, 'population' => $values['TOT_P'] ?? null, 'male' => $values['TOT_M'] ?? null, 'female' => $values['TOT_F'] ?? null, 'households' => $values['No_HH'] ?? null, 'literates' => $values['P_LIT'] ?? null, 'growth' => null, 'ratio' => ($values['TOT_M'] ?? 0) > 0 && isset($values['TOT_F']) ? 1000 * $values['TOT_F'] / $values['TOT_M'] : null, 'notes' => ['This population group uses its corresponding existing district counts; unavailable or conflicting fields remain unavailable.']]]];
                }

            } else {
                $censusMapContext = $presentation->censusMap($place, $dashboardChildren, $year);
            }
            $areaPresentation['rates'] = $presentation::rates($areaPresentation['values']);
        }

        return view($areaDashboard ? 'census-area-dashboard' : 'civic-explorer', compact('areaPresentation', 'censusMapContext', 'dashboardChildren', 'input', 'editions', 'availableEditions', 'years', 'year', 'edition', 'group', 'residence', 'place', 'records', 'parents', 'linked', 'matchedPlace', 'electoralState', 'children', 'childLevels', 'measures', 'title', 'residenceOptions', 'villageBrowse', 'stateOptions', 'districtOptions', 'censusSeries', 'areaOptions', 'authorities', 'adjacentPlaces'));
    }
}
