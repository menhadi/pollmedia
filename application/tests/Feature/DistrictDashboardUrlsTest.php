<?php

namespace Tests\Feature;

use App\Services\DistrictDashboardUrls;
use Tests\TestCase;

class DistrictDashboardUrlsTest extends TestCase
{
    public function test_readable_paths_round_trip_to_original_identifiers(): void
    {
        $urls = new DistrictDashboardUrls;
        $paths = [];
        foreach ($urls->all() as $row) {
            $parts = explode('/', $row['path']);
            $this->assertSame($row, $urls->resolve($parts[3], $parts[4]));
            $this->assertSame($row, $urls->identity($row['state'], $row['district']));
            $this->assertNotContains($row['path'], $paths);
            $paths[] = $row['path'];
        }
        $this->assertSame('/india/districts/uttar-pradesh/pilibhit', $urls->identity('09', '151')['path']);
    }

    public function test_state_directory_only_links_districts_with_a_named_profile(): void
    {
        $rows = collect([
            (object) ['id' => 1, 'district_code' => '151', 'name' => 'Pilibhit', 'values' => '{"TOT_P":120}'],
            (object) ['id' => 2, 'district_code' => '999', 'name' => 'Unassigned district', 'values' => '{"TOT_P":80}'],
        ]);
        $url = url('/india/districts/uttar-pradesh/pilibhit');
        $html = view('state-district-glance', [
            'districtOptions' => $rows, 'year' => 2011, 'residence' => 'Total',
            'districtProfiles' => collect(['151' => ['url' => $url, 'source_name' => 'Pilibhit']]),
            'topic' => 'overview', 'areaValues' => [], 'residencePopulation' => [],
        ])->render();
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);
        $this->assertSame(2, $xpath->query('//tbody/tr')->length);
        $this->assertSame(1, $xpath->query('//tbody//a')->length);
        $this->assertSame($url, $xpath->query('//tbody//a')->item(0)->getAttribute('href'));
        $this->assertSame('Unassigned District', trim($xpath->query('//tbody/tr[2]/th')->item(0)->textContent));
    }

    public function test_unknown_or_cross_state_names_never_guess_an_identity(): void
    {
        $urls = new DistrictDashboardUrls;
        $this->assertNull($urls->resolve('bihar', 'pilibhit'));
        $this->assertNull($urls->resolve('uttar-pradesh', '../pilibhit'));
        $this->assertNull($urls->identity('99', '999'));
    }
}
