<?php
namespace App\Http\Controllers;
use Illuminate\Contracts\View\View;
use App\Services\DistrictConstituencyLinks;
class ModernDistrictSourceMapController extends Controller
{
    public function index(string $slug): View
    {
        $districts = [
            'paschim-medinipur' => ['Paschim Medinipur', 5714, 'https://jhargram.gov.in/about-district/'],
            'alipurduar' => ['Alipurduar', 435, 'https://alipurduar.gov.in/about-district/'],
            'kalimpong' => ['Kalimpong', 193, 'https://kalimpong.gov.in/election/election-info/'],
            'darjiling' => ['Darjiling', 660, 'https://kalimpong.gov.in/election/election-info/'],
            'jalpaiguri' => ['Jalpaiguri', 476, 'https://alipurduar.gov.in/about-district/'],
            'jhargram' => ['Jhargram', 2994, 'https://jhargram.gov.in/about-district/'],
            'purba-bardhaman' => ['Purba Bardhaman', 2191, 'https://purbabardhamanpolice.wb.gov.in/HistoryofPurbaBardhamanDistrictPolice'],
            'paschim-bardhaman' => ['Paschim Bardhaman', 406, 'https://paschimbardhaman.gov.in/history/'],
        ];
        abort_unless(isset($districts[$slug]), 404);
        [$name, $shapeCount, $identityUrl] = $districts[$slug];
        $data = ['name' => $name];
        $sourceMap = 'maps/west-bengal-'.$slug.'-villages.geojson';
        abort_unless(is_file(public_path($sourceMap)), 404);
        $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict($name, 'West Bengal');
        return view('modern-district-source-map', compact('data', 'sourceMap', 'shapeCount', 'identityUrl', 'constituencyLinks', 'slug'));
    }
}
