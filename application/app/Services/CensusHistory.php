<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CensusHistory
{
    public function population(): ?array
    {
        $payload = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')->where('s.key', 'census-pilibhit-population-history')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');

        return $payload ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function edition1981(): ?array
    {
        $payload = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')->where('s.key', 'census-pilibhit-summary-1981')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');

        return $payload ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function villages1981(): ?array
    {
        $payload = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')->where('s.key', 'census-pilibhit-historical-villages-1981')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');

        return $payload ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function archive(): array
    {
        $entries = json_decode(file_get_contents(database_path('fixtures/census-pilibhit-archive.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($entries as &$entry) {
            $entry['village_profiles'] = 0;
            $payload = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')->where('s.key', 'census-pilibhit-villages-'.$entry['year'])->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
            if ($payload) {
                $entry['village_profiles'] = count(json_decode($payload, true)['villages'] ?? []);
            }
        }
        unset($entry);

        return $entries;
    }
}
