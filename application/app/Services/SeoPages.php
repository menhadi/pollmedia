<?php

namespace App\Services;

use App\Http\Controllers\PlaceController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeoPages
{
    public function catalog(string $type, string $year, string $state = '', string $search = ''): Collection
    {
        if (in_array($type, ['pc', 'ac'], true) && DB::table('historical_constituency_index')->where('kind', $type)->exists()) {
            $query = DB::table('historical_constituency_index')->where('kind', $type)
                ->when($year !== '', fn ($query) => $query->where('year', (int) $year))
                ->when($state !== '', fn ($query) => $query->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($state))]))
                ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($search).'%']))
                ->orderBy('constituency_name')->orderByDesc('year');
            if ($year === '') {
                $query->select('constituency_name')->selectRaw(ElectionPlaceIdentity::stateSql().' as state_label, MAX(year) as year')->groupBy('constituency_name')->groupByRaw(ElectionPlaceIdentity::stateSql());
            }
            $rows = $query->get();
            $stateNames = $rows->pluck('state_label')->unique()->mapWithKeys(fn (string $label): array => [$label => ElectionPlaceIdentity::state($label)]);

            return $rows->map(function (object $entry) use ($type, $year, $stateNames): array {
                $state = $stateNames[$entry->state_label];
                $label = $entry->constituency_name.' '.($type === 'pc' ? 'Lok Sabha' : 'Assembly').' · '.$state;
                $parameters = ['kind' => $type, 'state' => $state, 'name' => $entry->constituency_name];
                if ($year !== '') {
                    $parameters += ['edition' => $entry->edition_id, 'code' => $entry->record_code];
                    $label .= ' · '.$entry->year;
                }

                return ['path' => route('constituency.overview', $parameters, false), 'label' => $label,
                    'title' => mb_substr($label.' | Pollmedia', 0, 180),
                    'description' => 'Explore '.$label.': election results, candidate votes, party shares, turnout, winners and official source reports. Historical election records with available coverage.'];
            })->unique('path')->keyBy('path');
        }
        if ($type === 'sir') {
            return collect([['path' => '/india/sir', 'label' => 'SIR electoral roll listings', 'title' => 'SIR electoral roll source data | Pollmedia', 'description' => 'Explore published SIR enumeration data, constituency coverage and official source documents.']])->keyBy('path');
        }
        if ($type === 'census') {
            return DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')->where('e.status', 'published')->when($year !== '', fn ($query) => $query->where('e.year', (int) $year))->when($search !== '', fn ($query) => $query->where('r.name', 'like', '%'.$search.'%'))->orderBy('r.id')->select('r.id', 'r.name', 'e.year')->get()->map(function (object $row): array {
                return ['path' => route('civic.place', ['record' => $row->id], false), 'label' => $row->name.' Census '.$row->year, 'title' => $row->name.' Census '.$row->year.' | Pollmedia', 'description' => 'Explore '.$row->name.' Census '.$row->year.': published population measures and official source records. Historical figures, not current estimates.'];
            })->keyBy('path');
        }
        if ($type === 'village') {
            $year = $year !== '' ? $year : (string) ($this->filters('village')['years']->first() ?? '2011');
            if (! $this->filters('village')['years']->contains((int) $year)) {
                return collect();
            }
            $dataset = app(PlaceController::class)->payload('census-pilibhit-villages-'.$year);

            return collect($dataset['villages'])->filter(fn (array $village): bool => $search === '' || str_contains(mb_strtolower($village['name']), mb_strtolower($search)))->map(function (array $village) use ($year): array {
                $path = route('villages.show', ['code' => $village['code'], 'slug' => Str::slug($village['name'])] + ($year === '2001' ? ['year' => $year] : []), false);

                return ['path' => $path, 'label' => $village['name'].' - Census '.$year.' ('.$village['code'].')',
                    'title' => $village['name'].' village - Census '.$year.' | Pollmedia',
                    'description' => $village['name'].' village in the Pilibhit Census '.$year.' dataset: population, households and official source links. Historical figures, not current estimates.'];
            })->keyBy('path');
        }

        return DB::table('places')->where('type', $type)->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('name')->get()->map(function (object $place): array {
            $label = $place->name.' '.['district' => 'District', 'pc' => 'Parliamentary Constituency', 'ac' => 'Assembly Constituency'][$place->type];
            $path = route('places.show', ['type' => $place->type, 'slug' => Str::after($place->slug, $place->type.'-')], false);

            return ['path' => $path, 'label' => $label, 'title' => $label.' | Pollmedia',
                'description' => 'Explore '.$label.': available public data, connected places and dated official sources. Check each section for coverage and reference years.'];
        })->keyBy('path');
    }

    public function filters(string $type, string $state = ''): array
    {
        $years = collect();
        $states = collect();
        if (in_array($type, ['pc', 'ac'], true)) {
            $query = DB::table('historical_constituency_index')->where('kind', $type);
            $states = (clone $query)->distinct()->pluck('state_label')->map(fn (string $label): string => ElectionPlaceIdentity::state($label))->unique()->sort()->values();
            $years = $query->when($state !== '', fn ($query) => $query->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($state))]))->distinct()->orderByDesc('year')->pluck('year');
        } elseif ($type === 'census') {
            $years = DB::table('census_editions')->where('status', 'published')->distinct()->orderByDesc('year')->pluck('year');
        } elseif ($type === 'village') {
            $years = DB::table('data_sources as s')->join('source_releases as r', 'r.data_source_id', '=', 's.id')->where('r.status', 'accepted')->where('s.key', 'like', 'census-pilibhit-villages-%')->distinct()->pluck('s.key')->map(fn (string $key): int => (int) Str::afterLast($key, '-'))->sortDesc()->values();
        }

        return compact('years', 'states');
    }

    public function current(string $canonical): ?object
    {
        $path = parse_url($canonical, PHP_URL_PATH) ?: '/';
        $query = parse_url($canonical, PHP_URL_QUERY);

        return DB::table('seo_metadata')->where('path', $path.($query ? '?'.$query : ''))->first();
    }

    public function apply(string $path, ?string $title, ?string $description, int $expected, string $action, ?string $keywords = null): void
    {
        $current = DB::table('seo_metadata')->where('path', $path)->lockForUpdate()->first();
        abort_unless((int) ($current->revision_id ?? 0) === $expected, 409, 'This page changed after this draft was created. Create a fresh draft to review the latest version.');
        $revision = DB::table('seo_revisions')->insertGetId([
            'path' => $path, 'title' => $title, 'description' => $description,
            'keywords' => $keywords, 'before_keywords' => $current->keywords ?? null,
            'before_title' => $current->title ?? null, 'before_description' => $current->description ?? null,
            'action' => $action, 'created_at' => now(), 'user_id' => auth()->id(),
        ]);
        DB::table('seo_metadata')->updateOrInsert(['path' => $path], [
            'title' => $title, 'description' => $description, 'keywords' => $keywords, 'revision_id' => $revision, 'updated_at' => now(),
        ]);
    }
}
