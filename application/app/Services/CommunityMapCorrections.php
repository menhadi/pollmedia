<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;

class CommunityMapCorrections
{
    public function read(string $file): array
    {
        abort_unless(preg_match('~^(?:india-overview(?:-2001)?-metrics\.json|constituency-map-metrics\.json|census-2001-facility-distributions\.json|census-(?:2001|2011)-fields/(?:field-[a-f0-9]+|[A-Za-z0-9_]+)\.json|constituency-fields-2001/[A-Za-z0-9_]+\.json)$~D', $file), 404);
        $path = public_path('maps/'.$file);
        abort_unless(is_file($path), 404);
        $revision = $this->revision();
        $key = 'community-map-v1:'.hash('sha256', $file.':'.filemtime($path).':'.filesize($path).':'.$revision);

        return Cache::remember($key, 300, fn (): array => $this->apply(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $file));
    }

    public function apply(array $data, string $file): array
    {
        if (! isset($data['groups']) || ! in_array((int) ($data['year'] ?? 0), [2001, 2011], true)) {
            return $data;
        }
        $changes = DB::table('community_data_proposals as p')->join('census_catalogue_rows as r', 'r.id', '=', 'p.record_id')->join('census_editions as e', 'e.id', '=', 'r.edition_id')->join('census_publications as active', function ($join): void {
            $join->on('active.edition_id', '=', 'e.id')->on('active.source_key', '=', 'e.source_key');
        })->where('p.status', 'approved')->where(fn ($query) => $query->where(fn ($q) => $q->where('r.level', 'VILLAGE')->where('r.residence', 'Rural'))->orWhere(fn ($q) => $q->where('r.level', 'DISTRICT')->where('r.residence', 'Total')))->where('e.status', 'published')->where('e.year', $data['year'])->where('p.field', '!=', 'listing_name')->orderByDesc('p.reviewed_at')->orderByDesc('p.id')->select('p.*', 'r.values', 'r.geography', 'r.state_code', 'r.district_code', 'r.level', 'e.source_key', 'e.year')->get()->groupBy('record_id');
        $preferred = [];
        $overrides = [];
        if ((int) $data['year'] === 2001 && ! isset($data['field'])) {
            foreach ($changes as $record => $edits) {
                $row = $edits->first();
                if ($row->level !== 'VILLAGE' || str_starts_with($row->source_key, 'census-2001-village-directory-')) {
                    continue;
                }
                if (! $edits->contains(fn (object $edit): bool => in_array($edit->field, ['TOT_P', 'No_HH'], true))) {
                    continue;
                }
                $baseline = $this->directoryBaseline((int) $record);
                if (! $baseline) {
                    continue;
                }$current = json_decode($row->values, true);
                foreach (['population' => ['TOT_P', 'T_P'], 'households' => ['No_HH', 'T_HH']] as $metric => [$pca,$directory]) {
                    if ($edits->contains('field', $pca)) {
                        $overrides[$record][$metric] = [$baseline['values'][$directory] ?? null, $current[$pca] ?? null];
                        $preferred[$baseline['record_id']][$metric] = true;
                    }
                }
            }
        }
        $held = 0;
        foreach ($changes as $record => $edits) {
            $row = $edits->first();
            $current = json_decode($row->values, true);
            $before = $current;
            foreach ($edits as $edit) {
                $before[$edit->field] = json_decode($edit->before_value, true);
            }
            $parents = $this->parents($row, (int) $record);
            if (! $parents) {
                $held++;

                continue;
            }
            $directory = str_starts_with($row->source_key, 'census-2001-village-directory-');
            $prefix = (int) $row->year === 2011 ? 'c11_' : ($directory ? 'vd_' : 'pca_');
            foreach ($parents as $group) {
                if (! isset($data['groups'][$group])) {
                    continue;
                }
                if (isset($data['community_direct_records'][$group])) {
                    continue;
                }
                $summary = &$data['groups'][$group];
                if ($row->level === 'DISTRICT') {
                    if (isset($data['field']) && str_starts_with($data['field'], $prefix)) {
                        $raw = substr($data['field'], strlen($prefix));
                        if (! array_key_exists($raw, $current)) {
                            unset($summary);

                            continue;
                        } $value = $current[$raw] ?? null;
                        $summary = [1, (int) ($value !== null && $value !== ''), (int) $this->numeric($value), $this->numeric($value) ? $value : 0, $value !== null && ! $this->numeric($value) ? [json_encode($value) => 1] : [], 0];
                    } elseif (! isset($data['field']) && ! isset($summary['fields'])) {
                        foreach (['population' => 'TOT_P', 'households' => 'No_HH'] as $metric => $raw) {
                            $summary[$metric] = $this->numeric($current[$raw] ?? null) ? $current[$raw] : null;
                            $summary['counts'][$metric] = (int) ($summary[$metric] !== null);
                        }
                        $literacy = $this->literacy($current);
                        $summary['literate'] = $literacy[0] ?? null;
                        $summary['population_7_plus'] = $literacy[1] ?? null;
                        $summary['literacy'] = $literacy ? round(100 * $literacy[0] / $literacy[1], 2) : null;
                        $summary['counts']['literacy'] = (int) ($literacy !== null);
                        $summary['notes'][] = 'Administrator-approved official district source record '.$record.'. Current parent identity is unique; historical boundary equivalence is not asserted.';
                    }
                    if (isset($data['field']) && ! str_starts_with($data['field'], $prefix)) {
                        unset($summary);

                        continue;
                    }
                    $data['community_direct_records'][$group] = (int) $record;
                    unset($summary);

                    continue;
                }

                if (isset($data['field'])) {
                    $field = $data['field'];
                    if (! str_starts_with($field, $prefix)) {
                        continue;
                    }$raw = substr($field, strlen($prefix));
                    if (array_key_exists($raw, $before) || array_key_exists($raw, $current)) {
                        $this->native($summary, $before[$raw] ?? null, $current[$raw] ?? null);
                    }
                } elseif (isset($summary['fields'])) {
                    if (! $directory) {
                        continue;
                    }
                    foreach ($summary['fields'] as $field => &$distribution) {
                        $old = $before[$field] ?? null;
                        $new = $current[$field] ?? null;
                        if ($old === $new) {
                            continue;
                        }$this->distribution($distribution, $old, $new);
                    }unset($distribution);
                } else {
                    $population = $directory ? 'T_P' : 'TOT_P';
                    $households = $directory ? 'T_HH' : 'No_HH';
                    if ((int) $row->year === 2011 || $directory) {
                        if (! isset($preferred[$record]['population'])) {
                            $this->sum($summary, 'population', $before[$population] ?? null, $current[$population] ?? null);
                        }
                        if (! isset($preferred[$record]['households'])) {
                            $this->sum($summary, 'households', $before[$households] ?? null, $current[$households] ?? null);
                        }
                    }
                    foreach ($overrides[$record] ?? [] as $metric => [$original,$corrected]) {
                        $this->sum($summary, $metric, $original, $corrected);
                        $summary['notes'][] = 'Approved PCA '.$metric.' correction uses the same existing village identity. Original Village Directory and PCA values remain accessible separately.';
                    }
                    if (! $directory) {
                        $old = $this->literacy($before);
                        $new = $this->literacy($current);
                        $summary['literate'] = ($summary['literate'] ?? 0) - ($old[0] ?? 0) + ($new[0] ?? 0);
                        $summary['population_7_plus'] = ($summary['population_7_plus'] ?? 0) - ($old[1] ?? 0) + ($new[1] ?? 0);
                        $summary['counts']['literacy'] = max(0, ($summary['counts']['literacy'] ?? 0) - (int) ($old !== null) + (int) ($new !== null));
                        $summary['literacy'] = $summary['counts']['literacy'] > 0 && $summary['population_7_plus'] > 0 ? round(100 * $summary['literate'] / $summary['population_7_plus'], 2) : null;
                    }
                }
                unset($summary);
            }
        }
        $data['community_corrections'] = ['revision' => $this->revision(), 'records' => count($changes), 'unlinked_records' => $held, 'note' => 'Administrator-approved corrections are applied to linked source records. Original files and dated identities are preserved. Unlinked corrections do not receive map identities.'];

        return $data;
    }

