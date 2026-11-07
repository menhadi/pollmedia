<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\GramPanchayatDirectory;
use App\Services\MyPlaceDirectory;
use App\Services\MyPlaceFacts;
use App\Services\MyPlacePanchayat;
use App\Services\PanchayatSummaryPage;
use App\Services\PlaceTopics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MyPlacePanchayatController extends Controller
{
    public function area(Request $request, GramPanchayatDirectory $directory, string $scope, int $state, int $district, ?int $subdistrict = null): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:100000']);
        $q = trim($input['q'] ?? '');
        $page = (int) ($input['page'] ?? 1);
        $listing = $directory->listing($scope, $state, $district, $subdistrict, $q, $page);
        $areaName = $scope === 'lgd' ? $directory->areaName($state, $district, $subdistrict) : null;
        if ($scope === 'census') {
            $names = DB::table('census_catalogue_rows as c')
                ->join('census_editions as e', 'e.id', '=', 'c.edition_id')->where('e.status', 'published')->where('e.year', 2011)
                ->where('c.state_code', str_pad((string) $state, 2, '0', STR_PAD_LEFT))
                ->where('c.district_code', str_pad((string) $district, 3, '0', STR_PAD_LEFT));
            $names = $subdistrict === null ? $names->where('c.level', 'DISTRICT') : $names
                ->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])
                ->where('c.geography->Subdistt', str_pad((string) $subdistrict, 5, '0', STR_PAD_LEFT));
            $areaName = $names->value('c.name');
        }
        $subdistricts = $scope === 'lgd' && ! $subdistrict ? $directory->districtSubdistricts($state, $district) : [];

        return view('gram-panchayat-area', compact('scope', 'state', 'district', 'subdistrict', 'listing', 'q', 'page', 'areaName', 'subdistricts'));
    }

    public function profile(Request $request, GramPanchayatDirectory $directory, ElectoralVillageMap $map, int $state, int $gp): View
    {
        $enabled = PanchayatSummaryPage::enabled($state, $gp);
        abort_if($state === 9 && ! $enabled, 404);
        $profile = $directory->profile($state, $gp);
        $chosen = $request->query('village');
        abort_if($chosen !== null && (! is_string($chosen) || ! ctype_digit($chosen)), 404);
        $member = $chosen === null ? $profile['villages'][0] : collect($profile['villages'])->firstWhere('village', $chosen);
        abort_unless($member, 404);
        if ($enabled && PlaceTopics::selected('gp') === 'overview' && $request->query('view') !== 'details' && $chosen === null) {
            return app(PanchayatSummaryPage::class)->show($profile);
        }
        $village = null;
        if (PlaceTopics::selected('gp') === 'village-data') {
            $lookup = $map->database()->prepare('SELECT 1 FROM ancestry WHERE lgd=? AND state=? AND district=? AND subdistrict=?');
            $lookup->execute(array_map('strval', [$member['village'], $state, $member['district'], $member['subdistrict']]));
            $village = $lookup->fetchColumn() ? $map->village((string) $member['village'], PlaceTopics::selected('member') === 'overview') : null;
            if (PlaceTopics::selected('member') === 'people' && $village && empty($village['census']) && empty($village['urban_boundary'])) {
                $facts = app(MyPlaceFacts::class)->village(['code' => (string) $member['village'], 'census2011' => $village['census2011'] ?? null], (string) $state);
                if ($facts['census_status'] === 'available') {
                    $village['census'] = ['population' => $facts['population'], 'households' => $facts['households'], 'literacy_rate' => $facts['literacy']];
                    $village['census_source'] = $facts['census_source'];
                }
            }
        }
        $own = PlaceTopics::selected('gp') === 'records'
            ? app(MyPlacePanchayat::class)->profileRecords($profile['record'], array_column($profile['villages'], 'district'))
            : ['reports' => [], 'payment_totals' => [], 'allocations' => []];

        return view('gram-panchayat-profile', $profile + ['reports' => $own['reports']] + compact('own', 'member', 'village', 'enabled'));
    }

    public function index(Request $request, MyPlaceDirectory $directory, MyPlacePanchayat $panchayats): View
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'subdistrict' => 'nullable|regex:/^[0-9]{1,8}$/', 'panchayat' => 'nullable|regex:/^[0-9]{1,10}$/', 'lang' => 'nullable|in:en,hi']);
        $context = $directory->context($input['state'], $input['district'], $input['village'] ?? null);
        $context['gp_subdistrict'] = $input['subdistrict'] ?? null;
        $language = ($input['lang'] ?? 'en') === 'hi' ? 'hi' : 'en';
        $panchayat = $panchayats->data($context, $input['panchayat'] ?? null);
        abort_if(! empty($input['panchayat']) && ! $panchayat['selected'], 404);

        return view('my-place-gram-panchayat', compact('panchayat', 'context', 'language') + ['t' => fn ($en, $hi) => $language === 'hi' ? $hi : $en]);
    }
}
