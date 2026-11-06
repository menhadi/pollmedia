<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IssueAuthorities
{
    public function available(string $issue): Collection
    {
        return DB::table('office_jurisdictions as j')->join('offices as o', 'o.id', '=', 'j.office_id')
            ->join('source_releases as r', 'r.id', '=', 'j.source_release_id')->where('r.status', 'accepted')
            ->whereIn('j.place_id', $this->placeIds($issue))
            ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))
            ->select('o.id', 'o.title', 'o.kind', 'r.url')->distinct()->orderBy('o.title')->get();
    }

    private function placeIds(string $issue): array
    {
        $places = DB::table('citizen_issue_places')->where('issue_id', $issue)->pluck('place_id')->all();
        foreach (DB::table('citizen_issue_scopes')->where('issue_id', $issue)->pluck('area_key') as $key) {
            $parts = explode(':', $key);
            $namespace = null;
            if (in_array($parts[0], ['village', 'district', 'subdistrict', 'state'], true)) {
                $namespace = 'lgd:IN:'.$parts[0];
            } elseif (in_array($parts[0], ['ac', 'pc'], true) && ($parts[1] ?? '') === '9') {
                $namespace = 'electoral:IN:UP:'.$parts[0];
            }
            if ($namespace === null) {
                continue;
            }
            $ids = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                ->where('r.status', 'accepted')->where('i.namespace', $namespace)->where('i.code', end($parts))
                ->distinct()->pluck('i.place_id');
            if ($ids->count() === 1) {
                $places[] = $ids->first();
            }
        }

        return array_values(array_unique($places));
    }

    /** Resolve routing suggestions without creating a reviewed responsibility assignment. */
    public function routing(string $issue, string $category): array
    {
        $departments = [
            'roads' => 'Road-owning local body, Rural Development or Public Works department',
            'water' => 'Local water-supply body or Public Health Engineering / water department',
            'sanitation' => 'Gram Panchayat or municipal sanitation department',
            'electricity' => 'Electricity distribution utility serving the problem location',
            'health' => 'District health department or the facility-owning health authority',
            'education' => 'Education department or the institution-owning authority',
            'revenue' => 'Revenue department or local revenue office',
            'agriculture' => 'District agriculture department or agricultural service office',
            'welfare' => 'Social welfare department or the office responsible for the welfare scheme',
            'transport' => 'Regional or district transport office or public transport authority',
            'environment' => 'Pollution control board, environment department or forest office, depending on the issue',
            'other' => 'Local administration for routing to the relevant service department',
        ];
        $keys = DB::table('citizen_issue_scopes')->where('issue_id', $issue)->pluck('area_key')->all();
        $identifiers = DB::table('place_identifiers as i')
            ->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
            ->where('r.status', 'accepted')->whereIn('i.place_id', $this->placeIds($issue))
            ->whereIn('i.namespace', ['lgd:IN:village', 'lgd:IN:district'])->get(['i.namespace', 'i.code']);
        foreach ($identifiers as $identifier) {
            $keys[] = str_replace('lgd:IN:', '', $identifier->namespace).':'.$identifier->code;
        }

        return ['department' => $departments[$category] ?? $departments['other'], 'contacts' => $this->contactsForKeys($keys, $category), 'offices' => $this->available($issue)];
    }

    public function contactsForKeys(array $keys, ?string $category = null): Collection
    {
        $file = database_path('fixtures/issue-directory-contacts.json');
        $directory = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        $contacts = collect();
        foreach ($directory['entries'] ?? [] as $entry) {
            if (! array_intersect($entry['area_keys'], $keys) || ($category !== null && ! in_array($category, $entry['categories'], true))) {
                continue;
            }
            foreach ($entry['contacts'] as $contact) {
                $contacts->push($contact);
            }
        }

        $districtFile = database_path('fixtures/issue-district-contacts.json');
        $districtKeys = array_filter($keys, fn ($key): bool => is_string($key) && str_starts_with($key, 'district:'));
        if ($districtKeys !== [] && is_file($districtFile)) {
            $version = filemtime($districtFile).':'.filesize($districtFile);
            $index = Cache::remember('issue-district-contact-index:v3:'.$version, 3600, function () use ($districtFile): array {
                $directory = json_decode(file_get_contents($districtFile), true, 512, JSON_THROW_ON_ERROR);
                $index = [];
                $position = 0;
                foreach ($directory['districts'] ?? [] as $key => $district) {
                    foreach ([$key, 'district:'.$district['district_code']] as $match) {
                        $index[serialize($match)][$position] = $position;
                    }
                    $position++;
                }

                return $index;
            });
            $selected = [];
            foreach ($districtKeys as $key) {
                if (is_string($key) || is_int($key)) {
                    foreach ($index[serialize($key)] ?? [] as $position) {
                        $selected[$position] = $position;
                    }
                }
            }
            sort($selected, SORT_NUMERIC);
            $districts = $selected === [] ? [] : Cache::remember('issue-district-contact-subset:v3:'.$version.':'.hash('sha256', serialize($selected)), 3600, function () use ($districtFile, $selected): array {
                $directory = json_decode(file_get_contents($districtFile), true, 512, JSON_THROW_ON_ERROR);
                $districts = [];
                $wanted = array_fill_keys($selected, true);
                $position = 0;
                foreach ($directory['districts'] ?? [] as $key => $district) {
                    if (isset($wanted[$position])) {
                        $districts[$key] = $district;
                    }
                    $position++;
                }

                return $districts;
            });
            foreach ($districts as $district) {
                foreach ($district['contacts'] as $contact) {
                    if (($contact['name_review_status'] ?? null) === 'historical_office_term') {
                        continue;
                    }
                    if ($category === null || in_array($category, $contact['categories'], true)) {
                        $contacts->push($contact);
                    }
                }
            }
        }
        $contacts = $contacts->map(function ($contact) {
            $contact['phone_href'] = null;
            $digits = preg_replace('/[^0-9]/', '', $contact['phone'] ?? '');
            if (preg_match('/^[+0-9 ()-]+$/', $contact['phone'] ?? '') && in_array(strlen($digits), [10, 11, 12], true)) {
                if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                    $digits = substr($digits, 1);
                }
                if (strlen($digits) === 10) {
                    $contact['phone_href'] = 'tel:+91'.$digits;
                } elseif (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $contact['phone_href'] = 'tel:+'.$digits;
                }
            }
            if (! empty($contact['source_updated_on']) && $contact['source_updated_on'] < now()->subYear()->toDateString()) {
                $contact['freshness'] = 'outdated';
            } elseif (empty($contact['source_updated_on']) && ! isset($contact['freshness'])) {
                $contact['freshness'] = 'undated';
            }

            return $contact;
        })->sortBy(fn ($contact) => (int) ($contact['general_routing'] ?? false));

        return $contacts->unique(fn ($c) => $c['office'].'|'.($c['phone'] ?? '').'|'.($c['email'] ?? ''))->values();
    }

    public function holders(array $offices): Collection
    {
        if ($offices === []) {
            return collect();
        }

        return DB::table('office_assignments as a')->join('people as p', 'p.id', '=', 'a.person_id')
            ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->whereIn('a.office_id', $offices)->where('r.status', 'accepted')->whereNull('a.superseded_at')
            ->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
            ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>', today()->toDateString()))
            ->select('a.office_id', 'p.display_name', 'a.verified_at', 'a.status', 'r.url')->get()->groupBy('office_id');
    }
}
