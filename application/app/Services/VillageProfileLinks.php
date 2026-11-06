<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PDO;

class VillageProfileLinks
{
    private ?PDO $links = null;

    private array $directoryPackageHashes = [];

    private ?array $historicalPilibhitLinks = null;

    private ?object $historicalPilibhitRelease = null;

    private ?array $historicalPilibhitData = null;

    private ?PDO $historicalDirectoryIndex = null;

    private ?PDO $historicalPcaIndex = null;

    private ?array $pilibhit = null;

    public function forShape(array $place, array $layer, bool $withData = false, bool $allowSourceMatch = true): array
    {
        if (($layer['state'] ?? '') === 'Uttar Pradesh' && ($layer['district'] ?? '') === 'Pilibhit') {
            $data = $this->pilibhit();
            $record = collect($data['villages'] ?? [])->first(fn (array $row): bool => (string) $row['code'] === (string) ($place['code'] ?? ''));
            $subdistrict = collect($data['subdistricts'] ?? [])->firstWhere('code', $record['subdistrict_code'] ?? null);
            $normalize = fn (string $text): string => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($text));
            if ($record && $subdistrict && $normalize($record['name']) === $normalize($place['name']) && $normalize($subdistrict['name']) === $normalize($place['subdistrict'])) {
                $result = ['url' => route('villages.show', ['code' => $record['code'], 'slug' => Str::slug($record['name'])]), 'link_basis' => 'Existing Pilibhit Census code, village name and subdistrict'];
                if ($withData) {
                    $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
                    if (is_file($path)) {
                        $db = new PDO('sqlite:'.$path);
                        $db->exec('PRAGMA query_only=ON');
                        $query = $db->prepare("SELECT lgd FROM directory WHERE state='9' AND CAST(census2011 AS INTEGER)=?");
                        $query->execute([(int) $record['code']]);
                        $codes = $query->fetchAll(PDO::FETCH_COLUMN);
                        if (count($codes) === 1) {
                            $result = array_replace($result, app(ElectoralVillageMap::class)->connections($codes[0]));
                            $result['lgd_code'] = $codes[0];
                        }
                    }
                    $result['census'] = ['year' => 2011, 'population' => $record['population'] ?? null, 'households' => $record['households'] ?? null, 'literate' => $record['literate'] ?? null,
                        'children' => $record['population_0_6'] ?? null, 'source_release_id' => $data['_release']['id'] ?? null, 'source_sha256' => $data['_release']['sha256'] ?? null, 'source_url' => $data['source_url'] ?? null];
                    $result['navigation'] = [['label' => 'Uttar Pradesh state dashboard', 'url' => route('states.show', ['state' => 'uttar-pradesh'])]];
                    if (Route::has('district-dashboard')) {
                        $result['navigation'][] = ['label' => 'Pilibhit district dashboard · Census 2011', 'url' => route('district-dashboard', ['state' => '09', 'district' => '151'])];
                    }
                }

                return $withData && isset($result['lgd_code']) ? $this->historicalRecords($result, (string) $result['lgd_code']) : $result;
            }
        }
        $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
        if (! is_file($path)) {
            return [];
        }
        if ($this->links === null) {
            $this->links = app(ElectoralVillageMap::class)->database();
            $this->links->exec('PRAGMA query_only=ON');
        }
        $statement = $this->links->prepare('SELECT d.*,s.basis FROM shapes s JOIN layers l ON l.file=s.file JOIN directory d ON d.lgd=s.lgd AND d.state=s.state WHERE s.file=? AND s.feature_index=? AND l.sha256=?');
        $statement->execute([$layer['file'], $place['feature_index'], $layer['sha256']]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1 || ($withData && $allowSourceMatch && isset($layer['state']))) {
            if ($withData && $allowSourceMatch && isset($layer['state'], $place['feature_index'])) {
                $geometry = json_decode(file_get_contents(app(IndiaVillageMap::class)->geometryPath($layer)), true, flags: JSON_THROW_ON_ERROR);
                if (isset($geometry['features'])) {
                    $properties = array_map(fn (array $feature): array => $feature['properties'], $geometry['features']);
                    $row = app(VillageCensusRecords::class)->sourceMatches($layer['state'], $properties, $layer)[$place['feature_index']] ?? null;
                    if ($row && ($row->level === 'TOWN' || count($matches) !== 1)) {
                        $profile = app(VillageCensusRecords::class)->profile($row);
                        if ($row->level === 'TOWN' && count($matches) === 1) {
                            $profile['original_lgd_village_code'] = $matches[0]['lgd'];
                        }
                        array_unshift($profile['navigation'], ['label' => $layer['state'].' state dashboard', 'url' => route('states.show', ['state' => Str::slug(preg_replace('/^the /i', '', str_replace('&', 'and', $layer['state'])))])]);

                        return $profile;
                    }
                }
            }

            if (count($matches) !== 1) {
                return [];
            }
        }
        $village = $matches[0];
        $profile = ['url' => route('electoral-villages.village', ['code' => $village['lgd']]), 'lgd_code' => $village['lgd'], 'link_basis' => $village['basis']];
        if ($withData) {
            $profile = array_replace($profile, $this->records($village));
        }

