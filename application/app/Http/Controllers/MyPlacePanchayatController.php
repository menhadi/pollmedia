<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\GramPanchayatDirectory;
use App\Services\GramPanchayatUrls;
use App\Services\MyPlaceDirectory;
use App\Services\MyPlaceFacts;
use App\Services\MyPlacePanchayat;
use App\Services\PanchayatSummaryPage;
use App\Services\PlaceTopics;
use Illuminate\Http\RedirectResponse;
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

        $sourceDistrict = $scope === 'lgd' ? (string) $district : null;
        if ($scope === 'census' && $areaName) {
            $matches = collect(app(MyPlaceDirectory::class)->districts((string) $state))->filter(fn ($row) => mb_strtolower(trim($row['name'])) === mb_strtolower(trim($areaName)))->values();
            $sourceDistrict = $matches->count() === 1 ? (string) $matches[0]['code'] : null;
        }
        $listingStates = array_values(array_unique(array_map('strval', array_column($listing['items'], 'state'))));
        $monthlyState = $scope === 'lgd' ? (string) $state : (count($listingStates) === 1 ? $listingStates[0] : null);
        $additionalReports = $sourceDistrict && ! $subdistrict ? app(MyPlacePanchayat::class)->sourceOnlyPanchayats(['state' => ['code' => (string) $state], 'district' => ['code' => $sourceDistrict], 'village' => null]) : [];
        $additionalReports = array_values(array_filter($additionalReports, fn ($row) => $q === '' || str_contains(mb_strtolower($row['name']), mb_strtolower($q)) || $row['code'] === $q));

        return view('gram-panchayat-area', compact('scope', 'state', 'district', 'subdistrict', 'listing', 'q', 'page', 'areaName', 'subdistricts', 'additionalReports', 'monthlyState'));
    }

    public function profileBySlug(Request $request, GramPanchayatDirectory $directory, ElectoralVillageMap $map, string $state, string $gpName, int $gp): View|RedirectResponse
    {
        $code = app(GramPanchayatUrls::class)->stateCode($state);
        abort_unless($code !== null && $code !== '', 404);

        return $this->profile($request, $directory, $map, (int) $code, $gp);
    }

    public function profile(Request $request, GramPanchayatDirectory $directory, ElectoralVillageMap $map, int $state, int $gp): View|RedirectResponse
    {
        $profile = $directory->profile($state, $gp);
        $canonical = app(GramPanchayatUrls::class)->link($state, $profile['record']['name'] ?? null, $gp);
        if (parse_url($canonical, PHP_URL_PATH) !== $request->getPathInfo()) {
            return redirect()->to($canonical.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }
        $chosen = $request->query('village');
        abort_if($chosen !== null && (! is_string($chosen) || ! ctype_digit($chosen)), 404);
        $topic = PlaceTopics::selected('gp');
        if ($chosen === null && in_array($topic, ['overview', 'villages'], true)) {
            if ($request->query('view') === 'details' || $topic === 'villages') {
                $query = array_filter(['lang' => $request->query('lang') === 'hi' ? 'hi' : null]);

                return redirect()->to($canonical.($query ? '?'.http_build_query($query) : '').($topic === 'villages' ? '#member-villages' : '#panchayat-overview'), 301);
            }

            return app(PanchayatSummaryPage::class)->show($profile);
        }
        $member = $chosen === null ? ($profile['villages'][0] ?? null) : collect($profile['villages'])->firstWhere('village', $chosen);
        abort_unless($member, 404);
        if ($chosen !== null && $topic === 'overview') {
            return redirect()->to($canonical.'?'.http_build_query(array_filter(['topic' => 'village-data', 'village' => $chosen, 'lang' => $request->query('lang') === 'hi' ? 'hi' : null])), 301);
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

        return view('gram-panchayat-profile', $profile + ['reports' => $own['reports']] + compact('own', 'member', 'village'));
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
