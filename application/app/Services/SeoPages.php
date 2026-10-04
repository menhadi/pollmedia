<?php

namespace App\Services;

use App\Http\Controllers\PlaceController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeoPages
{
    public function catalog(string $type, string $year): Collection
    {
        if ($type === 'sir') {
            return collect([['path' => '/india/sir', 'label' => 'SIR electoral roll listings', 'title' => 'SIR electoral roll source data | Pollmedia', 'description' => 'Explore published SIR enumeration data, constituency coverage and official source documents.']])->keyBy('path');
        }
        if ($type === 'census') {
            return DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')->where('e.status', 'published')->where('e.year', (int) $year)->orderBy('r.id')->select('r.id', 'r.name', 'e.year')->get()->map(function (object $row): array {
                return ['path' => route('civic.place', ['record' => $row->id], false), 'label' => $row->name.' Census '.$row->year, 'title' => $row->name.' Census '.$row->year.' | Pollmedia', 'description' => 'Explore '.$row->name.' Census '.$row->year.': published population measures and official source records. Historical figures, not current estimates.'];
            })->keyBy('path');
        }
        if ($type === 'village') {
            $dataset = app(PlaceController::class)->payload('census-pilibhit-villages-'.$year);

            return collect($dataset['villages'])->map(function (array $village) use ($year): array {
                $path = route('villages.show', ['code' => $village['code'], 'slug' => Str::slug($village['name'])] + ($year === '2001' ? ['year' => $year] : []), false);

                return ['path' => $path, 'label' => $village['name'].' - Census '.$year.' ('.$village['code'].')',
                    'title' => $village['name'].' village - Census '.$year.' | Pollmedia',
                    'description' => $village['name'].' village in the Pilibhit Census '.$year.' dataset: population, households and official source links. Historical figures, not current estimates.'];
            })->keyBy('path');
        }

        return DB::table('places')->where('type', $type)->orderBy('name')->get()->map(function (object $place): array {
            $label = $place->name.' '.['district' => 'District', 'pc' => 'Parliamentary Constituency', 'ac' => 'Assembly Constituency'][$place->type];
            $path = route('places.show', ['type' => $place->type, 'slug' => Str::after($place->slug, $place->type.'-')], false);

            return ['path' => $path, 'label' => $label, 'title' => $label.' | Pollmedia',
                'description' => 'Explore '.$label.': available public data, connected places and dated official sources. Check each section for coverage and reference years.'];
        })->keyBy('path');
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