        return $profile;
    }

    private function pilibhit(): array
    {
        if ($this->pilibhit !== null) {
            return $this->pilibhit;
        }
        if (! RequestSchema::hasTable('source_releases')) {
            return $this->pilibhit = [];
        }
        $release = $this->historicalPilibhitRelease ??= DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->select('r.*')->first();

        return $this->pilibhit = json_decode($release?->payload ?? '{}', true) + ['_release' => ['id' => $release?->id, 'sha256' => $release->sha256 ?? null]];
    }

    public function records(array $village, bool $historicalOnly = false): array
    {
        $result = isset($village['lgd']) ? app(ElectoralVillageMap::class)->connections($village['lgd']) : [];
        if (isset($result['administration'])) {
            $stateName = $result['administration']['state_name'];
            $slug = Str::slug(preg_replace('/^the /i', '', str_replace('&', 'and', $stateName)));
            $result['navigation'] = [['label' => $stateName.' state dashboard', 'url' => route('states.show', ['state' => $slug])]];

        }
        $result = $this->historicalRecords($result, (string) ($village['lgd'] ?? ''));
        $code = (string) ($village['census2011'] ?? '');
        if (! preg_match('/^[0-9]{1,6}$/', $code) || (int) $code === 0 || $code === '999999') {
            return $result;
        }
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
        if ($village['state'] === '9') {
            $record = collect($this->pilibhit()['villages'] ?? [])->first(fn (array $row): bool => (string) $row['code'] === $code);
            if ($record) {
                $result['village_url'] = route('villages.show', ['code' => $code, 'slug' => Str::slug($record['name'])]);
                $result['url'] = $result['village_url'];
                if (Route::has('district-dashboard')) {
                    $result['navigation'][] = ['label' => 'Pilibhit district dashboard · Census 2011', 'url' => route('district-dashboard', ['state' => '09', 'district' => '151'])];
                }
            }
        }
        if ($historicalOnly || ! RequestSchema::hasTable('census_publications')) {
            return $result;
        }
        // Current LGD territories retain their official Census 2011 crosswalk codes.
        $states = match ((string) $village['state']) {
            '36' => ['28'], '37' => ['01'], '38' => ['25', '26'],
            default => [str_pad((string) $village['state'], 2, '0', STR_PAD_LEFT)],
        };
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published')->where('e.year', 2011)->whereIn('c.state_code', $states)
            ->where('c.level', 'VILLAGE')->where('c.residence', 'Rural')->where('c.geography->Town/Village', $code)
            ->select('c.id', 'c.state_code', 'c.district_code', 'c.geography', 'c.values', 'c.flags', 'e.source_url')
            ->orderByDesc('e.retrieved_at')->orderByDesc('e.id')->get();
        $identities = $rows->map(function (object $row): string {
            $geo = json_decode($row->geography, true);

            return implode(':', [$row->state_code, $row->district_code, $geo['Subdistt'] ?? '', $geo['Town/Village'] ?? '']);
        })->unique();
        if ($identities->count() !== 1) {
            return $result;
        }
        $row = $rows->first();
        $values = json_decode($row->values, true);
        $result['census_url'] = app(NamedPlaceUrls::class)->census($row);
        $result['census_source'] = $row->source_url;
        $result['census'] = ['year' => 2011, 'population' => $values['TOT_P'] ?? null, 'households' => $values['No_HH'] ?? null,
            'literate' => $values['P_LIT'] ?? null, 'flags' => json_decode($row->flags, true)];
        $denominator = isset($values['TOT_P'], $values['P_06']) ? $values['TOT_P'] - $values['P_06'] : null;
        $result['census']['literacy_rate'] = $denominator > 0 && isset($values['P_LIT']) ? round(100 * $values['P_LIT'] / $denominator, 2) : null;
        if (Route::has('district-dashboard')) {
            $district = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->join('census_publications as p', 'p.edition_id', '=', 'e.id')->where('e.status', 'published')->where('e.year', 2011)
                ->where('c.state_code', $row->state_code)->where('c.district_code', $row->district_code)->where('c.level', 'DISTRICT')->where('c.residence', 'Total')->value('c.name');
            if ($district) {
                $result['navigation'][] = ['label' => $district.' district dashboard · Census 2011',
                    'url' => route('district-dashboard', ['state' => $row->state_code, 'district' => $row->district_code])];
            }
        }

        return $result;
    }

    public function mapHistory(array $codes): array
    {
        $records = [];
        foreach (array_unique($codes) as $code) {
            $linked = $this->historicalRecords([], (string) $code);
            $history = $linked['census_history'] ?? [];
            if (empty($history) && isset($linked['census_directory'])) {
                $records[(string) $code] = ['year' => 2001, 'directory' => $linked['census_directory']];

                continue;
            }
            if (count($history) !== 1 || (int) $history[0]['year'] !== 2001) {
                continue;
            }
            $record = $history[0];
            $record['directory'] = $linked['census_directory'] ?? null;
            $record['values'] = VillageMapMetrics::values(['TOT_P' => $record['population'], 'No_HH' => $record['households'],
                'P_LIT' => $record['literate'], 'P_06' => $record['population_0_6']]);
            $records[(string) $code] = $record;
        }

        return $records;
    }

    private function historicalRecords(array $result, string $lgd): array
    {
        $result = $this->directoryNavigation($result, $lgd);
        $result = $this->nationalHistoricalRecords($result, $lgd);
        $path = database_path('fixtures/pilibhit-2001-profile-links.json');
        if ($lgd === '' || ! is_file($path)) {
            return $result;
        }
        $links = $this->historicalPilibhitLinks ??= json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $identity = $links['links'][$lgd] ?? null;
        if (! $identity || ! RequestSchema::hasTable('source_releases')) {
            return $result;
        }
        $query = ($this->links ??= app(ElectoralVillageMap::class)->database())->prepare('SELECT state,census2011 FROM directory WHERE lgd=?');
        $query->execute([$lgd]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        if (! $current || $current['state'] !== '9' || str_pad($current['census2011'], 6, '0', STR_PAD_LEFT) !== $identity['code2011']) {
            return $result;
        }
        $release = $this->historicalPilibhitRelease ??= DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'census-pilibhit-villages-2001')->where('r.status', 'accepted')
            ->orderByDesc('r.id')->select('r.id', 'r.sha256', 'r.payload')->first();
        if (! $release || (int) $release->id !== (int) $links['source_release_id'] || ! hash_equals($links['source_sha256'], $release->sha256)) {
            return $result;
        }
        $data = $this->historicalPilibhitData ??= json_decode($release->payload, true, flags: JSON_THROW_ON_ERROR);
        $records = collect($data['villages'] ?? [])->filter(fn (array $record): bool => (string) $record['code'] === $identity['code2001']
            && (string) $record['subdistrict_code'] === (string) $identity['subdistrict2001']);
        if (($data['year'] ?? null) !== 2001 || (string) ($data['district_code'] ?? '') !== $identity['district2001'] || $records->count() !== 1) {
            return $result;
        }
        $record = $records->first();
        $url = route('villages.show', ['code' => $record['code'], 'slug' => Str::slug($record['name']), 'year' => 2001]);
        $nativeHistory = array_values(array_filter($result['census_history'] ?? [], fn (array $history): bool => (int) ($history['year'] ?? 0) === 2001 && ($history['code'] ?? null) === $identity['code2001']
            && ($history['state_code'] ?? null) === $identity['state2001']
            && ($history['district_code'] ?? null) === $identity['district2001']
            && ($history['subdistrict_code'] ?? null) === $identity['subdistrict2001']));
        $result['census_history'] = [['year' => 2001, 'code' => $record['code'], 'state_code' => $identity['state2001'],
            'district_code' => $identity['district2001'], 'subdistrict_code' => $identity['subdistrict2001'],
            'population' => $record['population'] ?? null, 'households' => $record['households'] ?? null,
            'literate' => $record['literate'] ?? null, 'population_0_6' => $record['population_0_6'] ?? null,
            'pca' => count($nativeHistory) === 1 ? ($nativeHistory[0]['pca'] ?? null) : null,
            'source_release_id' => $release->id, 'source_sha256' => $release->sha256,
            'source_url' => $data['source_url'] ?? null, 'url' => $url, 'boundary_comparison' => 'pending']];
        $result['navigation'][] = ['label' => 'Census 2001 village profile', 'url' => $url];

        return $result;
    }

    private function directoryNavigation(array $result, string $lgd): array
    {
        $path = config('census-directory.index_path', database_path('fixtures/village-directory-2001-links.sqlite'));
        if ($lgd === '' || ! is_file($path) || ! RequestSchema::hasTable('census_editions')) {
            return $result;
        }
        $index = $this->historicalDirectoryIndex ??= new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro');
        $index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $query = $index->prepare('SELECT * FROM links WHERE lgd = ?');
        $query->execute([$lgd]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $source = config('census-sources.'.$link['source_key']);
            if (! in_array($source['schema'] ?? null, ['census-vd-explicit-counts-v1', 'census-vd-all-values-v1'], true)
                || ! hash_equals($source['review_package_sha256'], $link['package_sha256'])) {
                continue;
            }
            $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->join('census_publications as p', 'p.edition_id', '=', 'e.id')->join('import_runs as r', 'r.id', '=', 'e.import_run_id')
                ->where('c.id', $link['record_id'])->where('e.id', $link['edition_id'])->where('e.source_key', $link['source_key'])
                ->where('p.source_key', $link['source_key'])->where('e.status', 'published')->where('e.year', 2001)
                ->where('e.sha256', $link['source_sha256'])->where('c.record_key', $link['record_key'])
                ->where('c.level', 'VILLAGE')->where('c.residence', 'Rural')->select('c.id', 'c.values', 'c.geography', 'c.flags');
            $postgres = DB::connection()->getDriverName() === 'pgsql';
            $cachedHash = $this->directoryPackageHashes[$link['edition_id']] ?? null;
            $rows = $cachedHash !== null ? $rows : ($postgres ? $rows->selectRaw("encode(sha256(convert_to(r.extracted::text, 'UTF8')), 'hex') as package_hash") : $rows->addSelect('r.extracted'));
            $record = $rows->first();
            $packageHash = $record ? ($cachedHash ?? ($postgres ? $record->package_hash : hash('sha256', $record->extracted))) : null;
            if ($packageHash !== null) {
                $this->directoryPackageHashes[$link['edition_id']] = $packageHash;
            }
            if (! $record || ! hash_equals($link['package_sha256'], $packageHash)
                || ! app(CommunityData::class)->matchesOriginalValues($record->id, json_decode($record->values, true), $link['values_sha256'])) {
                continue;
            }
            $geo = json_decode($record->geography, true);
            if ([$geo['ST_CODE'] ?? null, $geo['DIST_CODE'] ?? null, $geo['THSIL_CODE'] ?? null, $geo['V_CT_CODE'] ?? null] !== json_decode($link['identity_json'], true)) {
                continue;
            }
            if (($source['schema'] ?? null) === 'census-vd-all-values-v1') {
                $result['census_directory'] = ['year' => 2001, 'identity' => $geo, 'values' => json_decode($record->values, true),
                    'fields' => $source['count_fields'], 'notes' => json_decode($record->flags, true), 'edit_url' => route('community.record', $record->id),
                    'source_url' => $source['url'], 'url' => route('census-catalogue.index', ['edition' => $link['edition_id']])];
            }
            $result['navigation'][] = ['label' => 'Village Directory 2001 · original facility counts', 'url' => route('census-catalogue.index', ['edition' => $link['edition_id']])];
        }

        return $result;
    }

    private function nationalHistoricalRecords(array $result, string $lgd): array
    {
        $path = database_path('fixtures/national-2001-profile-links.json');
        if ($lgd === '' || ! is_file($path) || ! RequestSchema::hasTable('census_editions')) {
            return $result;
        }
        $indexPath = database_path('fixtures/national-2001-profile-links.sqlite');
        if (is_file($indexPath)) {
            $index = $this->historicalPcaIndex ??= new PDO('sqlite:file:'.str_replace('\\', '/', $indexPath).'?mode=ro');
            $index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $statement = $index->prepare('SELECT identity_json FROM identities WHERE lgd = ?');
            $statement->execute([$lgd]);
            $encodedIdentity = $statement->fetchColumn();
            $identity = $encodedIdentity === false ? null : json_decode($encodedIdentity, true, flags: JSON_THROW_ON_ERROR);
        } else {
            $links = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $identity = $links['links'][$lgd] ?? null;
        }
        if (! $identity) {
            return $result;
        }
        $query = ($this->links ??= app(ElectoralVillageMap::class)->database())->prepare('SELECT state,census2011 FROM directory WHERE lgd=?');
        $query->execute([$lgd]);
        $current = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($current) !== 1 || str_pad((string) $current[0]['state'], 2, '0', STR_PAD_LEFT) !== $identity['state2011']
            || str_pad((string) $current[0]['census2011'], 6, '0', STR_PAD_LEFT) !== $identity['code2011']) {
            return $result;
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('c.id', $identity['record_id'])->where('e.source_key', $identity['source_key'])
            ->where('e.sha256', $identity['source_sha256'])->where('e.year', 2001)->where('e.status', 'published')
            ->where('c.level', 'VILLAGE')->where('c.residence', 'Rural')->where('c.state_code', $identity['state2001'])
            ->where('c.district_code', $identity['district2001'])
            ->select('c.*', 'e.id as edition_id', 'e.sha256', 'e.source_url', 'e.year as census_year')->get();
        if ($rows->count() !== 1) {
            return $result;
        }
        $row = $rows->first();
        $geo = json_decode($row->geography, true, flags: JSON_THROW_ON_ERROR);
        if (($geo['SUB-DISTT'] ?? $geo['TAHSIL'] ?? null) !== $identity['subdistrict2001']
            || (isset($geo['SUB-DISTT'], $geo['TAHSIL']) && $geo['SUB-DISTT'] !== $geo['TAHSIL']) || ($geo['TOWN_VILL'] ?? null) !== $identity['code2001']
            || (int) ($geo['WARD'] ?? -1) !== 0 || (int) ($geo['EB'] ?? -1) !== 0) {
            return $result;
        }
        $values = json_decode($row->values, true, flags: JSON_THROW_ON_ERROR);
        $urls = app(NamedPlaceUrls::class);
        $url = method_exists($urls, 'fromPublishedCensusRow')
            ? $urls->fromPublishedCensusRow($row)
            : $urls->census($row);
        $result['census_history'][] = ['year' => 2001, 'code' => $identity['code2001'], 'state_code' => $identity['state2001'],
            'district_code' => $identity['district2001'], 'subdistrict_code' => $identity['subdistrict2001'],
            'population' => $values['TOT_P'] ?? null, 'households' => $values['No_HH'] ?? null,
            'literate' => $values['P_LIT'] ?? null, 'population_0_6' => $values['P_06'] ?? null,
            'pca' => ['year' => 2001, 'values' => $values, 'fields' => array_combine(array_keys($values), array_keys($values)),
                'notes' => json_decode($row->flags, true), 'url' => $url, 'edit_url' => route('community.record', $row->id), 'source_url' => $row->source_url],
            'edition_id' => $row->edition_id, 'source_sha256' => $row->sha256, 'source_url' => $row->source_url,
            'source_sheet' => $identity['sheet'], 'source_row' => $identity['source_row'], 'url' => $url, 'boundary_comparison' => 'pending'];
        $result['navigation'][] = ['label' => 'Census 2001 village profile', 'url' => $url];

        return $result;
    }
}
