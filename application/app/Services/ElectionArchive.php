<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ElectionArchive
{
    public function catalogue(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/eci-election-archive.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function collection(string $url): array
    {
        $id = substr(hash('sha256', $url), 0, 24);
        $path = 'election-archive/'.$id.'/manifest.json';
        if (! Storage::disk('local')->exists($path)) {
            return ['id' => $id, 'status' => 'not_collected', 'files' => [], 'errors' => []];
        }
        $data = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($data['url'] ?? null) === $url, 500, 'Archive manifest identity differs.');

        return ['id' => $id, 'has_extraction' => Storage::disk('local')->exists('election-archive/'.$id.'/extraction.json')] + $data;
    }

    public function entries(string $type, ?int $year = null): array
    {
        $catalogue = $this->catalogue();
        $published = DB::table('election_contests as e')->join('places as p', 'p.id', '=', 'e.place_id')
            ->join('source_releases as r', 'r.id', '=', 'e.source_release_id')
            ->where('p.type', $type)->where('e.active', true)->where('r.status', 'accepted')
            ->where('e.election_type', $type === 'pc' ? 'lok_sabha_general' : 'vidhan_sabha_general')
            ->select('e.year', 'e.place_id')->distinct()->get()->groupBy('year');

        return collect($catalogue[$type])->map(fn ($entry) => ['label' => $entry[0], 'year' => (int) substr($entry[0], 0, 4), 'url' => $entry[1], 'published_places' => $published->get((int) substr($entry[0], 0, 4), collect())->count(), 'collection' => $this->collection($entry[1])])
            ->filter(fn ($entry) => $year === null || $entry['year'] === $year)->values()->all();
    }
}
