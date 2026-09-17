<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConstituencyHistory
{
    public const EDITIONS = [2009 => 'e5346f9160ad32fb68a34578', 2014 => '3a136496a89deb7c38ecfe18', 2019 => '2e749f2174f08a9ea1fc803d', 2024 => '349e04305ee4652986f79497'];

    public const ASSEMBLY_EDITIONS = [2012 => 'f4df4876a829786cd04cb280', 2017 => 'c7a9e523186004c713736633', 2022 => 'cfc1444fdc82076beba88c6f'];

    public function forAssembly(object $place): array
    {
        $empty = ['rows' => [], 'mapping' => null];
        if ($place->type !== 'ac' || $place->country_code !== 'IN') {
            return $empty;
        }
        $ids = DB::table('place_identifiers as i')->join('source_releases as s', 's.id', '=', 'i.source_release_id')
            ->where('i.place_id', $place->id)->where('i.namespace', 'electoral:IN:UP:ac')->where('i.version', 'eci-election-2022')->where('s.status', 'accepted')->pluck('i.code');
        if ($ids->count() !== 1) {
            return $empty;
        }
        $geography = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        $path = base_path('../pilot/raw/up-district-gazette-2023.pdf');
        if (! is_file($path) || ! hash_equals($geography['district_sha256'], hash_file('sha256', $path))) {
            return $empty;
        }
        $mapping = collect($geography['district_rows'])->firstWhere('code', (int) $ids->first());
        if (! $mapping || $this->normalized($mapping['name']) !== $this->normalized($place->name)) {
            return $empty;
        }
        $mapping['url'] = $geography['district_url'];
        $rows = [];
        foreach (self::ASSEMBLY_EDITIONS as $year => $archive) {
            $row = ['year' => $year, 'record' => null, 'url' => null, 'source_url' => null, 'reason' => 'Edition is unavailable or its constituency identity needs verification.'];
            try {
                [$data] = app(HistoricalElectionArchive::class)->load($archive, app(ElectionArchive::class));
            } catch (HttpException $exception) {
                $rows[] = $row;

                continue;
            }
            $matches = collect($data['records'])->where('code', $mapping['code']);
            $original = $matches->count() === 1 ? $matches->first() : null;
            if ($data['kind'] === 'ac' && $data['year'] === $year && $original && $this->normalized($original['name']) === $this->normalized($mapping['name'])) {
                $record = app(HistoricalElectionReview::class)->apply($archive, $original, $data['source_sha256']);
                $row['record'] = $record;
                $row['reason'] = null;
                $row['source_url'] = $data['source_url'];
                $row['url'] = route('elections.assembly', ['edition' => $archive, 'state' => 'Uttar Pradesh', 'code' => $original['code']]);
                $winner = collect($record['candidates'])->first(fn (array $candidate): bool => $candidate['candidate_name'] === ($record['winner'] ?? null) && ! ($candidate['is_nota'] ?? false) && strtoupper($candidate['party_at_election']) !== 'NOTA');
                $row['winner_party'] = ! $record['has_warning'] ? ($winner['party_at_election'] ?? null) : null;
            }
            $rows[] = $row;
        }

        return compact('rows', 'mapping');
    }

    public function relatedPlace(string $archive, array $record): ?array
    {
        $isAssembly = in_array($archive, self::ASSEMBLY_EDITIONS, true);
        $earlier = ! $isAssembly && ! in_array($archive, self::EDITIONS, true);
        if ($isAssembly) {
            $code = $record['code'];
        } elseif ($earlier) {
            $evidence = json_decode(file_get_contents(database_path('fixtures/pilibhit-earlier-boundaries.json')), true, 512, JSON_THROW_ON_ERROR);
            if (! collect($evidence['records'])->contains(fn (array $identity): bool => $identity['archive'] === $archive && $identity['code'] === $record['code'])) {
                return null;
            }
            $code = 26;
        } else {
            if (($record['state_code'] ?? null) !== 'S24') {
                return null;
            }
            $code = $record['official_pc_code'] ?? null;
        }
        $places = DB::table('places as p')->join('place_identifiers as i', 'i.place_id', '=', 'p.id')
            ->where('i.namespace', $isAssembly ? 'electoral:IN:UP:ac' : 'electoral:IN:UP:pc')->where('i.version', $isAssembly ? 'eci-election-2022' : 'delimitation-order-34')->where('i.code', (string) $code)
            ->select('p.*')->distinct()->get();
        if ($places->count() !== 1) {
            return null;
        }
        $place = $places->first();
        $comparison = $isAssembly ? $this->forAssembly($place) : $this->forPlace($place);
        $url = route($isAssembly ? 'elections.assembly' : 'elections.history', ['edition' => $archive, 'state' => $record['state_name'] ?? $record['state_code'] ?? ($isAssembly ? 'Uttar Pradesh' : ''), 'code' => $record['code']]);
        $links = $earlier ? ($comparison['earlier']['links'] ?? []) : $comparison['rows'];
        if (! collect($links)->contains('url', $url)) {
            return null;
        }

        $timeline = collect($comparison['earlier']['links'] ?? [])->concat($comparison['rows'])
            ->filter(fn (array $link): bool => ! empty($link['url']))->sortBy('year')->values()
            ->map(fn (array $link): array => ['year' => $link['year'], 'url' => $link['url']]);
        $position = $timeline->search(fn (array $link): bool => $link['url'] === $url);

        return [
            'name' => $place->name,
            'slug' => substr($place->slug, 3),
            'earlier' => $earlier,
            'previous' => $timeline->get($position - 1),
            'next' => $timeline->get($position + 1),
        ];
    }

    public function forPlace(object $place): array
    {
        $empty = ['rows' => [], 'mapping' => null];
        if ($place->type !== 'pc' || $place->country_code !== 'IN') {
            return $empty;
        }
        $identifiers = DB::table('place_identifiers as i')->join('source_releases as s', 's.id', '=', 'i.source_release_id')
            ->where('i.place_id', $place->id)->where('i.namespace', 'electoral:IN:UP:pc')->where('i.version', 'delimitation-order-34')->where('s.status', 'accepted')
            ->select('i.code', 's.sha256', 's.url')->get();
        if ($identifiers->count() !== 1) {
            return $empty;
        }
        $identifier = $identifiers->first();
        $geography = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        $path = base_path('../pilot/raw/up-delimitation.pdf');
        if (! is_string($identifier->sha256) || ! is_file($path) || ! hash_equals($geography['pc_sha256'], $identifier->sha256) || ! hash_equals($identifier->sha256, hash_file('sha256', $path))) {
            return $empty;
        }
        $mapping = collect($geography['pcs'])->firstWhere('code', (int) $identifier->code);
        if (! $mapping || $this->normalized($place->name) !== $this->normalized($mapping['name'])) {
            return $empty;
        }
        $mapping['url'] = $identifier->url;
        $rows = [];
        foreach (self::EDITIONS as $year => $archive) {
            $row = ['year' => $year, 'record' => null, 'url' => null, 'source_url' => null, 'reason' => 'Edition is not available or its source integrity needs review.'];
            try {
                [$data] = app(HistoricalElectionArchive::class)->load($archive, app(ElectionArchive::class));
            } catch (HttpException $exception) {
                $rows[] = $row;

                continue;
            }
            $matches = collect($data['records'])->filter(fn (array $record): bool => ($record['state_code'] ?? null) === 'S24' && ($record['official_pc_code'] ?? null) === (int) $identifier->code);
            $original = $matches->count() === 1 ? $matches->first() : null;
            $nameNote = $original ? $this->nameMapping($archive, $data, $original, $mapping['name']) : null;
            if ($data['kind'] !== 'pc' || $data['year'] !== $year || ! $original || ($this->normalized($original['constituency_name'] ?? '') !== $this->normalized($mapping['name']) && $nameNote === null)) {
                $row['reason'] = 'Historical state, code or name needs mapping review.';
                $rows[] = $row;

                continue;
            }
            $record = app(HistoricalElectionReview::class)->apply($archive, $original, $data['source_sha256']);
            $row['record'] = $record;
            $row['mapping_note'] = $nameNote;
            $row['reason'] = null;
            $row['source_url'] = $data['source_url'];
            $row['url'] = route('elections.history', ['edition' => $archive, 'state' => $original['state_name'] ?? $original['state_code'], 'code' => $original['code']]);
            $ranked = collect($record['candidates'])->reject(fn (array $candidate): bool => ($candidate['is_nota'] ?? false) || strtoupper($candidate['party_at_election']) === 'NOTA')->sortByDesc('votes')->values();
            $row['winner_party'] = ! $record['has_warning'] && isset($record['winner']) && $ranked->count() > 1 && $ranked[0]['candidate_name'] === $record['winner'] ? $ranked[0]['party_at_election'] : null;
            $rows[] = $row;
        }

        $earlier = (int) $identifier->code === 26 ? $this->earlierPilibhit() : null;

        return compact('rows', 'mapping', 'earlier');
    }

    private function earlierPilibhit(): ?array
    {
        $evidence = json_decode(file_get_contents(database_path('fixtures/pilibhit-earlier-boundaries.json')), true, 512, JSON_THROW_ON_ERROR);
        $path = base_path('../pilot/raw/'.$evidence['file']);
        if (! is_file($path) || ! hash_equals($evidence['sha256'], hash_file('sha256', $path))) {
            return null;
        }
        $links = [];
        $evidence['older_orders'] = array_filter($evidence['older_orders'] ?? [], function (array $source): bool {
            $sourcePath = base_path('../pilot/raw/'.$source['file']);

            return is_file($sourcePath) && hash_equals($source['sha256'], hash_file('sha256', $sourcePath));
        });
        $amendment = $evidence['reorganisation'];
        $amendmentPath = base_path('../pilot/raw/'.$amendment['file']);
        $amendmentVerified = is_file($amendmentPath) && hash_equals($amendment['sha256'], hash_file('sha256', $amendmentPath));
        if (! $amendmentVerified) {
            $evidence['reorganisation'] = null;
        }
        foreach ($evidence['records'] as $identity) {
            if (isset($identity['boundary_source']) && ! isset($evidence['older_orders'][$identity['boundary_source']])) {
                continue;
            }
            if ($identity['year'] === 2004 && ! $amendmentVerified) {
                continue;
            }
            try {
                [$data] = app(HistoricalElectionArchive::class)->load($identity['archive'], app(ElectionArchive::class));
            } catch (HttpException $exception) {
                continue;
            }
            $record = collect($data['records'])->firstWhere('code', $identity['code']);
            if ($data['kind'] !== 'pc' || $data['year'] !== $identity['year'] || ! hash_equals($identity['source_sha256'], $data['source_sha256'])
                || ! $record || ($record['official_pc_code'] ?? null) !== $identity['official_pc_code'] || ($record['state_name'] ?? null) !== $identity['state_name'] || ($record['constituency_name'] ?? null) !== ($identity['constituency_name'] ?? 'PILIBHIT')) {
                continue;
            }
            $links[] = ['year' => $data['year'], 'url' => route('elections.history', ['edition' => $identity['archive'], 'state' => $record['state_name'], 'code' => $record['code']])];
        }
        $evidence['links'] = $links;

        return $evidence;
    }

    private function normalized(string $name): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(preg_replace('/\s*\((?:SC|ST)\)$/i', '', trim($name))));
    }

    private function nameMapping(string $archive, array $data, array $record, string $target): ?string
    {
        $mappings = json_decode(file_get_contents(database_path('fixtures/pc-name-mappings.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($mappings as $mapping) {
            if ($mapping['archive'] === $archive && $mapping['year'] === $data['year']
                && $mapping['state_code'] === ($record['state_code'] ?? null)
                && $mapping['official_pc_code'] === ($record['official_pc_code'] ?? null)
                && $mapping['source_name'] === ($record['constituency_name'] ?? null)
                && $mapping['mapped_name'] === $target
                && hash_equals($mapping['source_sha256'], $data['source_sha256'])
                && collect($data['additional_sources'] ?? [])->contains('sha256', $mapping['summary_sha256'])
                && $mapping['detail_page'] === ($record['detail_page'] ?? null)
                && $mapping['summary_page'] === ($record['summary_page'] ?? null)) {
                return $mapping['note'];
            }
        }

        return null;
    }
}