    public function directoryBaseline(int $record): ?array
    {
        $pcaPath = config('community-map.pca_index_path', database_path('fixtures/national-2001-profile-links.sqlite'));
        $directoryPath = config('census-directory.index_path', database_path('fixtures/village-directory-2001-links.sqlite'));
        if (! is_file($pcaPath) || ! is_file($directoryPath)) {
            return null;
        }
        $index = new PDO('sqlite:file:'.str_replace('\\', '/', $pcaPath).'?mode=ro');
        $q = $index->prepare("SELECT lgd FROM identities WHERE CAST(json_extract(identity_json,'$.record_id') AS INTEGER)=CAST(? AS INTEGER)");
        $q->execute([$record]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            return null;
        }
        $directory = new PDO('sqlite:file:'.str_replace('\\', '/', $directoryPath).'?mode=ro');
        $q = $directory->prepare("SELECT record_id,record_key,values_sha256 FROM links WHERE lgd=? AND source_key LIKE 'census-2001-village-directory-all-%'");
        $q->execute([$ids[0]]);
        $links = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($links) !== 1) {
            return null;
        }$link = $links[0];
        $row = app(CommunityData::class)->publishedRows()->where('r.id', $link['record_id'])->where('r.record_key', $link['record_key'])->where('e.year', 2001)->select('r.values')->first();
        if (! $row) {
            return null;
        }$values = json_decode($row->values, true);
        foreach (DB::table('community_data_proposals')->where('record_id', $link['record_id'])->where('status', 'approved')->where('field', '!=', 'listing_name')->orderByDesc('reviewed_at')->orderByDesc('id')->get() as $change) {
            $values[$change->field] = json_decode($change->before_value, true);
        }
        if (! hash_equals($link['values_sha256'], hash('sha256', json_encode($values)))) {
            return null;
        }

