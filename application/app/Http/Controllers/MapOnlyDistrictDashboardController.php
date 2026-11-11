<?php

namespace App\Http\Controllers;

use App\Services\CommunityAreaIssues;
use App\Services\DistrictConstituencyLinks;
use App\Services\ElectoralVillageMap;
use App\Services\IndiaVillageMap;
use App\Services\PlaceLabels;
use App\Services\PlaceTopics;
use Illuminate\Contracts\View\View;

class MapOnlyDistrictDashboardController extends Controller
{
    /**
     * Districts created after Census 2011 have no district census record. The
     * page reuses the same layout and shows only what is available: the
     * polygon map, community issues, authorities and constituency links.
     */
    public function show(array $district): View
    {
        $options = ['map', 'issues', 'authorities', 'elections'];
        $topic = PlaceTopics::selected('district');
        if (! in_array($topic, $options, true)) {
            $topic = 'map';
        }

        $data = [
            'name' => $district['name'],
            'state_name' => $district['state_name'],
            'census_district_code' => null,
            'subdistricts' => [],
            'year' => null,
        ];

        $issueArea = app(CommunityAreaIssues::class)->context('district', $district['name'], (string) $district['state']);
        $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict($district['name'], $district['state_name']);

        $normalise = fn ($value): string => preg_replace('/[^a-z0-9]+/', '', strtolower(str_replace('&', 'and', (string) $value)));
        $sameState = fn ($left, $right): bool => $normalise($left) === $normalise($right)
            || (str_contains($normalise($left), 'dadra') && str_contains($normalise($right), 'dadra'));

        $layer = collect(app(IndiaVillageMap::class)->catalogue()['layers'])->first(
            fn (array $row): bool => $sameState($row['state'], $district['state_name']) && $normalise($row['district']) === $normalise($district['name'])
        );

        $labels = [];
        if ($layer) {
            $map = app(ElectoralVillageMap::class);
            $labels = app(PlaceLabels::class)->forLayer($layer, $map->database());
        }
        $counts = ['VILLAGE' => 0, 'TOWN' => 0, 'FOREST' => 0, 'OTHER' => 0];
        foreach ($labels as $label) {
            $counts[$label['level'] ?? 'OTHER'] = ($counts[$label['level'] ?? 'OTHER'] ?? 0) + 1;
        }

        $satelliteLayers = json_decode(file_get_contents(database_path('fixtures/district-summary-satellite.json')), true, flags: JSON_THROW_ON_ERROR);
        $satellite = $layer ? ($satelliteLayers[$layer['file']] ?? null) : null;

        return view('district-map-only', compact('district', 'data', 'topic', 'options', 'issueArea', 'constituencyLinks', 'layer', 'labels', 'counts', 'satellite'));
    }
}
