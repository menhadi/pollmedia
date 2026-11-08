<?php

namespace App\Http\Controllers;

use App\Services\CensusHistory;
use App\Services\ConstituencyUrls;
use App\Services\ElectionArchive;
use App\Services\ElectionPlaceIdentity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use XMLWriter;

class SitemapController extends Controller
{
    public function index(PlaceController $places): Response|BinaryFileResponse
    {
        if (is_file(public_path('sitemaps/index.xml'))) {
            return response()->file(public_path('sitemaps/index.xml'), ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
        }
        $xml = Cache::flexible('public-sitemap-xml-v2', [3600, 86400], fn (): string => $this->buildXml($places), ['seconds' => 120]);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function buildXml(PlaceController $places): string
    {
        $urls = [route('home'), route('states.show', ['state' => 'uttar-pradesh'])];
        foreach (DB::table('seo_metadata')->where('path', 'like', '/india/census/states/%')->pluck('path') as $statePath) {
            $urls[] = url($statePath);
        }
        if (app(CensusHistory::class)->edition1981() !== null) {
            $urls[] = route('census.1981');
        }
        if (app(CensusHistory::class)->population() !== null) {
            $urls[] = route('census.history');
        }
        $electionYears = DB::table('election_contests as e')->join('source_releases as s', 's.id', '=', 'e.source_release_id')
            ->where('e.active', true)->where('s.status', 'accepted')->orderByDesc('e.year')
            ->select('e.place_id', 'e.year')->get()->groupBy('place_id');
        foreach (DB::table('places')->whereIn('type', ['district', 'pc', 'ac'])->get() as $place) {
            $parameters = ['type' => $place->type, 'slug' => Str::after($place->slug, $place->type.'-')];
            $urls[] = route('places.show', $parameters);
            foreach ($electionYears->get($place->id, collect())->skip(1) as $election) {
                $urls[] = route('places.show', $parameters + ['year' => $election->year]);
            }
        }
        $seenSeats = [];
        foreach (ElectionArchive::publicIndex()->orderByDesc('year')->select('kind', 'state_label', 'constituency_name', 'edition_id', 'record_code')->get() as $seat) {
            $state = ElectionPlaceIdentity::state($seat->state_label);
            $identity = $seat->kind.'|'.$state.'|'.mb_strtolower(trim($seat->constituency_name));
            if (isset($seenSeats[$identity])) {
                continue;
            }
            $seenSeats[$identity] = true;
            $urls[] = ConstituencyUrls::url(['kind' => $seat->kind, 'state' => $state, 'name' => $seat->constituency_name, 'edition' => $seat->edition_id, 'code' => $seat->record_code]);
        }
        foreach (['2011', '2001'] as $year) {
            $edition = $year === '2001' ? ['year' => $year] : [];
            $urls[] = route('villages.index', $edition);
            foreach ($places->payload('census-pilibhit-villages-'.$year)['villages'] as $village) {
                $urls[] = route('villages.named', ['state' => 'uttar-pradesh', 'district' => 'pilibhit', 'code' => $village['code'], 'slug' => Str::slug($village['name'])] + $edition);
            }
        }
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach (array_unique($urls) as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', preg_replace('~^https?://[^/]+~', 'https://pollmedia.org', $url));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
