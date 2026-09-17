<?php

namespace App\Http\Controllers;

use App\Services\CensusHistory;
use App\Services\ElectionResults;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use XMLWriter;

class SitemapController extends Controller
{
    public function index(PlaceController $places, ElectionResults $results): Response
    {
        $urls = [route('home'), route('states.show', ['state' => 'uttar-pradesh'])];
        if (app(CensusHistory::class)->edition1981() !== null) {
            $urls[] = route('census.1981');
        }
        if (app(CensusHistory::class)->population() !== null) {
            $urls[] = route('census.history');
        }
        foreach (DB::table('places')->whereIn('type', ['district', 'pc', 'ac'])->get() as $place) {
            $parameters = ['type' => $place->type, 'slug' => Str::after($place->slug, $place->type.'-')];
            $urls[] = route('places.show', $parameters);
            foreach ($results->forPlace($place->id)->skip(1) as $election) {
                $urls[] = route('places.show', $parameters + ['year' => $election->year]);
            }
        }
        foreach (['2011', '2001'] as $year) {
            $edition = $year === '2001' ? ['year' => $year] : [];
            $urls[] = route('villages.index', $edition);
            foreach ($places->payload('census-pilibhit-villages-'.$year)['villages'] as $village) {
                $urls[] = route('villages.show', ['code' => $village['code'], 'slug' => Str::slug($village['name'])] + $edition);
            }
        }
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach (array_unique($urls) as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
