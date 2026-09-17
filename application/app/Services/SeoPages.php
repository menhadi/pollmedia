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

    public function apply(string $path, ?string $title, ?string $description, int $expected, string $action): void
    {
        $current = DB::table('seo_metadata')->where('path', $path)->lockForUpdate()->first();
        abort_unless((int) ($current->revision_id ?? 0) === $expected, 409, 'This page changed after this draft was created. Create a fresh draft to review the latest version.');
        $revision = DB::table('seo_revisions')->insertGetId([
            'path' => $path, 'title' => $title, 'description' => $description,
            'before_title' => $current->title ?? null, 'before_description' => $current->description ?? null,
            'action' => $action, 'created_at' => now(), 'user_id' => auth()->id(),
        ]);
        DB::table('seo_metadata')->updateOrInsert(['path' => $path], [
            'title' => $title, 'description' => $description, 'revision_id' => $revision, 'updated_at' => now(),
        ]);
    }
}
