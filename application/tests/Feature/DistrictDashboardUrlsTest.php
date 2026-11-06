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

    public function test_unknown_or_cross_state_names_never_guess_an_identity(): void
    {
        $urls = new DistrictDashboardUrls;
        $this->assertNull($urls->resolve('bihar', 'pilibhit'));
        $this->assertNull($urls->resolve('uttar-pradesh', '../pilibhit'));
        $this->assertNull($urls->identity('99', '999'));
    }
}