        return ['record_id' => (int) $link['record_id'], 'values' => $values];
    }

    public function revision(): string
    {
        $q = DB::table('community_data_proposals')->where('status', 'approved');

        return $q->count().':'.($q->max('id') ?? 0);
    }

    private function numeric(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function sum(array &$group, string $field, mixed $old, mixed $new): void
    {
        $count = ($group['counts'][$field] ?? 0) - (int) $this->numeric($old) + (int) $this->numeric($new);
        $group['counts'][$field] = max(0, $count);
        $group[$field] = $count > 0 ? ($group[$field] ?? 0) - ($this->numeric($old) ? $old : 0) + ($this->numeric($new) ? $new : 0) : null;
    }

    private function literacy(array $values): ?array
    {
        foreach (['TOT_P', 'P_06', 'P_LIT'] as $field) {
            if (! $this->numeric($values[$field] ?? null) || $values[$field] < 0) {
                return null;
            }
        }
        $denominator = $values['TOT_P'] - $values['P_06'];

        return $denominator > 0 && $values['P_LIT'] <= $denominator ? [$values['P_LIT'], $denominator] : null;
    }

    private function native(array &$summary, mixed $old, mixed $new): void
    {
        if ($old === $new || ! array_is_list($summary) || count($summary) < 6) {
            return;
        }
        $summary[1] += (int) ($new !== null && $new !== '') - (int) ($old !== null && $old !== '');
        $summary[2] += (int) $this->numeric($new) - (int) $this->numeric($old);
        $summary[3] += ($this->numeric($new) ? $new : 0) - ($this->numeric($old) ? $old : 0);
        $trackDistribution = ! empty($summary[4]);
        foreach ([[$old, -1], [$new, 1]] as [$value,$delta]) {
            if ($value === null || $value === '') {
                continue;
            }$key = json_encode($value);
            if ($delta < 0 && ! isset($summary[4][$key])) {
                continue;
            }if ($delta > 0 && $this->numeric($value) && ! $trackDistribution) {
                continue;
            }$summary[4][$key] = max(0, ($summary[4][$key] ?? 0) + $delta);
            if ($summary[4][$key] === 0) {
                unset($summary[4][$key]);
            }
        }
    }

    private function distribution(array &$summary, mixed $old, mixed $new): void
    {
        $oldReported = $old !== null && $old !== '';
        $newReported = $new !== null && $new !== '';
        $summary['reported'] += (int) $newReported - (int) $oldReported;
        $summary['missing'] -= (int) $newReported - (int) $oldReported;
        foreach ([[$old, -1], [$new, 1]] as [$value,$delta]) {
            $key = json_encode($value);
            $summary['raw_distribution'][$key] = max(0, ($summary['raw_distribution'][$key] ?? 0) + $delta);
            if ($summary['raw_distribution'][$key] === 0) {
                unset($summary['raw_distribution'][$key]);
            }
        }
    }

    public function parents(object $row, int $record): array
    {
        $paths = [app(ElectoralVillageMap::class)->directory().'/links.sqlite', config('census-directory.index_path', database_path('fixtures/village-directory-2001-links.sqlite')), config('community-map.pca_index_path', database_path('fixtures/national-2001-profile-links.sqlite'))];
        $version = array_map(fn (string $path): string => is_file($path) ? filemtime($path).':'.filesize($path) : 'missing', $paths);

        return Cache::remember('community-map-parents-v1:'.hash('sha256', json_encode([$record, $row->year, $row->geography, $version])), 3600, fn (): array => $this->resolveParents($row, $record));
    }

    private function resolveParents(object $row, int $record): array
    {
        $map = app(ElectoralVillageMap::class);
        $database = $map->database();
        $lgd = null;
        if (($row->level ?? 'VILLAGE') === 'DISTRICT') {
            $ids = [];
            if ((int) $row->year === 2001) {
                $path = config('community-map.pca_index_path', database_path('fixtures/national-2001-profile-links.sqlite'));
                if (! is_file($path)) {
                    return [];
                }
                $index = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro');
                $q = $index->prepare("SELECT lgd FROM identities WHERE printf('%02d',json_extract(identity_json,'$.state2001'))=? AND printf('%03d',json_extract(identity_json,'$.district2001'))=?");
                $q->execute([str_pad($row->state_code, 2, '0', STR_PAD_LEFT), str_pad($row->district_code, 3, '0', STR_PAD_LEFT)]);
                $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $codes = app(CommunityData::class)->publishedRows()->where('e.year', 2011)->where('r.level', 'VILLAGE')->where('r.state_code', $row->state_code)->where('r.district_code', $row->district_code)->select('r.geography')->distinct()->get()->map(fn (object $record): mixed => json_decode($record->geography, true)['Town/Village'] ?? null)->filter()->unique()->all();
                $states = match ($row->state_code) {
                    '28' => ['28', '36'],'01' => ['1', '37'],'25','26' => ['25', '26', '38'],default => [(string) (int) $row->state_code]
                };
                foreach (array_chunk($codes, 400) as $chunk) {
                    $q = $database->prepare('SELECT lgd FROM directory WHERE state IN ('.implode(',', array_fill(0, count($states), '?')).') AND census2011 IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
                    $q->execute([...$states, ...$chunk]);
                    $ids = array_merge($ids, $q->fetchAll(PDO::FETCH_COLUMN));
                }
            }
            $parents = [];
            foreach (array_chunk(array_unique($ids), 400) as $chunk) {
                $q = $database->prepare('SELECT DISTINCT state,district FROM ancestry WHERE lgd IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
                $q->execute($chunk);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $parent) {
                    $parents[$parent['state'].':'.$parent['district'].':'] = true;
                }
            }

            return count($parents) === 1 ? array_keys($parents) : [];
        }

        if ((int) $row->year === 2001) {
            $directory = str_starts_with($row->source_key, 'census-2001-village-directory-');
            $path = $directory ? config('census-directory.index_path', database_path('fixtures/village-directory-2001-links.sqlite')) : config('community-map.pca_index_path', database_path('fixtures/national-2001-profile-links.sqlite'));
            if (! is_file($path)) {
                return [];
            }$index = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro');
            $query = $index->prepare($directory ? 'SELECT DISTINCT lgd FROM links WHERE record_id=?' : "SELECT lgd FROM identities WHERE CAST(json_extract(identity_json,'$.record_id') AS INTEGER)=CAST(? AS INTEGER)");
            $query->execute([$record]);
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) {
                return [];
            }$lgd = $ids[0];
        } else {
            $geo = json_decode($row->geography, true);
            $code = $geo['Town/Village'] ?? null;
            if (! $code) {
                return [];
            }
            $states = match ($row->state_code) {
                '28' => ['28', '36'],'01' => ['1', '37'],'25','26' => ['25', '26', '38'],default => [(string) (int) $row->state_code]
            };
            $query = $database->prepare('SELECT lgd FROM directory WHERE census2011=? AND state IN ('.implode(',', array_fill(0, count($states), '?')).')');
            $query->execute([(string) $code, ...$states]);
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) {
                return [];
            }$lgd = $ids[0];
        }
        $query = $database->prepare('SELECT state,district,subdistrict FROM ancestry WHERE lgd=?');
        $query->execute([$lgd]);
        $parent = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($parent) !== 1) {
            return [];
        }$parent = $parent[0];
        $keys = [$parent['state'].':'.$parent['district'].':'];
        if ($parent['subdistrict'] !== null) {
            $keys[] = $parent['state'].':'.$parent['district'].':'.$parent['subdistrict'];
        }
        $connections = $map->connections((string) $lgd);
        foreach ($connections['constituencies'] ?? [] as $seat) {
            if (! $seat['partial']) {
                $keys[] = $parent['state'].':'.$seat['kind'].':'.$seat['code'];
            }
        }

        return array_unique($keys);
    }
}
