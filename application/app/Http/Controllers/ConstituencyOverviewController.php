<?php

namespace App\Http\Controllers;

use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConstituencyOverviewController extends Controller
{
    public function index(Request $request, HistoricalElectionArchive $history, ElectionArchive $archives, HistoricalElectionReview $reviews): View
    {
        $input = $request->validate(['kind' => 'required|in:pc,ac', 'state' => 'required|string|max:100', 'name' => 'required|string|max:160', 'edition' => 'nullable|regex:/^[a-f0-9]{24}$/']);
        $kind = $input['kind'];
        $state = $input['state'];
        $name = $input['name'];
        $entries = DB::table('historical_constituency_index')->where('kind', $kind)->where('state_label', $state)->whereRaw('LOWER(constituency_name) = ?', [mb_strtolower($name)])->orderByDesc('year')->orderBy('edition_id')->get();
        abort_if($entries->isEmpty(), 404);
        $rows = $entries->map(function ($entry) use ($history, $archives, $reviews) {
            $record = null;
            $source = null;
            try {
                [$data] = $history->load($entry->edition_id, $archives);
                $original = collect($data['records'])->firstWhere('code', $entry->record_code);
                if ($original) {
                    $record = $reviews->apply($entry->edition_id, $original, $data['source_sha256']);
                    $source = $data['source_url'];
                }
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [404, 409, 503])) {
                    throw $error;
                }
            }

            return ['entry' => $entry, 'record' => $record, 'source' => $source];
        });
        $chosen = isset($input['edition']) ? $rows->first(fn ($row) => $row['entry']->edition_id === $input['edition']) : null;
        abort_if(isset($input['edition']) && ! $chosen, 404);
        $latest = $rows->first();
        $chosen ??= $latest;

        $related = collect();
        // UP identifiers establish state scope; a shared name alone is not a geographic link.
        $placeIds = $state === 'Uttar Pradesh' ? DB::table('places as p')->join('place_identifiers as i', 'i.place_id', '=', 'p.id')->join('source_releases as s', 's.id', '=', 'i.source_release_id')
            ->where('p.type', $kind)->whereRaw('LOWER(p.name) = ?', [mb_strtolower($name)])->where('i.namespace', 'electoral:IN:UP:'.$kind)->where('s.status', 'accepted')->distinct()->pluck('p.id') : collect();
        if ($placeIds->count() === 1) {
            $placeId = $placeIds->first();
            $links = DB::table('place_relationships as r')->join('source_releases as s', 's.id', '=', 'r.source_release_id')->where('s.status', 'accepted')->whereIn('r.type', ['assembly_segment_of', 'district_directory_lists'])
                ->where(fn ($q) => $q->whereNull('r.valid_from')->orWhere('r.valid_from', '<=', today()->toDateString()))
                ->where(fn ($q) => $q->whereNull('r.valid_to')->orWhere('r.valid_to', '>', today()->toDateString()))->select('r.*')->get();
            $direct = $links->filter(fn ($r) => $r->from_place_id === $placeId || $r->to_place_id === $placeId);
            $ids = $direct->map(fn ($r) => $r->from_place_id === $placeId ? $r->to_place_id : $r->from_place_id);
            if ($kind === 'pc') {
                $segments = $direct->where('type', 'assembly_segment_of')->pluck('from_place_id');
                $ids = $ids->merge($links->where('type', 'district_directory_lists')->whereIn('from_place_id', $segments)->pluck('to_place_id'));
            }
            $related = DB::table('places')->whereIn('id', $ids->unique())->whereIn('type', ['pc', 'ac', 'district'])->orderBy('type')->orderBy('name')->get();
        }

        if ($related->isEmpty() && $state === 'Uttar Pradesh') {
            $geography = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
            $normalize = fn ($value) => preg_replace('/[^a-z]/', '', strtolower(preg_replace('/\s*\((?:SC|ST)\)$/i', '', trim($value))));
            $pcs = collect($geography['pcs']);
            $acs = collect($geography['district_rows']);
            $matches = ($kind === 'pc' ? $pcs : $acs)->filter(fn ($row) => $normalize($row['name']) === $normalize($name));
            if ($matches->count() === 1) {
                $match = $matches->first();
                $linkedAcs = $kind === 'pc' ? $acs->whereIn('code', $match['ac_codes']) : collect([$match]);
                $linkedSeats = $kind === 'pc' ? $linkedAcs->map(fn ($row) => ['name' => $row['name'], 'type' => 'ac']) : $pcs->filter(fn ($row) => in_array($match['code'], $row['ac_codes']))->map(fn ($row) => ['name' => $row['name'], 'type' => 'pc']);
                foreach ($linkedSeats as $seat) {
                    $candidates = DB::table('historical_constituency_index')->where('state_label', $state)->where('kind', $seat['type'])->select('constituency_name')->distinct()->get()->filter(fn ($row) => $normalize($row->constituency_name) === $normalize($seat['name']))->pluck('constituency_name')->unique(fn ($n) => mb_strtolower($n));
                    if ($candidates->count() === 1) {
                        $related->push((object) ['name' => $seat['name'], 'type' => $seat['type'], 'url' => route('constituency.overview', ['kind' => $seat['type'], 'state' => $state, 'name' => $candidates->first()]), 'reference' => false]);
                    }
                }
                foreach ($linkedAcs->pluck('district')->unique() as $district) {
                    $profile = DB::table('places')->where('type', 'district')->where('country_code', 'IN')->whereRaw('LOWER(name) = ?', [mb_strtolower($district)])->first();
                    $related->push((object) ['name' => $district, 'type' => 'district', 'url' => $profile ? route('places.show', ['type' => 'district', 'slug' => substr($profile->slug, 9)]) : $geography['district_url'], 'reference' => ! $profile]);
                }
            }
        }

        return view('constituency-overview', compact('kind', 'state', 'name', 'rows', 'chosen', 'latest', 'related'));
    }
}
