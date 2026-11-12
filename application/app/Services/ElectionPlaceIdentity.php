<?php

namespace App\Services;

class ElectionPlaceIdentity
{
    /** Navigation aliases only; original state labels and historical boundaries remain in source records. */
    private const STATE_ALIASES = ['madras' => 'Tamil Nadu', 'mysore' => 'Karnataka', 'orissa' => 'Odisha', 'orrisa' => 'Odisha', 'uttaranchal' => 'Uttarakhand', 'pondicherry' => 'Puducherry', 'kerla' => 'Kerala', 'gujrat' => 'Gujarat', 'goa daman and diu' => 'Goa', 'goa daman & diu' => 'Goa', 'delhi' => 'Delhi', 'nct of delhi' => 'Delhi', 'national capital territory of delhi' => 'Delhi'];

    /**
     * Normalised label key => present-day state or union territory.
     * Historical report names and district-era labels are grouped under their successor.
     */
    private const CANONICAL_STATES = [
        'andamannicobar' => 'Andaman & Nicobar Islands',
        'andamannicobarisland' => 'Andaman & Nicobar Islands',
        'andamannicobarislands' => 'Andaman & Nicobar Islands',
        'aandnislands' => 'Andaman & Nicobar Islands',
        'andamanandnicobarislands' => 'Andaman & Nicobar Islands',
        'andamanandnicobarisland' => 'Andaman & Nicobar Islands',
        'ap' => 'Andhra Pradesh',
        'andhrapradesh' => 'Andhra Pradesh',
        'andharapradesh' => 'Andhra Pradesh',
        'arunachalpradesh' => 'Arunachal Pradesh',
        'assam' => 'Assam',
        'bihar' => 'Bihar',
        'chandigarh' => 'Chandigarh',
        'chhattisgarh' => 'Chhattisgarh',
        'chattisgarh' => 'Chhattisgarh',
        'chhatisgarh' => 'Chhattisgarh',
        'dadraandnagarhaveli' => 'Dadra & Nagar Haveli and Daman & Diu',
        'dandnhaveli' => 'Dadra & Nagar Haveli and Daman & Diu',
        'dadraandnagarhavelianddamananddiu' => 'Dadra & Nagar Haveli and Daman & Diu',
        'damananddiu' => 'Dadra & Nagar Haveli and Daman & Diu',
        'delhi' => 'Delhi',
        'nctofdelhi' => 'Delhi',
        'nationalcapitalterritoryofdelhi' => 'Delhi',
        'nationalcapitalterritor' => 'Delhi',
        'goa' => 'Goa',
        'goadamananddiu' => 'Goa',
        'gujarat' => 'Gujarat',
        'gujrat' => 'Gujarat',
        'gj' => 'Gujarat',
        'haryana' => 'Haryana',
        'himachalpradesh' => 'Himachal Pradesh',
        'jandk' => 'Jammu & Kashmir',
        'jammuandkashmir' => 'Jammu & Kashmir',
        'jammukashmir' => 'Jammu & Kashmir',
        'jharkhand' => 'Jharkhand',
        'karnataka' => 'Karnataka',
        'kerala' => 'Kerala',
        'kerla' => 'Kerala',
        'ladakh' => 'Ladakh',
        'lakshadweep' => 'Lakshadweep',
        'laccadiveminicoyandamindiviislands' => 'Lakshadweep',
        'lmandaislands' => 'Lakshadweep',
        'madhyapradesh' => 'Madhya Pradesh',
        'madyapradesh' => 'Madhya Pradesh',
        'maharashtra' => 'Maharashtra',
        'manipur' => 'Manipur',
        'meghalaya' => 'Meghalaya',
        'mizoram' => 'Mizoram',
        'nagaland' => 'Nagaland',
        'odisha' => 'Odisha',
        'orissa' => 'Odisha',
        'orrisa' => 'Odisha',
        'puducherry' => 'Puducherry',
        'podicherry' => 'Puducherry',
        'pondicherry' => 'Puducherry',
        'punjab' => 'Punjab',
        'pepsu' => 'Punjab',
        'rajasthan' => 'Rajasthan',
        'rj' => 'Rajasthan',
        'sikkim' => 'Sikkim',
        'tamilnadu' => 'Tamil Nadu',
        'tn' => 'Tamil Nadu',
        'telangana' => 'Telangana',
        'hydera' => 'Telangana',
        'tripura' => 'Tripura',
        'uttarpradesh' => 'Uttar Pradesh',
        'up' => 'Uttar Pradesh',
        'uttarpardesh' => 'Uttar Pradesh',
        'uttatpradesh' => 'Uttar Pradesh',
        'uttarakhand' => 'Uttarakhand',
        'uttrakhand' => 'Uttarakhand',
        'uttaranchal' => 'Uttarakhand',
        'hp' => 'Himachal Pradesh',
        'himachal' => 'Himachal Pradesh',
        'haryanajind' => 'Haryana',
        'karnatka' => 'Karnataka',
        'karnattaka' => 'Karnataka',
        'mbharat' => 'Madhya Pradesh',
        'mp' => 'Madhya Pradesh',
        'mharashtra' => 'Maharashtra',
        'meghalya' => 'Meghalaya',
        'meghalayawestkhasihillsdistrict' => 'Meghalaya',
        'sauras' => 'Gujarat',
        'sowrashtra' => 'Gujarat',
        'tcochin' => 'Kerala',
        'travcoc' => 'Kerala',
        'utdelhi' => 'Delhi',
        'vp' => 'Madhya Pradesh',
        'wb' => 'West Bengal',
        'westbemgal' => 'West Bengal',
        'westbengalnorth24paraganas' => 'West Bengal',
        'gujaratstate' => 'Gujarat',
        'westbengal' => 'West Bengal',
        // Historical states and agencies, grouped under their successor state.
        'ajmer' => 'Rajasthan',
        'bhopal' => 'Madhya Pradesh',
        'bilaspur' => 'Himachal Pradesh',
        'bombay' => 'Maharashtra',
        'coorg' => 'Karnataka',
        'hyderabad' => 'Telangana',
        'kutch' => 'Gujarat',
        'kutchh' => 'Gujarat',
        'saurashtra' => 'Gujarat',
        'sourastra' => 'Gujarat',
        'madhyabharat' => 'Madhya Pradesh',
        'vindhyapradesh' => 'Madhya Pradesh',
        'mysore' => 'Karnataka',
        'madras' => 'Tamil Nadu',
        'patialaandeastpunjabstatesunion' => 'Punjab',
        'travancorecochin' => 'Kerala',
    ];

