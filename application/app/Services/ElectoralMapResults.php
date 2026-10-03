<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ElectoralMapResults
{
    public function records(string $kind, ?string $state = null, ?string $edition = null): array
    {
        if (! Schema::hasTable('historical_constituency_index')) {
            return [];
        }
        $stateSql = $this->stateSql();
        $query = DB::table('historical_constituency_index')->where('kind', $kind)
            ->when($edition, fn ($q) => $q->where('edition_id', $edition))
            ->when($state, fn ($q) => $q->whereRaw($stateSql.' = ?', [str_replace('&', 'and', mb_strtolower($state))]));
        if (! $edition) {
            $latest = DB::table('historical_constituency_index')->where('kind', $kind)
                ->selectRaw($stateSql.' AS map_state, MAX(year) AS map_year')->groupByRaw($stateSql);
            $query->joinSub($latest, 'latest_map_years', fn ($join) => $join->on('year', '=', 'latest_map_years.map_year')->whereRaw($stateSql.' = latest_map_years.map_state'));
        }
        $rows = $query->select('historical_constituency_index.*')->orderByDesc('year')->orderBy('edition_id')->orderBy('record_code')->get();
        if (! $edition) {
            $rows = $rows->groupBy(fn ($r) => mb_strtolower(ElectionPlaceIdentity::state($r->state_label ?? '')))->flatMap(function ($group) {
                $latest = $group->where('year', $group->max('year'))->groupBy('edition_id')->sortByDesc(fn ($entries) => $entries->count())->first();

                return $latest ?? collect();
            });
        }
        $records = [];
        foreach ($rows->groupBy('edition_id') as $id => $entries) {
            $data = $this->editionRecords($id, $entries->first()->extraction_sha256);
            foreach ($entries as $entry) {
                $record = $data[$entry->record_code] ?? null;
                $result = $record ? app(HistoricalElectionAnalytics::class)->singleSeatResult($record, $id) : null;
                $recordState = ElectionPlaceIdentity::state($entry->state_label ?? '');
                $records[] = ['id' => $id.':'.$entry->record_code, 'kind' => $kind, 'state' => $recordState,
                    'name' => $entry->constituency_name, 'code' => $entry->record_code, 'year' => $entry->year,
                    'official_code' => $record['official_pc_code'] ?? $record['official_ac_code'] ?? null,
                    'party' => $result['party'] ?? null, 'winner' => $result['winner'] ?? null,
                    'url' => route('constituency.overview', ['kind' => $kind, 'state' => $recordState,
                        'name' => $entry->constituency_name, 'edition' => $id, 'code' => $entry->record_code])];
            }
        }

        return $records;
    }

    private function stateSql(): string
    {
        $original = ElectionPlaceIdentity::stateSql();

        return "REPLACE(CASE $original WHEN 'orissa' THEN 'odisha' WHEN 'uttaranchal' THEN 'uttarakhand' WHEN 'pondicherry' THEN 'puducherry' ELSE $original END, '&', 'and')";
    }

    private function editionRecords(string $edition, string $expectedHash): array
    {
        $reviewVersion = Schema::hasTable('historical_election_reviews') ? DB::table('historical_election_reviews')->where('archive', $edition)->max('id') : 0;

        return Cache::remember('map-results-v1:'.$edition.':'.$expectedHash.':'.$reviewVersion, 300, function () use ($edition, $expectedHash): array {
            $disk = app(ArchiveFiles::class);
            $path = 'election-archive/'.$edition.'/extraction.json';
            if (! $disk->exists($path)) {
                return [];
            }
            $body = $disk->get($path);
            abort_unless(hash_equals($expectedHash, hash('sha256', $body)), 409, 'Indexed election extraction checksum differs.');
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $manifestPath = 'election-archive/'.$edition.'/manifest.json';
            abort_unless($disk->exists($manifestPath), 409, 'Election source manifest is missing.');
            $manifest = json_decode($disk->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $hashes = collect($manifest['files'] ?? [])->pluck('sha256', 'file');
            abort_unless(($manifest['url'] ?? null) === ($data['source_url'] ?? null)
                && $hashes->get($data['source_file'] ?? null) === ($data['source_sha256'] ?? null), 409, 'Election source identity differs.');
            foreach ($data['additional_sources'] ?? [] as $source) {
                abort_unless($hashes->get($source['file']) === $source['sha256'], 409, 'Additional election source identity differs.');
            }
            $records = [];
            foreach ($data['records'] ?? [] as $record) {
                $records[$record['code']] = app(HistoricalElectionReview::class)->apply($edition, $record, $data['source_sha256']);
            }

            return $records;
        });
    }

    public function colors(): array
    {
        return array_replace(config('site.appearance.party_colors', []), app(SiteSettings::class)->appearance()['party_colors'] ?? []);
    }
}
