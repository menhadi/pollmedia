<?php
namespace App\Http\Controllers;
use App\Services\DistrictDashboardData;
use App\Services\DistrictConstituencyLinks;
use Illuminate\Contracts\View\View;
class WestBengalDistrictDashboardController extends Controller
{
    public function index(string $district): View
    {
        $data=app(DistrictDashboardData::class)->published($district,'19');
        abort_unless($data,404);
        $maps=[
            '333' => 'maps/west-bengal-murshidabad-villages.geojson',
            '336' => 'maps/west-bengal-nadia-villages.geojson',
            '337' => 'maps/west-bengal-north-twenty-four-parganas-villages.geojson',
            '338' => 'maps/west-bengal-hugli-villages.geojson',
            '340' => 'maps/west-bengal-puruliya-villages.geojson',
            '339' => 'maps/west-bengal-bankura-villages.geojson',
            '329' => 'maps/west-bengal-koch-bihar-villages.geojson',
            '345' => 'maps/west-bengal-purba-medinipur-villages.geojson',
            '330' => 'maps/west-bengal-uttar-dinajpur-villages.geojson',
            '331' => 'maps/west-bengal-dakshin-dinajpur-villages.geojson',
            '332' => 'maps/west-bengal-maldah-villages.geojson',
            '334' => 'maps/west-bengal-birbhum-villages.geojson',
        ];
        $file=$maps[$district]??null;
        $sourceMap=$file&&is_file(public_path($file))?$file:null;
        $censusSeries=$data['history'];
        $constituencyLinks=app(DistrictConstituencyLinks::class)->forDistrict($data['name'],'West Bengal');
        return view('district-dashboard',compact('data','sourceMap','censusSeries','constituencyLinks'));
    }
}
