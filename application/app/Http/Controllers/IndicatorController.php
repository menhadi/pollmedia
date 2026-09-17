<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IndicatorController extends Controller
{
    public function index(Request $request): View
    {
        $indiaContext = $request->routeIs('indicators.india');
        if ($indiaContext) {
            $request->merge(['country' => 'IN']);
        }
        $input = $request->validate([
            'country' => 'nullable|regex:/^[A-Z]{2}$/', 'place' => 'nullable|integer|exists:places,id',
            'indicator' => 'nullable|integer|exists:indicators,id', 'period' => 'nullable|string|max:100',
        ]);
        $country = $input['country'] ?? '';
        $place = $input['place'] ?? null;
        $indicator = $input['indicator'] ?? null;
        $period = $input['period'] ?? '';
        $base = DB::table('observations as v')->join('places as p', 'p.id', '=', 'v.place_id')
            ->join('indicators as i', 'i.id', '=', 'v.indicator_id')
            ->join('source_releases as r', 'r.id', '=', 'v.source_release_id')
            ->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('r.status', 'accepted')->where('v.status', 'reported');
        $countries = (clone $base)->select('p.country_code')->distinct()->orderBy('p.country_code')->pluck('p.country_code');
        $base->when($country, fn ($q) => $q->where('p.country_code', $country));
        $places = (clone $base)->select('p.id', 'p.name', 'p.type')->distinct()->orderBy('p.name')->get();
        $base->when($place, fn ($q) => $q->where('p.id', $place));
        $indicators = (clone $base)->select('i.id', 'i.label', 'i.unit')->distinct()->orderBy('i.label')->get();
        $base->when($indicator, fn ($q) => $q->where('i.id', $indicator));
        $periods = (clone $base)->select('v.period')->distinct()->orderByDesc('v.period')->pluck('v.period');
        $measurements = $base->when($period !== '', fn ($q) => $q->where('v.period', $period))
            ->select('v.id', 'v.value', 'v.period', 'v.source_locator', 'p.name', 'p.slug', 'p.type', 'p.country_code',
                'i.label', 'i.unit', 'i.definition', 'i.evidence_class', 'r.id as release_id', 'r.url', 'r.retrieved_at', 's.publisher')
            ->orderBy('p.name')->orderBy('i.label')->orderByDesc('v.period')->orderByDesc('v.id')->paginate(30)->withQueryString();

        return view('indicator-browser', compact('indiaContext', 'country', 'place', 'indicator', 'period', 'countries', 'places', 'indicators', 'periods', 'measurements'));
    }
}
