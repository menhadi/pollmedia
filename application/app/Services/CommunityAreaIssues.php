<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommunityAreaIssues
{
    public function context(string $kind, string $name = '', string $state = '', string $code = '', ?int $placeId = null): array
    {
        $state = ctype_digit($state) ? (string) (int) $state : $state;
        $context = ['kind' => $kind, 'name' => $name ?: 'India', 'keys' => [], 'place_id' => $placeId, 'available' => $kind === 'india'];
        if ($kind === 'india') {
            return $context;
        }
        if ($kind === 'village' && preg_match('/^[0-9]{1,8}$/', $code)) {
            return array_replace($context, ['keys' => ['village:'.$code], 'available' => true]);
        }
        if (! is_file(app(ElectoralVillageMap::class)->directory().'/links.sqlite')) {
            return $context;
        }
        $db = app(ElectoralVillageMap::class)->database();
        $states = $db->query('SELECT code,name FROM states')->fetchAll(\PDO::FETCH_ASSOC);
        $states = array_values(array_filter($states, fn ($s) => ($state !== '' && (string) $s['code'] === $state) || ($state !== '' && Str::slug($s['name']) === Str::slug($state)) || ($kind === 'state' && Str::slug($s['name']) === Str::slug($name))));
        if (count($states) !== 1) {
            return $context;
        }
        $stateCode = (string) $states[0]['code'];
        if ($kind === 'state') {
            return array_replace($context, ['keys' => ['state:'.$stateCode], 'available' => true]);
        }
        if ($kind === 'district') {
            $q = $db->prepare('SELECT DISTINCT a.district AS code,d.district AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd WHERE a.state=?');
            $q->execute([$stateCode]);
        } elseif (in_array($kind, ['ac', 'pc'])) {
            $q = $db->prepare('SELECT code,name FROM seats WHERE state=? AND kind=?');
            $q->execute([$stateCode, $kind]);
        } else {
            return $context;
        }
        $matches = array_values(array_filter($q->fetchAll(\PDO::FETCH_ASSOC), fn ($r) => $code !== '' ? (string) $r['code'] === ltrim($code, '0') : Str::slug($r['name']) === Str::slug($name)));
        $codes = array_values(array_unique(array_column($matches, 'code')));
        if (count($codes) === 1) {
            $context['keys'] = [$kind.':'.$stateCode.':'.$codes[0]];
            $context['available'] = true;
        }

        return $context;
    }

    public function censusContext(object $place, ?array $map): array
    {
        if ($place->level === 'STATE') {
            return $this->context('state', $place->name, (string) $place->state_code);
        }
        $keys = [];
        foreach ($map['codes'] ?? [] as $code) {
            if (preg_match('/^[0-9]{1,8}$/', (string) $code)) {
                $keys[] = 'village:'.$code;
            }
        }

        return ['kind' => strtolower($place->level), 'name' => $place->name, 'keys' => array_values(array_unique($keys)), 'place_id' => null, 'available' => $keys !== []];
    }

    public function place(object $place): array
    {
        $ids = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')->where('i.place_id', $place->id)->where('r.status', 'accepted')->select('i.*')->get();
        $lgd = $ids->firstWhere('namespace', 'lgd:IN:district');
        $state = '';
        $code = '';
        foreach ($ids as $id) {
            if (preg_match('/^electoral:IN:UP:(ac|pc)$/', $id->namespace)) {
                $state = '9';
                $code = (string) $id->code;
            }
        }
        if ($lgd) {
            $q = app(ElectoralVillageMap::class)->database()->prepare('SELECT DISTINCT state FROM ancestry WHERE district=?');
            $q->execute([(string) $lgd->code]);
            $states = $q->fetchAll(\PDO::FETCH_COLUMN);
            if (count($states) === 1) {
                $state = (string) $states[0];
                $code = (string) $lgd->code;
            }
        }

        return $this->context($place->type, $place->name, $state, $code, (int) $place->id);
    }

    public function feed(array $context): array
    {
        if (! Schema::hasTable('citizen_issue_scopes')) {
            return ['issues' => collect(), 'total' => 0, 'authorities' => collect(), 'contacts' => collect()];
        }
        $query = DB::table('citizen_issues as i')->whereIn('i.status', ['open', 'in_progress', 'resolved']);
        if ($context['kind'] !== 'india') {
            $query->where(function ($q) use ($context) {
                $q->whereIn('i.id', DB::table('citizen_issue_scopes')->whereIn('area_key', $context['keys'])->select('issue_id'));
                if ($context['place_id']) {
                    $q->orWhereIn('i.id', DB::table('citizen_issue_places')->where('place_id', $context['place_id'])->select('issue_id'));
                }
            });
        }
        $total = (clone $query)->count();
        $issues = (clone $query)->select('i.*')->selectSub(DB::table('citizen_issue_supports')->selectRaw('COUNT(*)')->whereColumn('issue_id', 'i.id')->where('direction', 1), 'supports')->orderByDesc('i.created_at')->paginate(12, ['*'], 'issues_page')->withQueryString()->fragment('community-issues');
        // Feed membership may use child villages; authority jurisdiction must match the page itself.
        $kind = $context['kind'] === 'sub-district' ? 'subdistrict' : $context['kind'];
        $keys = array_values(array_filter($context['keys'], fn ($key) => str_starts_with($key, $kind.':')));
        $contacts = app(IssueAuthorities::class)->contactsForKeys($keys);
        $authorities = collect();
        $placeIds = [];
        if (! empty($context['place_id'])) {
            $placeIds[] = $context['place_id'];
        }
        foreach ($keys as $key) {
            $parts = explode(':', $key);
            $namespace = in_array($kind, ['state', 'district', 'subdistrict', 'village'], true) ? 'lgd:IN:'.$kind : null;
            if ($namespace) {
                $ids = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                    ->where('r.status', 'accepted')->where('i.namespace', $namespace)->where('i.code', end($parts))->distinct()->pluck('i.place_id');
                if ($ids->count() === 1) {
                    $placeIds[] = $ids->first();
                }
            }
        }
        // The original Pilibhit office directory uses its accepted Census 2011 identity.
        // Retained Pilibhit LGD evidence maps this district to LGD 173 in Uttar Pradesh.
        if ($kind === 'district' && in_array('district:9:173', $keys, true)) {
            $legacyIds = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                ->where('r.status', 'accepted')->where('i.namespace', 'census:district:IN:UP')
                ->where('i.code', '151')->where('i.version', '2011')->distinct()->pluck('i.place_id');
            if ($legacyIds->count() === 1) {
                $placeIds[] = $legacyIds->first();
            }
        }
        if ($placeIds !== []) {
            $authorities = DB::table('office_jurisdictions as j')->join('offices as o', 'o.id', '=', 'j.office_id')
                ->join('source_releases as r', 'r.id', '=', 'j.source_release_id')->where('r.status', 'accepted')
                ->whereIn('j.place_id', $placeIds)
                ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))
                ->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))
                ->select('o.id', 'o.title', 'o.kind', 'r.url')->distinct()->orderBy('o.title')->get();
        }

        return compact('issues', 'total', 'authorities', 'contacts');
    }
}
