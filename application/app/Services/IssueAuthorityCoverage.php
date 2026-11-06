<?php

namespace App\Services;

class IssueAuthorityCoverage
{
    public const TYPES = ['roads', 'water', 'sanitation', 'electricity', 'health', 'education', 'revenue', 'agriculture', 'welfare', 'transport', 'environment', 'other'];

    public function report(): array
    {
        $read = fn ($name) => json_decode(file_get_contents(database_path('fixtures/'.$name)), true, 512, JSON_THROW_ON_ERROR);
        $national = $read('issue-district-contacts.json');
        $audit = $read('issue-contact-coverage.json');
        $local = $read('issue-directory-contacts.json');
        $rows = [];
        foreach ($national['districts'] as $key => $district) {
            $rows[$key] = ['key' => $key, 'state' => $district['state'], 'name' => $district['district'], 'contacts' => $district['contacts']];
        }
        foreach ($audit['missing_lgd_districts'] as [$state, $stateName, $district, $name]) {
            $key = 'district:'.$state.':'.$district;
            $rows[$key] ??= ['key' => $key, 'state' => $stateName, 'name' => $name, 'contacts' => []];
        }
        foreach ($local['entries'] as $entry) {
            foreach ($entry['area_keys'] as $key) {
                if (isset($rows[$key])) {
                    foreach ($entry['contacts'] as $contact) {
                        $contact['categories'] = $entry['categories'];
                        $rows[$key]['contacts'][] = $contact;
                    }
                    break;
                }
            }
        }
        $totals = array_fill_keys(self::TYPES, ['available' => 0, 'routing_only' => 0, 'missing' => 0]);
        $allContacts = 0;
        foreach ($rows as &$row) {
            $row['contacts'] = collect($row['contacts'])->unique(fn ($c) => mb_strtolower($c['office']).'|'.preg_replace('/\D/', '', $c['phone'] ?? '').'|'.mb_strtolower($c['email'] ?? ''))->values()->all();
            $allContacts += count($row['contacts']);
            $row['older'] = 0;
            $row['undated'] = 0;
            foreach ($row['contacts'] as &$contact) {
                $contact['older'] = ($contact['freshness'] ?? null) === 'outdated' || (! empty($contact['source_updated_on']) && $contact['source_updated_on'] < now()->subYear()->toDateString());
                $row['older'] += (int) $contact['older'];
                $row['undated'] += (int) empty($contact['source_updated_on']);
            }
            unset($contact);
            $row['types'] = [];
            foreach (self::TYPES as $type) {
                $contacts = array_filter($row['contacts'], fn ($c) => in_array($type, $c['categories'], true));
                $specific = array_filter($contacts, fn ($c) => $type === 'other' || ! ($c['general_routing'] ?? false));
                $status = count($specific) ? 'available' : (count($contacts) ? 'routing_only' : 'missing');
                $row['types'][$type] = ['status' => $status, 'count' => count($specific)];
                $totals[$type][$status]++;
            }
            $row['complete'] = collect($row['types'])->every(fn ($t) => $t['status'] === 'available');
        }
        unset($row);
        usort($rows, fn ($a, $b) => [$a['state'], $a['name']] <=> [$b['state'], $b['name']]);
        $paths = ['issue-district-contacts.json', 'issue-directory-contacts.json', 'issue-contact-coverage.json'];

        return ['rows' => $rows, 'totals' => $totals, 'contactCount' => $allContacts, 'types' => self::TYPES,
            'updated' => gmdate('Y-m-d H:i:s', max(array_map(fn ($p) => filemtime(database_path('fixtures/'.$p)), $paths))).' UTC',
            'unresolved' => $audit['unresolved_district_names'] ?? []];
    }
}
