<?php

namespace App\Http\Controllers;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistrictDashboardController extends Controller
{
    public function index(string $state, string $district): View|RedirectResponse
    {
        if ($state === '09' && in_array($district, ['136', '151'], true)) {
            return redirect()->route($district === '136' ? 'rampur-district-pilot' : 'pilibhit-district-pilot');
        }
        $data = app(DistrictDashboardData::class)->published($district, $state);
        abort_unless($data, 404, 'No published Census district records are available for this code.');
        $censusSeries = $data['history'];
        $constituencyLinks = $state === '29' && in_array($district, ['555', '556', '557', '558', '559', '560', '561', '562', '563', '564', '565', '566'], true) ? app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
            '555' => 'Belgaum', '556' => 'Bagalkot', '557' => 'Bijapur', '558' => 'Bidar', '559' => 'Raichur', '560' => 'Koppal', '561' => 'Gadag', '562' => 'Dharwad', '563' => 'Uttara Kannada', '564' => 'Haveri', '565' => 'Bellary', '566' => 'Chitradurga'
        }, 'Karnataka') : ($state === '09' ? app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
            '175' => 'Prayagraj', '176' => 'Barabanki', '149' => 'Badaun', '142' => 'Bulandshahar', '177' => 'Ayodhya', '144' => 'Hathras', '163' => 'Kanpur Dehat', '202' => 'Kasganj', '189' => 'Kushi Nagar', '187' => 'Maharajganj', default => $data['name']
        }) : ['available' => false]);
        $mapFiles = ['146' => 'maps/agra-villages.geojson', '143' => 'maps/aligarh-villages.geojson', '175' => 'maps/allahabad-villages.geojson', '178' => 'maps/ambedkar-nagar-villages.geojson', '162' => 'maps/auraiya-villages.geojson', '191' => 'maps/azamgarh-villages.geojson', '139' => 'maps/baghpat-villages.geojson', '180' => 'maps/bahraich-villages.geojson', '193' => 'maps/ballia-villages.geojson', '182' => 'maps/balrampur-villages.geojson', '170' => 'maps/banda-villages.geojson', '176' => 'maps/barabanki-villages.geojson', '150' => 'maps/bareilly-villages.geojson', '185' => 'maps/basti-villages.geojson', '134' => 'maps/bijnor-villages.geojson', '149' => 'maps/budaun-villages.geojson', '142' => 'maps/bulandshahr-villages.geojson', '196' => 'maps/chandauli-villages.geojson', '171' => 'maps/chitrakoot-villages.geojson', '190' => 'maps/deoria-villages.geojson', '201' => 'maps/etah-villages.geojson', '161' => 'maps/etawah-villages.geojson', '177' => 'maps/faizabad-villages.geojson', '159' => 'maps/farrukhabad-villages.geojson', '172' => 'maps/fatehpur-villages.geojson', '147' => 'maps/firozabad-villages.geojson', '140' => 'maps/ghaziabad-villages.geojson', '195' => 'maps/ghazipur-villages.geojson', '183' => 'maps/gonda-villages.geojson', '188' => 'maps/gorakhpur-villages.geojson', '168' => 'maps/hamirpur-villages.geojson', '155' => 'maps/hardoi-villages.geojson', '144' => 'maps/hathras-villages.geojson', '165' => 'maps/jalaun-villages.geojson', '194' => 'maps/jaunpur-villages.geojson', '166' => 'maps/jhansi-villages.geojson', '160' => 'maps/kannauj-villages.geojson', '163' => 'maps/kanpur-dehat-villages.geojson', '164' => 'maps/kanpur-nagar-villages.geojson', '202' => 'maps/kasganj-villages.geojson', '174' => 'maps/kaushambi-villages.geojson', '153' => 'maps/kheri-villages.geojson', '189' => 'maps/kushinagar-villages.geojson', '167' => 'maps/lalitpur-villages.geojson', '157' => 'maps/lucknow-villages.geojson', '187' => 'maps/maharajganj-villages.geojson', '169' => 'maps/mahoba-villages.geojson', '148' => 'maps/mainpuri-villages.geojson', '145' => 'maps/mathura-villages.geojson', '192' => 'maps/mau-villages.geojson', '138' => 'maps/meerut-villages.geojson', '199' => 'maps/mirzapur-villages.geojson', '135' => 'maps/moradabad-villages.geojson', '133' => 'maps/muzaffarnagar-villages.geojson', '173' => 'maps/pratapgarh-villages.geojson', '158' => 'maps/rae-bareli-villages.geojson', '132' => 'maps/saharanpur-villages.geojson', '186' => 'maps/sant-kabir-nagar-villages.geojson'];
        $mapFile = $state === '29' && in_array($district, ['555', '556', '557', '558', '559', '560', '561', '562', '563', '564', '565', '566'], true) ? match ($district) {
            '555' => 'maps/belagavi-villages.geojson', '556' => 'maps/bagalkot-villages.geojson', '557' => 'maps/bijapur-villages.geojson', '558' => 'maps/bidar-villages.geojson', '559' => 'maps/raichur-villages.geojson', '560' => 'maps/koppal-villages.geojson', '561' => 'maps/gadag-villages.geojson', '562' => 'maps/dharwad-villages.geojson', '563' => 'maps/uttara-kannada-villages.geojson', '564' => 'maps/haveri-villages.geojson', '565' => 'maps/bellary-villages.geojson', '566' => 'maps/chitradurga-villages.geojson'
        } : ($state === '09' ? ($mapFiles[$district] ?? null) : null);
        if ($state === '27' && in_array($district, ['497', '498', '499', '500', '501', '502', '503', '504', '505', '506', '507', '508', '509', '510', '511', '512', '513', '514', '515', '516', '517', '518', '519', '520', '521', '522', '523', '524', '525', '526', '527', '528', '529', '530', '531'], true)) {
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
                '497' => 'Nandurbar', '498' => 'Dhule', '499' => 'Jalgaon', '500' => 'Buldhana', '501' => 'Akola', '502' => 'Washim', '503' => 'Amravati', '504' => 'Wardha', '505' => 'Nagpur', '506' => 'Bhandara', '507' => 'Gondiya', '508' => 'Gadchiroli', '509' => 'Chandrapur', '510' => 'Yavatmal', '511' => 'Nanded', '512' => 'Hingoli', '513' => 'Parbhani', '514' => 'Jalna', '515' => 'Aurangabad', '516' => 'Nashik', '517' => 'Thane', '518' => 'Mumbai Suburban', '519' => 'Mumbai City', '520' => 'Raigad', '521' => 'Pune', '522' => 'Ahmednagar', '523' => 'Beed', '524' => 'Latur', '525' => 'Osmanabad', '526' => 'Solapur', '527' => 'Satara', '528' => 'Ratnagiri', '529' => 'Sindhudurg', '530' => 'Kolhapur', '531' => 'Sangli'
            }, 'Maharashtra');
            $mapFile = match ($district) {
                '497' => 'maps/nandurbar-villages.geojson', '498' => 'maps/dhule-villages.geojson', '499' => 'maps/jalgaon-villages.geojson', '500' => 'maps/buldhana-villages.geojson', '501' => 'maps/akola-villages.geojson', '502' => 'maps/washim-villages.geojson', '503' => 'maps/amravati-villages.geojson', '504' => 'maps/wardha-villages.geojson', '505' => 'maps/nagpur-villages.geojson', '506' => 'maps/bhandara-villages.geojson', '507' => 'maps/gondiya-villages.geojson', '508' => 'maps/gadchiroli-villages.geojson', '509' => 'maps/chandrapur-villages.geojson', '510' => 'maps/yavatmal-villages.geojson', '511' => 'maps/nanded-villages.geojson', '512' => 'maps/hingoli-villages.geojson', '513' => 'maps/parbhani-villages.geojson', '514' => 'maps/jalna-villages.geojson', '515' => 'maps/aurangabad-villages.geojson', '516' => 'maps/nashik-villages.geojson', '517' => 'maps/thane-villages.geojson', '518' => 'maps/mumbai-suburban-villages.geojson', '519' => 'maps/mumbai-villages.geojson', '520' => 'maps/raigad-villages.geojson', '521' => 'maps/pune-villages.geojson', '522' => 'maps/ahmednagar-villages.geojson', '523' => 'maps/beed-villages.geojson', '524' => 'maps/latur-villages.geojson', '525' => 'maps/osmanabad-villages.geojson', '526' => 'maps/solapur-villages.geojson', '527' => 'maps/satara-villages.geojson', '528' => 'maps/ratnagiri-villages.geojson', '529' => 'maps/sindhudurg-villages.geojson', '530' => 'maps/kolhapur-villages.geojson', '531' => 'maps/sangli-villages.geojson'
            };
        }
        if ($state === '24' && $district === '468') {
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kachchh', 'Gujarat');
            $mapFile = 'maps/kachchh-villages.geojson';
        }
        $sourceMap = $mapFile && is_file(public_path($mapFile)) ? $mapFile : null;

        return view('district-dashboard', compact('data', 'censusSeries', 'constituencyLinks', 'sourceMap'));
    }

    public function directory(): View
    {
        $districts = collect();
        if (Schema::hasTable('census_editions') && Schema::hasTable('census_catalogue_rows') && Schema::hasTable('census_publications')) {
            $districts = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->join('census_publications as p', function ($join): void {
                    $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
                })
                ->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'DISTRICT')->where('c.residence', 'Total')
                ->whereNotLike('e.source_key', 'census-a02-%')
                ->select('c.state_code', 'c.district_code', 'c.name')->orderBy('c.name')->get()->unique(fn (object $row): string => $row->state_code.':'.$row->district_code)->values();
        }
        if ($districts->isEmpty()) {
            $districts = collect([(object) ['state_code' => '09', 'district_code' => '136', 'name' => 'Rampur'], (object) ['state_code' => '09', 'district_code' => '151', 'name' => 'Pilibhit']]);
        }

        return view('district-dashboard-directory', compact('districts'));
    }
}
