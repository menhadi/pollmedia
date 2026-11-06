<?php

namespace App\Services;

class MyPlaceServices
{
    public static function categories(): array
    {
        return [
            'health' => ['Health', 'स्वास्थ्य'], 'education' => ['Education', 'शिक्षा'],
            'agriculture' => ['Agriculture', 'कृषि'], 'water' => ['Water', 'पानी'],
            'electricity' => ['Electricity', 'बिजली'], 'roads' => ['Roads', 'सड़कें'],
            'sanitation' => ['Sanitation', 'साफ-सफाई'], 'welfare' => ['Welfare & schemes', 'कल्याण और योजनाएँ'],
            'revenue' => ['Revenue & land', 'राजस्व और भूमि'], 'transport' => ['Transport', 'परिवहन'],
            'environment' => ['Environment', 'पर्यावरण'], 'other' => ['Local administration', 'स्थानीय प्रशासन'],
        ];
    }

    public function directory(array $context): array
    {
        $records = [];
        $pilotKeys = [];
        if ($context['pilot']) {
            $pilot = app(PilibhitMyPlace::class)->services();
            foreach ($pilot['services'] as $service) {
                if ($service['kind'] === 'online') {
                    continue;
                }
                $source = $pilot['sources'][$service['source']];
                $category = ['farming' => 'agriculture', 'utilities' => $service['id'] === 'water-office' ? 'water' : 'electricity'][$service['category']] ?? $service['category'];
                $records[] = [
                    'name' => $service['name'], 'original_name' => null, 'categories' => [$category],
                    'address' => $service['address'], 'scope' => 'district', 'kind' => $service['kind'],
                    'phone' => $service['phone'], 'phone_href' => $this->phoneHref($service['phone']), 'email' => null,
                    'source_url' => $source['url'], 'checked_on' => $source['checked_on'],
                    'source_updated_on' => $source['directory_updated'],
                ];
                if ($service['phone']) {
                    $pilotKeys[$service['phone'].'|'.$source['url']] = true;
                }
            }
        }

        $keys = ['district' => ['district:'.$context['state']['code'].':'.$context['district']['code']]];
        if ($context['village']) {
            $keys = ['village' => ['village:'.$context['village']['code']]] + $keys;
        }
        $seen = [];
        $localPath = database_path('fixtures/issue-directory-contacts.json');
        $localEntries = is_file($localPath) ? json_decode(file_get_contents($localPath), true, flags: JSON_THROW_ON_ERROR)['entries'] ?? [] : [];
        foreach ($keys as $scope => $areaKeys) {
            $localCategories = [];
            foreach ($localEntries as $entry) {
                if (! array_intersect($entry['area_keys'], $areaKeys)) {
                    continue;
                }
                foreach ($entry['contacts'] as $contact) {
                    $identity = $contact['office'].'|'.($contact['phone'] ?? '').'|'.($contact['email'] ?? '');
                    $localCategories[$identity] = array_values(array_unique(array_merge($localCategories[$identity] ?? [], $entry['categories'])));
                }
            }
            foreach (app(IssueAuthorities::class)->contactsForKeys($areaKeys) as $contact) {
                $identity = $contact['office'].'|'.($contact['phone'] ?? '').'|'.($contact['email'] ?? '');
                if (isset($seen[$identity]) || isset($pilotKeys[($contact['phone'] ?? '').'|'.($contact['source_url'] ?? '')])) {
                    continue;
                }
                $seen[$identity] = true;
                $categories = ($contact['general_routing'] ?? false) ? ['other'] : (array_values(array_intersect($contact['categories'] ?? $localCategories[$identity] ?? ['other'], array_keys(self::categories()))) ?: ['other']);
                $label = self::categories()[$categories[0]];
                $records[] = [
                    'name' => ['en' => $contact['office'], 'hi' => $label[1].' संपर्क'],
                    'original_name' => $contact['office'], 'categories' => $categories,
                    'address' => null, 'scope' => $scope, 'kind' => 'office',
                    'phone' => $contact['phone'] ?? null, 'phone_href' => $contact['phone_href'] ?? $this->phoneHref($contact['phone'] ?? null),
                    'email' => filter_var($contact['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                    'source_url' => $contact['source_url'] ?? null, 'checked_on' => $contact['checked_on'] ?? null,
                    'source_updated_on' => $contact['source_updated_on'] ?? null,
                ];
            }
        }

        return $records;
    }

    private function phoneHref(?string $phone): ?string
    {
        $digits = preg_replace('/[\s().-]+/', '', $phone ?? '');
        if (preg_match('/^\+91[0-9]{10}$/D', $digits)) {
            return 'tel:'.$digits;
        }
        if (preg_match('/^0[0-9]{10}$/D', $digits)) {
            return 'tel:+91'.substr($digits, 1);
        }
        if (preg_match('/^[0-9]{10}$/D', $digits)) {
            return 'tel:+91'.$digits;
        }

        return preg_match('/^(?:[0-9]{3,6}|1800[0-9]{6,9})$/D', $digits) ? 'tel:'.$digits : null;
    }
}
