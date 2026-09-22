<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ElectionGeographySummary
{
    /** @var array<string, string> */
    private const CURRENT_STATES_AND_UTS = [
        'andaman-and-nicobar-islands' => 'Andaman & Nicobar Islands',
        'andhra-pradesh' => 'Andhra Pradesh',
        'arunachal-pradesh' => 'Arunachal Pradesh',
        'assam' => 'Assam',
        'bihar' => 'Bihar',
        'chandigarh' => 'Chandigarh',
        'chhattisgarh' => 'Chhattisgarh',
        'dadra-and-nagar-haveli-and-daman-and-diu' => 'Dadra & Nagar Haveli and Daman & Diu',
        'delhi' => 'Delhi',
        'goa' => 'Goa',
        'gujarat' => 'Gujarat',
        'haryana' => 'Haryana',
        'himachal-pradesh' => 'Himachal Pradesh',
        'jammu-and-kashmir' => 'Jammu & Kashmir',
        'jharkhand' => 'Jharkhand',
        'karnataka' => 'Karnataka',
        'kerala' => 'Kerala',
        'ladakh' => 'Ladakh',
        'lakshadweep' => 'Lakshadweep',
        'madhya-pradesh' => 'Madhya Pradesh',
        'maharashtra' => 'Maharashtra',
        'manipur' => 'Manipur',
        'meghalaya' => 'Meghalaya',
        'mizoram' => 'Mizoram',
        'nagaland' => 'Nagaland',
        'odisha' => 'Odisha',
        'puducherry' => 'Puducherry',
        'punjab' => 'Punjab',
        'rajasthan' => 'Rajasthan',
        'sikkim' => 'Sikkim',
        'tamil-nadu' => 'Tamil Nadu',
        'telangana' => 'Telangana',
        'tripura' => 'Tripura',
        'uttar-pradesh' => 'Uttar Pradesh',
        'uttarakhand' => 'Uttarakhand',
        'west-bengal' => 'West Bengal',
    ];

    /**
     * @return Collection<int, array{slug: string, name: string, kind: string, archive_editions: int, archive_years: Collection<int, int>, places: int}>
     */
    public function states(): Collection
    {
        $archive = collect(json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR)['entries'])
            ->groupBy('state');

        return collect(self::CURRENT_STATES_AND_UTS)->map(function (string $name, string $slug) use ($archive): array {
            $entries = $archive->get($name, collect());

            return [
                'slug' => $slug,
                'name' => $name,
                'kind' => in_array($slug, ['andaman-and-nicobar-islands', 'chandigarh', 'dadra-and-nagar-haveli-and-daman-and-diu', 'delhi', 'jammu-and-kashmir', 'ladakh', 'lakshadweep', 'puducherry'], true) ? 'Union Territory' : 'State',
                'archive_editions' => $entries->count(),
                'archive_years' => $entries->pluck('year')->unique()->sortDesc()->values(),
                'places' => $slug === 'uttar-pradesh' ? DB::table('places')->whereIn('type', ['district', 'pc', 'ac'])->count() : 0,
            ];
        })->values();
    }

    /** @return array{places: int, districts: int, pcs: int, acs: int, contests: int, years: Collection<int, int>} */
    public function importedSummary(): array
    {
        $coverage = DB::table('places')->whereIn('type', ['district', 'pc', 'ac'])->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $contests = DB::table('election_contests as e')->join('source_releases as r', 'r.id', '=', 'e.source_release_id')
            ->where('e.active', true)->where('r.status', 'accepted');

        return [
            'places' => (int) $coverage->sum(),
            'districts' => (int) ($coverage['district'] ?? 0),
            'pcs' => (int) ($coverage['pc'] ?? 0),
            'acs' => (int) ($coverage['ac'] ?? 0),
            'contests' => (clone $contests)->count(),
            'years' => (clone $contests)->distinct()->orderBy('e.year')->pluck('e.year'),
        ];
    }

    /** @return array{slug: string, name: string, kind: string, archive_editions: int, archive_years: Collection<int, int>, places: int} */
    public function state(string $slug): array
    {
        $state = $this->states()->firstWhere('slug', Str::slug($slug));
        abort_unless($state, 404);

        return $state;
    }
}
