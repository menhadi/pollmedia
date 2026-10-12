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
        $constituencyLinks = $state === '29' && in_array($district, ['555', '556', '557', '558', '559', '560', '561', '562', '563', '564', '565', '566', '567', '568', '569', '570', '571', '572', '573', '574', '575', '576', '577', '578', '579', '580', '581', '582', '583', '584'], true) ? app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
            '555' => 'Belgaum', '556' => 'Bagalkot', '557' => 'Bijapur', '558' => 'Bidar', '559' => 'Raichur', '560' => 'Koppal', '561' => 'Gadag', '562' => 'Dharwad', '563' => 'Uttara Kannada', '564' => 'Haveri', '565' => 'Bellary', '566' => 'Chitradurga', '567' => 'Davanagere', '568' => 'Shimoga', '569' => 'Udupi', '570' => 'Chikmagalur', '571' => 'Tumkur', '572' => 'Bangalore', '573' => 'Mandya', '574' => 'Hassan', '575' => 'Dakshina Kannada', '576' => 'Kodagu', '577' => 'Mysore', '578' => 'Chamarajanagar', '579' => 'Gulbarga', '580' => 'Yadgir', '581' => 'Kolar', '582' => 'Chikkaballapura', '583' => 'Bangalore Rural', '584' => 'Ramanagara'
        }, 'Karnataka') : ($state === '09' ? app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
            '175' => 'Prayagraj', '176' => 'Barabanki', '149' => 'Badaun', '142' => 'Bulandshahar', '177' => 'Ayodhya', '144' => 'Hathras', '163' => 'Kanpur Dehat', '202' => 'Kasganj', '189' => 'Kushi Nagar', '187' => 'Maharajganj', '137' => 'Amroha', default => $data['name']
        }) : ['available' => false]);
        $mapFiles = ['146' => 'maps/agra-villages.geojson', '143' => 'maps/aligarh-villages.geojson', '175' => 'maps/allahabad-villages.geojson', '178' => 'maps/ambedkar-nagar-villages.geojson', '162' => 'maps/auraiya-villages.geojson', '191' => 'maps/azamgarh-villages.geojson', '139' => 'maps/baghpat-villages.geojson', '180' => 'maps/bahraich-villages.geojson', '193' => 'maps/ballia-villages.geojson', '182' => 'maps/balrampur-villages.geojson', '170' => 'maps/banda-villages.geojson', '176' => 'maps/barabanki-villages.geojson', '150' => 'maps/bareilly-villages.geojson', '185' => 'maps/basti-villages.geojson', '134' => 'maps/bijnor-villages.geojson', '149' => 'maps/budaun-villages.geojson', '142' => 'maps/bulandshahr-villages.geojson', '196' => 'maps/chandauli-villages.geojson', '171' => 'maps/chitrakoot-villages.geojson', '190' => 'maps/deoria-villages.geojson', '201' => 'maps/etah-villages.geojson', '161' => 'maps/etawah-villages.geojson', '177' => 'maps/faizabad-villages.geojson', '159' => 'maps/farrukhabad-villages.geojson', '172' => 'maps/fatehpur-villages.geojson', '147' => 'maps/firozabad-villages.geojson', '140' => 'maps/ghaziabad-villages.geojson', '195' => 'maps/ghazipur-villages.geojson', '183' => 'maps/gonda-villages.geojson', '188' => 'maps/gorakhpur-villages.geojson', '168' => 'maps/hamirpur-villages.geojson', '155' => 'maps/hardoi-villages.geojson', '144' => 'maps/hathras-villages.geojson', '165' => 'maps/jalaun-villages.geojson', '194' => 'maps/jaunpur-villages.geojson', '166' => 'maps/jhansi-villages.geojson', '160' => 'maps/kannauj-villages.geojson', '163' => 'maps/kanpur-dehat-villages.geojson', '164' => 'maps/kanpur-nagar-villages.geojson', '202' => 'maps/kasganj-villages.geojson', '174' => 'maps/kaushambi-villages.geojson', '153' => 'maps/kheri-villages.geojson', '189' => 'maps/kushinagar-villages.geojson', '167' => 'maps/lalitpur-villages.geojson', '157' => 'maps/lucknow-villages.geojson', '187' => 'maps/maharajganj-villages.geojson', '169' => 'maps/mahoba-villages.geojson', '148' => 'maps/mainpuri-villages.geojson', '145' => 'maps/mathura-villages.geojson', '192' => 'maps/mau-villages.geojson', '138' => 'maps/meerut-villages.geojson', '199' => 'maps/mirzapur-villages.geojson', '135' => 'maps/moradabad-villages.geojson', '133' => 'maps/muzaffarnagar-villages.geojson', '173' => 'maps/pratapgarh-villages.geojson', '158' => 'maps/rae-bareli-villages.geojson', '132' => 'maps/saharanpur-villages.geojson', '186' => 'maps/sant-kabir-nagar-villages.geojson', '198' => 'maps/bhadohi-villages.geojson', '152' => 'maps/shahjahanpur-villages.geojson', '181' => 'maps/shrawasti-villages.geojson', '184' => 'maps/siddharthnagar-villages.geojson', '154' => 'maps/sitapur-villages.geojson', '200' => 'maps/sonbhadra-villages.geojson', '179' => 'maps/sultanpur-villages.geojson', '156' => 'maps/unnao-villages.geojson', '197' => 'maps/varanasi-villages.geojson', '141' => 'maps/gautam-buddha-nagar-villages.geojson', '137' => 'maps/amroha-villages.geojson'];
        $mapFile = $state === '29' && in_array($district, ['555', '556', '557', '558', '559', '560', '561', '562', '563', '564', '565', '566', '567', '568', '569', '570', '571', '572', '573', '574', '575', '576', '577', '578', '579', '580', '581', '582', '583', '584'], true) ? match ($district) {
            '555' => 'maps/belagavi-villages.geojson', '556' => 'maps/bagalkot-villages.geojson', '557' => 'maps/bijapur-villages.geojson', '558' => 'maps/bidar-villages.geojson', '559' => 'maps/raichur-villages.geojson', '560' => 'maps/koppal-villages.geojson', '561' => 'maps/gadag-villages.geojson', '562' => 'maps/dharwad-villages.geojson', '563' => 'maps/uttara-kannada-villages.geojson', '564' => 'maps/haveri-villages.geojson', '565' => 'maps/bellary-villages.geojson', '566' => 'maps/chitradurga-villages.geojson', '567' => 'maps/davanagere-villages.geojson', '568' => 'maps/shimoga-villages.geojson', '569' => 'maps/udupi-villages.geojson', '570' => 'maps/chikmagalur-villages.geojson', '571' => 'maps/tumkur-villages.geojson', '572' => 'maps/bangalore-villages.geojson', '573' => 'maps/mandya-villages.geojson', '574' => 'maps/hassan-villages.geojson', '575' => 'maps/dakshina-kannada-villages.geojson', '576' => 'maps/kodagu-villages.geojson', '577' => 'maps/mysore-villages.geojson', '578' => 'maps/chamarajanagar-villages.geojson', '579' => 'maps/gulbarga-villages.geojson', '580' => 'maps/yadgir-villages.geojson', '581' => 'maps/kolar-villages.geojson', '582' => 'maps/chikkaballapura-villages.geojson', '583' => 'maps/bangalore-rural-villages.geojson', '584' => 'maps/ramanagara-villages.geojson'
        } : ($state === '09' ? ($mapFiles[$district] ?? null) : null);
        if ($state === '27' && in_array($district, ['497', '498', '499', '500', '501', '502', '503', '504', '505', '506', '507', '508', '509', '510', '511', '512', '513', '514', '515', '516', '517', '518', '519', '520', '521', '522', '523', '524', '525', '526', '527', '528', '529', '530', '531'], true)) {
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict(match ($district) {
                '497' => 'Nandurbar', '498' => 'Dhule', '499' => 'Jalgaon', '500' => 'Buldhana', '501' => 'Akola', '502' => 'Washim', '503' => 'Amravati', '504' => 'Wardha', '505' => 'Nagpur', '506' => 'Bhandara', '507' => 'Gondiya', '508' => 'Gadchiroli', '509' => 'Chandrapur', '510' => 'Yavatmal', '511' => 'Nanded', '512' => 'Hingoli', '513' => 'Parbhani', '514' => 'Jalna', '515' => 'Aurangabad', '516' => 'Nashik', '517' => 'Thane', '518' => 'Mumbai Suburban', '519' => 'Mumbai City', '520' => 'Raigad', '521' => 'Pune', '522' => 'Ahmednagar', '523' => 'Beed', '524' => 'Latur', '525' => 'Osmanabad', '526' => 'Solapur', '527' => 'Satara', '528' => 'Ratnagiri', '529' => 'Sindhudurg', '530' => 'Kolhapur', '531' => 'Sangli'
            }, 'Maharashtra');
            $mapFile = match ($district) {
                '497' => 'maps/nandurbar-villages.geojson', '498' => 'maps/dhule-villages.geojson', '499' => 'maps/jalgaon-villages.geojson', '500' => 'maps/buldhana-villages.geojson', '501' => 'maps/akola-villages.geojson', '502' => 'maps/washim-villages.geojson', '503' => 'maps/amravati-villages.geojson', '504' => 'maps/wardha-villages.geojson', '505' => 'maps/nagpur-villages.geojson', '506' => 'maps/bhandara-villages.geojson', '507' => 'maps/gondiya-villages.geojson', '508' => 'maps/gadchiroli-villages.geojson', '509' => 'maps/chandrapur-villages.geojson', '510' => 'maps/yavatmal-villages.geojson', '511' => 'maps/nanded-villages.geojson', '512' => 'maps/hingoli-villages.geojson', '513' => 'maps/parbhani-villages.geojson', '514' => 'maps/jalna-villages.geojson', '515' => 'maps/aurangabad-villages.geojson', '516' => 'maps/nashik-villages.geojson', '517' => 'maps/thane-villages.geojson', '518' => 'maps/mumbai-suburban-villages.geojson', '519' => 'maps/mumbai-villages.geojson', '520' => 'maps/raigad-villages.geojson', '521' => 'maps/pune-villages.geojson', '522' => 'maps/ahmednagar-villages.geojson', '523' => 'maps/beed-villages.geojson', '524' => 'maps/latur-villages.geojson', '525' => 'maps/osmanabad-villages.geojson', '526' => 'maps/solapur-villages.geojson', '527' => 'maps/satara-villages.geojson', '528' => 'maps/ratnagiri-villages.geojson', '529' => 'maps/sindhudurg-villages.geojson', '530' => 'maps/kolhapur-villages.geojson', '531' => 'maps/sangli-villages.geojson'
            };
        }
        if ($state === '10' && in_array($district, ['209', '240', '235', '225', '222', '231', '224', '232', '236', '215', '217', '239', '238', '233', '212', '210', '226', '227', '223', '213', '207', '216', '229', '203', '237', '230', '204', '234', '214', '221', '219', '228', '205', '206', '218', '208', '220', '211'], true)) {
            $mapFile = match ($district) {
                '209' => 'maps/araria-villages.geojson',
                '240' => 'maps/arwal-villages.geojson',
                '235' => 'maps/bihar-aurangabad-villages.geojson',
                '225' => 'maps/bihar-banka-villages.geojson',
                '222' => 'maps/bihar-begusarai-villages.geojson',
                '231' => 'maps/bihar-bhojpur-villages.geojson',
                '224' => 'maps/bihar-bhagalpur-villages.geojson',
                '232' => 'maps/bihar-buxar-villages.geojson',
                '236' => 'maps/bihar-gaya-villages.geojson',
                '215' => 'maps/bihar-darbhanga-villages.geojson',
                '217' => 'maps/bihar-gopalganj-villages.geojson',
                '239' => 'maps/bihar-jehanabad-villages.geojson',
                '238' => 'maps/bihar-jamui-villages.geojson',
                '233' => 'maps/bihar-kaimur-bhabua-villages.geojson',
                '212' => 'maps/bihar-katihar-villages.geojson',
                '210' => 'maps/bihar-kishanganj-villages.geojson',
                '226' => 'maps/bihar-munger-villages.geojson',
                '227' => 'maps/bihar-lakhisarai-villages.geojson',
                '223' => 'maps/bihar-khagaria-villages.geojson',
                '213' => 'maps/bihar-madhepura-villages.geojson',
                '207' => 'maps/bihar-madhubani-villages.geojson',
                '216' => 'maps/bihar-muzaffarpur-villages.geojson',
                '229' => 'maps/bihar-nalanda-villages.geojson',
                '203' => 'maps/bihar-pashchim-champaran-villages.geojson',
                '237' => 'maps/bihar-nawada-villages.geojson',
                '230' => 'maps/bihar-patna-villages.geojson',
                '204' => 'maps/bihar-purba-champaran-villages.geojson',
                '234' => 'maps/bihar-rohtas-villages.geojson',
                '214' => 'maps/bihar-saharsa-villages.geojson',
                '221' => 'maps/bihar-samastipur-villages.geojson',
                '219' => 'maps/bihar-saran-villages.geojson',
                '228' => 'maps/bihar-sheikhpura-villages.geojson',
                '205' => 'maps/bihar-sheohar-villages.geojson',
                '206' => 'maps/bihar-sitamarhi-villages.geojson',
                '218' => 'maps/bihar-siwan-villages.geojson',
                '208' => 'maps/bihar-supaul-villages.geojson',
                '220' => 'maps/bihar-vaishali-villages.geojson',
                '211' => 'maps/bihar-purnia-villages.geojson',
            };
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict($data['name'], 'Bihar');
        }
        if ($state === '33' && $district === '605') {
            $mapFile = 'maps/vellore-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Vellore', 'Tamil Nadu');
        }
        if ($state === '24' && in_array($district, ['468', '469', '470', '471', '472', '473', '474', '475', '476', '477', '478', '479', '480', '481', '482', '483', '484', '485', '486', '487', '488', '489', '490', '491', '492', '493'], true)) {
            $gujaratDistrict = match ($district) {
                '468' => 'Kachchh',
                '469' => 'Banaskantha',
                '470' => 'Patan',
                '471' => 'Mahesana',
                '472' => 'Sabarkantha',
                '473' => 'Gandhinagar',
                '474' => 'Ahmedabad',
                '475' => 'Surendranagar',
                '476' => 'Rajkot',
                '477' => 'Jamnagar',
                '478' => 'Porbandar',
                '479' => 'Junagadh',
                '480' => 'Amreli',
                '481' => 'Bhavnagar',
                '482' => 'Anand',
                '483' => 'Kheda',
                '484' => 'Panchmahals',
                '485' => 'Dahod',
                '486' => 'Vadodara',
                '487' => 'Narmada',
                '488' => 'Bharuch',
                '489' => 'The Dangs',
                '490' => 'Navsari',
                '491' => 'Valsad',
                '492' => 'Surat',
                '493' => 'Tapi',
                '472' => 'Sabarkantha',
                '473' => 'Gandhinagar',
                '474' => 'Ahmedabad',
                '475' => 'Surendranagar',
                '476' => 'Rajkot',
                '477' => 'Jamnagar',
                '478' => 'Porbandar',
                '479' => 'Junagadh',
                '480' => 'Amreli',
                '481' => 'Bhavnagar',
                '482' => 'Anand',
                '483' => 'Kheda',
                '484' => 'Panchmahals',
                '485' => 'Dahod',
                '486' => 'Vadodara',
                '487' => 'Narmada',
                '488' => 'Bharuch',
                '489' => 'The Dangs',
                '490' => 'Navsari',
                '491' => 'Valsad',
                '492' => 'Surat',
                '493' => 'Tapi',
            };
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict($gujaratDistrict, 'Gujarat');
            $mapFile = $district === '489' ? 'maps/dangs-villages.geojson' : 'maps/'.strtolower($gujaratDistrict).'-villages.geojson';
        }
        if ($state === '33' && $district === '614') {
            $mapFile = 'maps/tiruchirappalli-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Tiruchirappalli', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '615') {
            $mapFile = 'maps/perambalur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Perambalur', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '616') {
            $mapFile = 'maps/ariyalur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Ariyalur', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '617') {
            $mapFile = 'maps/cuddalore-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Cuddalore', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '618') {
            $mapFile = 'maps/nagapattinam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Nagapattinam', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '619') {
            $mapFile = 'maps/thiruvarur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Thiruvarur', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '620') {
            $mapFile = 'maps/thanjavur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Thanjavur', 'Tamil Nadu');
        }
        if ($state === '28' && $district === '543') {
            $mapFile = 'maps/vizianagaram-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Vizianagaram', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '544') {
            $mapFile = 'maps/visakhapatnam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Visakhapatnam', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '545') {
            $mapFile = 'maps/east-godavari-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('East Godavari', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '546') {
            $mapFile = 'maps/west-godavari-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('West Godavari', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '547') {
            $mapFile = 'maps/krishna-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Krishna', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '548') {
            $mapFile = 'maps/guntur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Guntur', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '549') {
            $mapFile = 'maps/prakasam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Prakasam', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '550') {
            $mapFile = 'maps/nellore-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Nellore', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '551') {
            $mapFile = 'maps/ysr-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kadapa', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '552') {
            $mapFile = 'maps/kurnool-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kurnool', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '553') {
            $mapFile = 'maps/anantapur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Anantapur', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '554') {
            $mapFile = 'maps/chittoor-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Chittoor', 'Andhra Pradesh');
        }
        if ($state === '28' && $district === '541') {
            $mapFile = 'maps/khammam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Khammam', 'Telangana');
        }
        if ($state === '28' && $district === '540') {
            $mapFile = 'maps/warangal-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Warangal', 'Telangana');
        }
        if ($state === '28' && $district === '539') {
            $mapFile = 'maps/nalgonda-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Nalgonda', 'Telangana');
        }
        if ($state === '28' && $district === '538') {
            $mapFile = 'maps/mahbubnagar-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Mahbubnagar', 'Telangana');
        }
        if ($state === '28' && $district === '537') {
            $mapFile = 'maps/rangareddy-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Rangareddy', 'Telangana');
        }
        if ($state === '28' && $district === '536') {
            $mapFile = 'maps/hyderabad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Hyderabad', 'Telangana');
        }
        if ($state === '28' && $district === '535') {
            $mapFile = 'maps/medak-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Medak', 'Telangana');
        }
        if ($state === '28' && $district === '534') {
            $mapFile = 'maps/karimnagar-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Karimnagar', 'Telangana');
        }
        if ($state === '28' && $district === '533') {
            $mapFile = 'maps/nizamabad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Nizamabad', 'Telangana');
        }
        if ($state === '28' && $district === '532') {
            $mapFile = 'maps/adilabad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Adilabad', 'Telangana');
        }
        if ($state === '22' && $district === '415') {
            $mapFile = 'maps/narayanpur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Narayanpur', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '416') {
            $mapFile = 'maps/dantewada-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Dakshin Bastar (Dantewada)', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '417') {
            $mapFile = 'maps/chhattisgarh-bijapur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Bijapur', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '414') {
            $mapFile = 'maps/bastar-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Bastar', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '413') {
            $mapFile = 'maps/uttar-bastar-kanker-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Uttar Bastar Kanker', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '412') {
            $mapFile = 'maps/dhamtari-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Dhamtari', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '411') {
            $mapFile = 'maps/mahasamund-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Mahasamund', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '410') {
            $mapFile = 'maps/raipur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Raipur', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '409') {
            $mapFile = 'maps/durg-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Durg', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '408') {
            $mapFile = 'maps/rajnandgaon-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Rajnandgaon', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '407') {
            $mapFile = 'maps/kabeerdham-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kabeerdham', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '406') {
            $mapFile = 'maps/bilaspur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Bilaspur', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '405') {
            $mapFile = 'maps/janjgir-champa-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Janjgir-Champa', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '404') {
            $mapFile = 'maps/korba-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Korba', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '403') {
            $mapFile = 'maps/raigarh-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Raigarh', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '402') {
            $mapFile = 'maps/jashpur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Jashpur', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '401') {
            $mapFile = 'maps/surguja-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Surguja', 'Chhattisgarh');
        }
        if ($state === '22' && $district === '400') {
            $mapFile = 'maps/koriya-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Koriya', 'Chhattisgarh');
        }
        if ($state === '28' && $district === '542') {
            $mapFile = 'maps/srikakulam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Srikakulam', 'Andhra Pradesh');
        }
        if ($state === '32' && $district === '601') {
            $mapFile = 'maps/thiruvananthapuram-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Thiruvananthapuram', 'Kerala');
        }
        if ($state === '32' && $district === '600') {
            $mapFile = 'maps/kollam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kollam', 'Kerala');
        }
        if ($state === '32' && $district === '599') {
            $mapFile = 'maps/pathanamthitta-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Pathanamthitta', 'Kerala');
        }
        if ($state === '32' && $district === '598') {
            $mapFile = 'maps/alappuzha-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Alappuzha', 'Kerala');
        }
        if ($state === '32' && $district === '597') {
            $mapFile = 'maps/kottayam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kottayam', 'Kerala');
        }
        if ($state === '32' && $district === '596') {
            $mapFile = 'maps/idukki-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Idukki', 'Kerala');
        }
        if ($state === '32' && $district === '595') {
            $mapFile = 'maps/ernakulam-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Ernakulam', 'Kerala');
        }
        if ($state === '32' && $district === '594') {
            $mapFile = 'maps/thrissur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Thrissur', 'Kerala');
        }
        if ($state === '32' && $district === '593') {
            $mapFile = 'maps/palakkad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Palakkad', 'Kerala');
        }
        if ($state === '32' && $district === '592') {
            $mapFile = 'maps/malappuram-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Malappuram', 'Kerala');
        }
        if ($state === '32' && $district === '591') {
            $mapFile = 'maps/kozhikode-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kozhikode', 'Kerala');
        }
        if ($state === '32' && $district === '590') {
            $mapFile = 'maps/wayanad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Wayanad', 'Kerala');
        }
        if ($state === '32' && $district === '589') {
            $mapFile = 'maps/kannur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kannur', 'Kerala');
        }
        if ($state === '32' && $district === '588') {
            $mapFile = 'maps/kasaragod-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kasaragod', 'Kerala');
        }
        if ($state === '33' && $district === '633') {
            $mapFile = 'maps/tiruppur-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Tiruppur', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '632') {
            $mapFile = 'maps/coimbatore-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Coimbatore', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '631') {
            $mapFile = 'maps/krishnagiri-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Krishnagiri', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '630') {
            $mapFile = 'maps/dharmapuri-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Dharmapuri', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '629') {
            $mapFile = 'maps/kanniyakumari-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Kanniyakumari', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '628') {
            $mapFile = 'maps/tirunelveli-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Tirunelveli', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '627') {
            $mapFile = 'maps/thoothukkudi-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Thoothukkudi', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '626') {
            $mapFile = 'maps/ramanathapuram-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Ramanathapuram', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '625') {
            $mapFile = 'maps/virudhunagar-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Virudhunagar', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '624') {
            $mapFile = 'maps/theni-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Theni', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '623') {
            $mapFile = 'maps/madurai-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Madurai', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '622') {
            $mapFile = 'maps/sivaganga-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Sivaganga', 'Tamil Nadu');
        }
        if ($state === '33' && $district === '621') {
            $mapFile = 'maps/pudukkottai-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Pudukkottai', 'Tamil Nadu');
        }
        if ($state === '19' && $district === '333') {
            $mapFile = 'maps/west-bengal-murshidabad-villages.geojson';
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Murshidabad', 'West Bengal');
        }
        if ($state === '23' && in_array($district, ['418', '419', '420', '421', '422', '423', '424', '425', '426', '427', '428', '429', '430', '431', '432', '433', '434', '435', '436', '437', '438', '439', '440', '441', '442', '443', '444', '445', '446', '447', '448', '449', '450', '451', '452', '453', '454', '455', '456', '457', '458', '459', '460', '461', '462', '463', '464', '465', '466', '467'], true)) {
            $mpDistrict = ['418' => 'Sheopur', '419' => 'Morena', '420' => 'Bhind', '421' => 'Gwalior', '422' => 'Datia', '423' => 'Shivpuri', '424' => 'Tikamgarh', '425' => 'Chhatarpur', '426' => 'Panna', '427' => 'Sagar', '428' => 'Damoh', '429' => 'Satna', '430' => 'Rewa', '431' => 'Umaria', '432' => 'Neemuch', '433' => 'Mandsaur', '434' => 'Ratlam', '435' => 'Ujjain', '436' => 'Shajapur', '437' => 'Dewas', '438' => 'Dhar', '439' => 'Indore', '440' => 'Khargone', '441' => 'Barwani', '442' => 'Rajgarh', '443' => 'Vidisha', '444' => 'Bhopal', '445' => 'Sehore', '446' => 'Raisen', '447' => 'Betul', '448' => 'Harda', '449' => 'Hoshangabad', '450' => 'Katni', '451' => 'Jabalpur', '452' => 'Narsinghpur', '453' => 'Dindori', '454' => 'Mandla', '455' => 'Chhindwara', '456' => 'Seoni', '457' => 'Balaghat', '458' => 'Guna', '459' => 'Ashoknagar', '460' => 'Shahdol', '461' => 'Anuppur', '462' => 'Sidhi', '463' => 'Singrauli', '464' => 'Jhabua', '465' => 'Alirajpur', '466' => 'Khandwa', '467' => 'Burhanpur'][$district];
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict(match ($mpDistrict) {
                'Ashoknagar' => 'Ashok Nagar', default => $mpDistrict
            }, 'Madhya Pradesh');
            $mapFile = 'maps/'.strtolower($mpDistrict).'-villages.geojson';
        }
        if ($state === '08' && in_array($district, ['099', '100', '101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '112', '113', '114', '115', '116', '117', '118', '119', '120', '121', '122', '123', '124', '125', '126'], true)) {
            $rjDistrict = ['099' => 'Ganganagar', '100' => 'Hanumangarh', '101' => 'Bikaner', '102' => 'Churu', '103' => 'Jhunjhunun', '104' => 'Alwar', '105' => 'Bharatpur', '106' => 'Dhaulpur', '107' => 'Karauli', '108' => 'Sawai Madhopur', '109' => 'Dausa', '110' => 'Jaipur', '111' => 'Sikar', '112' => 'Tonk', '113' => 'Ajmer', '114' => 'Nagaur', '115' => 'Pali', '116' => 'Jodhpur', '117' => 'Jaisalmer', '118' => 'Barmer', '119' => 'Jalor', '120' => 'Sirohi', '121' => 'Bundi', '122' => 'Bhilwara', '123' => 'Rajsamand', '124' => 'Dungarpur', '125' => 'Banswara', '126' => 'Udaipur'][$district];
            $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict(['Jhunjhunun' => 'Jhunjhunu', 'Dhaulpur' => 'Dholpur', 'Jalor' => 'Jalore'][$rjDistrict] ?? $rjDistrict, 'Rajasthan');
            $mapFile = 'maps/'.str_replace(' ', '-', strtolower($rjDistrict)).'-villages.geojson';
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
