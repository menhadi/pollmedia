<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PilibhitSeeder extends Seeder
{
    private function row(string $table, array $key, array $values): int
    {
        DB::table($table)->updateOrInsert($key, $values);

        return DB::table($table)->where($key)->value('id');
    }

    public function run(): void
    {
        DB::transaction(function () {
            $checked = '2026-09-15'; // Source checks recorded at date precision, not invented appointment dates.
            $sample = json_decode(file_get_contents(database_path('fixtures/sample.json')), true, 512, JSON_THROW_ON_ERROR);
            $crosswalk = json_decode(ltrim(file_get_contents(database_path('fixtures/crosswalk-summary.json')), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
            $release = function ($key, $publisher, $url, $payload = null, $sha = null) use ($checked) {
                $source = $this->row('data_sources', ['key' => $key], ['publisher' => $publisher, 'url' => $url, 'last_checked_at' => $checked, 'reuse_status' => 'review_required']);

                return $this->row('source_releases', ['data_source_id' => $source, 'version_key' => $sha ?? 'checked-2026-09-15'], ['url' => $url, 'retrieved_at' => $checked, 'sha256' => $sha, 'status' => 'accepted', 'payload' => $payload === null ? null : json_encode($payload)]);
            };
            $census = $release('census-pilibhit', 'Census of India', $sample['census']['landing'], $sample['census'], $sample['census']['sha256']);
            $villageData = json_decode(file_get_contents(database_path('fixtures/pilibhit-villages-2011.json')), true, 512, JSON_THROW_ON_ERROR);
            $release('census-pilibhit-villages-2011', 'Census of India', $villageData['landing'], $villageData, $villageData['sha256']);
            $historicalVillages = json_decode(file_get_contents(database_path('fixtures/pilibhit-villages-2001.json')), true, 512, JSON_THROW_ON_ERROR);
            $historicalRelease = $release('census-pilibhit-villages-2001', 'Census of India', $historicalVillages['landing'], $historicalVillages, $historicalVillages['sha256']);
            DB::table('source_releases')->where('id', $historicalRelease)->update(['retrieved_at' => '2026-09-16']);
            $blockMapping = json_decode(file_get_contents(database_path('fixtures/pilibhit-block-mapping.json')), true, 512, JSON_THROW_ON_ERROR);
            $blockRelease = $release('census-pilibhit-block-mapping', 'Census of India', $blockMapping['url'], $blockMapping, $blockMapping['sha256']);
            DB::table('source_releases')->where('id', $blockRelease)->update(['retrieved_at' => $blockMapping['checked_on']]);
            $lgd = json_decode(file_get_contents(database_path('fixtures/pilibhit-lgd.json')), true, 512, JSON_THROW_ON_ERROR);
            $lgdRelease = $release('lgd-pilibhit', 'Local Government Directory, Ministry of Panchayati Raj', $lgd['url'], $lgd, $lgd['sha256']);
            DB::table('source_releases')->where('id', $lgdRelease)->update(['retrieved_at' => $lgd['checked_on']]);
            DB::table('data_sources')->where('key', 'lgd-pilibhit')->update(['last_checked_at' => $lgd['checked_on']]);
            $electoral = json_decode(file_get_contents(database_path('fixtures/pilibhit-lgd-electoral.json')), true, 512, JSON_THROW_ON_ERROR);
            $electoralRelease = $release('lgd-pilibhit-electoral', 'Local Government Directory, Ministry of Panchayati Raj', $electoral['url'], $electoral, $electoral['sha256']);
            DB::table('source_releases')->where('id', $electoralRelease)->update(['retrieved_at' => $electoral['checked_on']]);
            DB::table('data_sources')->where('key', 'lgd-pilibhit-electoral')->update(['last_checked_at' => $electoral['checked_on']]);
            $sir = $release('sir-pilibhit', 'District Election Office, Pilibhit', $sample['sir']['source_url'], $sample['sir'], $sample['sir']['sha256']);
            $geo = $release('soi-up', 'Survey of India', 'https://surveyofindia.gov.in/pages/village-boundary-data-base-of-entire-india', $crosswalk);
            $reps = $release('pilibhit-representatives', 'District Administration, Pilibhit', 'https://pilibhit.nic.in/constituencies-2/');
            $staff = $release('pilibhit-officers', 'District Administration, Pilibhit', 'https://pilibhit.nic.in/about-district/whos-who/');
            $places = [];
            foreach ([['district-bareilly', 'Bareilly', 'district'], ['ac-baheri', 'Baheri', 'ac'], ['district-pilibhit', 'Pilibhit', 'district'], ['pc-pilibhit', 'Pilibhit', 'pc'], ['ac-pilibhit', 'Pilibhit', 'ac'], ['ac-barkhera', 'Barkhera', 'ac'], ['ac-puranpur', 'Puranpur', 'ac'], ['ac-bisalpur', 'Bisalpur', 'ac']] as [$slug,$name,$type]) {
                $places[$slug] = $this->row('places', ['slug' => $slug], ['name' => $name, 'type' => $type, 'country_code' => 'IN']);
            }
            $this->row('place_identifiers', ['namespace' => 'census:district:IN:UP', 'code' => '151', 'version' => '2011'], ['place_id' => $places['district-pilibhit'], 'source_release_id' => $census]);
            foreach (['pc-pilibhit' => '26', 'ac-pilibhit' => '127', 'ac-barkhera' => '128', 'ac-puranpur' => '129', 'ac-bisalpur' => '130'] as $slug => $code) {
                $this->row('place_identifiers', ['namespace' => 'electoral:IN:UP:'.explode('-', $slug)[0], 'code' => $code, 'version' => 'directory-2026-09-15'], ['place_id' => $places[$slug], 'source_release_id' => $reps]);
                if (str_starts_with($slug, 'ac-')) {
                    $this->row('place_relationships', ['from_place_id' => $places[$slug], 'to_place_id' => $places['district-pilibhit'], 'type' => 'district_directory_lists', 'source_release_id' => $reps], []);
                }
            }
            $mapping = $release('eci-up-delimitation', 'Election Commission of India', 'https://www.eci.gov.in/eci-backend/public/api/download?url=LMAhAK6sOPBp%2FNFF0iRfXbEB1EVSLT41NNLRjYNJJP1KivrUxbfqkDatmHy12e%2FzVx8fLfn2ReU7TfrqYobgIhDdiug%2BcfNuyNw18VbQyX4ytYYT1mZfnv91aSRW9dyU35NzLWOLO770pAfWh4kL0t2DL6R6ejFIaHyi4sj1oGpt6ybm3x8d7llFBKReV90%2BdOFtn933icz0MOeiesxvsQ%3D%3D', ['source_locator' => 'UP delimitation order, page 43, PC 26']);
            foreach (['ac-baheri', 'ac-pilibhit', 'ac-barkhera', 'ac-puranpur', 'ac-bisalpur'] as $ac) {
                $this->row('place_relationships', ['from_place_id' => $places[$ac], 'to_place_id' => $places['pc-pilibhit'], 'type' => 'assembly_segment_of', 'source_release_id' => $mapping], []);
            }
            $districtMapping = $release('eci-up-district-2023', 'Election Commission of India', 'https://egazette.gov.in/WriteReadData/2023/249449.pdf', ['source_locator' => 'Page 15, Bareilly district, AC 118']);
            $this->row('place_relationships', ['from_place_id' => $places['ac-baheri'], 'to_place_id' => $places['district-bareilly'], 'type' => 'district_directory_lists', 'source_release_id' => $districtMapping], []);
            $this->row('place_identifiers', ['namespace' => 'electoral:IN:UP:ac', 'code' => '118', 'version' => 'delimitation'], ['place_id' => $places['ac-baheri'], 'source_release_id' => $mapping]);
            foreach (['population' => ['Population', 'people', $sample['census']['district_population']], 'households' => ['Households', 'households', 362573], 'literate' => ['Literate population', 'people', 1061095]] as $key => [$label,$unit,$value]) {
                $indicator = $this->row('indicators', ['key' => $key], ['label' => $label, 'unit' => $unit, 'evidence_class' => 'official', 'definition' => 'Census 2011 Primary Census Abstract; district total']);
                $this->row('observations', ['place_id' => $places['district-pilibhit'], 'indicator_id' => $indicator, 'source_release_id' => $census, 'period' => '2011'], ['value' => $value, 'source_locator' => 'EB-0920!'.(['population' => 'K2', 'households' => 'J2', 'literate' => 'W2'][$key])]);
            }
            $parliament = $this->row('organizations', ['key' => 'lok-sabha'], ['name' => 'Lok Sabha', 'official_url' => 'https://sansad.in/ls']);
            $assembly = $this->row('organizations', ['key' => 'up-assembly'], ['name' => 'Uttar Pradesh Legislative Assembly']);
            $district = $this->row('organizations', ['key' => 'pilibhit-administration'], ['name' => 'Pilibhit District Administration', 'official_url' => 'https://pilibhit.nic.in/']);
            $entries = [
                ['mp-pilibhit', 'Member of Parliament', 'elected', $parliament, 'pc-pilibhit', 'jitin-prasada', 'Jitin Prasada', $reps],
                ['mla-pilibhit', 'Member of Legislative Assembly', 'elected', $assembly, 'ac-pilibhit', 'sanjay-singh-gangwar', 'Sanjay Singh Gangwar', $reps],
                ['mla-barkhera', 'Member of Legislative Assembly', 'elected', $assembly, 'ac-barkhera', 'pravaktanand', 'Pravaktanand', $reps],
                ['mla-puranpur', 'Member of Legislative Assembly', 'elected', $assembly, 'ac-puranpur', 'babu-ram-paswan', 'Babu Ram Paswan', $reps],
                ['mla-bisalpur', 'Member of Legislative Assembly', 'elected', $assembly, 'ac-bisalpur', 'vivek-verma', 'Vivek Verma', $reps],
                ['dm-pilibhit', 'District Magistrate', 'administrative', $district, 'district-pilibhit', 'gyanendra-singh', 'Gyanendra Singh (IAS)', $staff],
                ['cdo-pilibhit', 'Chief Development Officer', 'administrative', $district, 'district-pilibhit', 'satish-prasad-mishra', 'Satish Prasad Mishra', $staff],
            ];
            foreach ($entries as [$key,$title,$kind,$org,$place,$personKey,$name,$evidence]) {
                $office = $this->row('offices', ['key' => $key], ['title' => $title, 'kind' => $kind, 'organization_id' => $org]);
                $person = $this->row('people', ['key' => $personKey], ['display_name' => $name]);
                $this->row('office_jurisdictions', ['office_id' => $office, 'place_id' => $places[$place], 'source_release_id' => $evidence], []);
                // Seed only this historical evidence snapshot; do not reactivate it after a future replacement.
                DB::table('office_assignments')->insertOrIgnore(['office_id' => $office, 'person_id' => $person, 'source_release_id' => $evidence, 'verified_at' => $checked, 'status' => 'last_verified']);
                if ($personKey === 'jitin-prasada') {
                    foreach (['Official profile' => 'https://sansad.in/ls/members/biography/4065?from=members', 'Wikipedia' => 'https://en.wikipedia.org/wiki/Jitin_Prasada'] as $kind => $url) {
                        $this->row('public_profiles', ['person_id' => $person, 'kind' => $kind], ['url' => $url, 'verified_at' => $checked, 'identity_evidence' => 'Name and Pilibhit Lok Sabha constituency corroborated against official directory']);
                    }
                }
            }
        });
    }
}