    private static function labels(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/eci-state-search-labels.json')), true, 512, JSON_THROW_ON_ERROR)['labels'];
    }

    private static function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('&', 'and', $value);
        $value = preg_replace('/\bcode\b.*$/u', '', $value) ?? $value;
        $value = preg_replace('/[- ]*s[- ]*\d+$/u', '', $value) ?? $value;
        $value = preg_replace('/[- ]*u\d+$/u', '', $value) ?? $value;
        $value = preg_replace('/[- ]*state$/u', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';
    }

    public static function stateSql(): string
    {
        $sql = "CASE regexp_replace(regexp_replace(regexp_replace(regexp_replace(regexp_replace(replace(lower(trim(state_label)), '&', 'and'), ' code.*$', ''), '[- ]*s[- ]*[0-9]+$', ''), '[- ]*u[0-9]+$', ''), '[- ]*state$', ''), '[^a-z0-9]+', '', 'g')";
        foreach (self::CANONICAL_STATES as $key => $label) {
            $sql .= " WHEN '".str_replace("'", "''", $key)."' THEN '".str_replace("'", "''", mb_strtolower($label))."'";
        }

        return $sql.' ELSE lower(trim(state_label)) END';
    }

    public static function state(string $state): string
    {
        $key = self::key($state);
        if (isset(self::CANONICAL_STATES[$key])) {
            return self::CANONICAL_STATES[$key];
        }
        $phrase = mb_strtolower(trim($state));
        if (isset(self::STATE_ALIASES[$phrase])) {
            return self::STATE_ALIASES[$phrase];
        }
        $labels = self::labels();
        if (isset($labels[$phrase])) {
            return self::CANONICAL_STATES[self::key($labels[$phrase])]
                ?? (self::STATE_ALIASES[mb_strtolower($labels[$phrase])] ?? $labels[$phrase]);
        }
        foreach ($labels as $label) {
            if (mb_strtolower($label) === $phrase) {
                return self::CANONICAL_STATES[self::key($label)] ?? (self::STATE_ALIASES[mb_strtolower($label)] ?? $label);
            }
        }

        return ElectionGeographySummary::currentStateName($state) ?? trim($state);
    }
}
