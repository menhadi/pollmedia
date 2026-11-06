<?php

namespace App\Http\Controllers;

use App\Services\CensusPlaceNavigation;
use App\Services\CommunityData;
use App\Services\CommunityElectionCorrections;
use App\Services\CommunityMapCorrections;
use App\Services\DistrictDashboardUrls;
use App\Services\ElectionGeographySummary;
use App\Services\ElectionPlaceIdentity;
use App\Services\ElectoralVillageMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CommunityListingController extends Controller
{
    public function documents(int $place): array
    {
        $listing = DB::table('places')->find($place) ?? abort(404);
        $documents = [['table' => 'places', 'id' => $place, 'field' => 'name', 'label' => 'Place name', 'value' => $listing->name, 'expected' => hash('sha256', json_encode($listing)), 'source' => null]];
        $observations = DB::table('observations as o')->join('indicators as i', 'i.id', '=', 'o.indicator_id')->join('source_releases as s', 's.id', '=', 'o.source_release_id')->join('data_sources as d', 'd.id', '=', 's.data_source_id')->where('o.place_id', $place)->where('s.status', 'accepted')->where('i.key', 'not like', '%jjm%')->where('d.key', 'not like', '%jjm%')->select('o.id', 'i.label', 'i.unit', 'o.period', 'o.value', 's.url')->get();
        foreach ($observations as $observation) {
            $original = DB::table('observations')->find($observation->id);
            $documents[] = ['table' => 'observations', 'id' => $original->id, 'field' => 'value', 'label' => $observation->label.' · '.$observation->period.' · '.$observation->unit, 'value' => $original->value, 'expected' => hash('sha256', json_encode($original)), 'source' => $observation->url];
        }

        return [$listing, $documents];
    }

    public function show(Request $request, int $place): View|RedirectResponse
    {
        [$listing,$documents] = $this->documents($place);
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('community.listing', $place, false)]));
        }
        $census = app(CensusPlaceNavigation::class)->record($listing);
        $changes = DB::table('community_data_proposals')->where(function ($query) use ($listing, $documents): void {
            $query->where(fn ($q) => $q->where('target_table', 'places')->where('target_id', $listing->id))->orWhere(fn ($q) => $q->where('target_table', 'observations')->whereIn('target_id', collect($documents)->where('table', 'observations')->pluck('id')));
        })->where('status', 'approved')->orderByDesc('reviewed_at')->get();

        return view('community-listing', compact('listing', 'documents', 'census', 'changes'));
    }

    public function propose(Request $request, int $place): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('community.listing', $place, false)]));
        }
        abort_unless($request->user()->email_verified_at, 403);
        $input = $request->validate(['table' => 'required|in:places,observations', 'id' => 'required|integer|min:1', 'expected' => 'required|string|size:64', 'value' => 'nullable|string|max:1000', 'reason' => 'required|string|min:10|max:2000', 'reference_url' => 'nullable|url:http,https|max:2000']);
        [$listing,$documents] = $this->documents($place);
        $document = collect($documents)->first(fn (array $row): bool => $row['table'] === $input['table'] && $row['id'] == (int) $input['id']) ?? abort(404);
        abort_unless(hash_equals($document['expected'], $input['expected']), 409, 'This value changed. Reload before suggesting a correction.');
        $value = $input['value'] ?? null;
        if ($input['table'] === 'places') {
            abort_unless(is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255, 422, 'Enter a place name.');
            $value = trim($value);
        } else {
            abort_unless($value === null || (is_numeric($value) && is_finite((float) $value) && abs((float) $value) <= 9007199254740991), 422, 'Enter a finite number or leave unavailable.');
        }
        abort_if((string) $document['value'] === (string) $value, 422, 'The value is unchanged.');
        abort_if(DB::table('community_data_proposals')->where('target_table', $document['table'])->where('target_id', $document['id'])->where('user_id', $request->user()->id)->where('status', 'pending')->exists(), 409, 'You already have a pending correction for this value.');
        DB::table('community_data_proposals')->insert(['user_id' => $request->user()->id, 'target_table' => $document['table'], 'target_id' => $document['id'], 'listing_name' => $listing->name, 'field' => $document['field'], 'before_value' => json_encode($document['value']), 'proposed_value' => json_encode($value), 'expected' => $document['expected'], 'reason' => $input['reason'], 'reference_url' => $input['reference_url'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return redirect(CommunityData::publicEditReturn($request->input('return_to')) ?? route('community.listing', $place))->with('status', 'Correction submitted. Track the administrator’s decision in My contributions. Published data stays unchanged until approval.');
    }

    public function scope(Request $request): View|RedirectResponse
    {
        $input = $request->validate(['state' => 'required|string|max:100', 'district' => 'nullable|regex:/^[0-9]{1,3}$/', 'year' => 'nullable|integer|in:2001,2011', 'level' => 'nullable|in:STATE,DISTRICT,VILLAGE', 'village' => 'nullable|regex:/^[0-9]{6,8}$/', 'residence' => 'nullable|in:Total,Rural,Urban']);
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => $request->getRequestUri()]));
        }
        $state = $input['state'];
        $stateName = null;
        if (! ctype_digit($state)) {
            $known = app(ElectionGeographySummary::class)->states()->firstWhere('slug', $state);
            abort_unless($known, 404);
            $stateName = $known['name'];
            $paths = app(DistrictDashboardUrls::class)->all();
            $codes = collect($paths)->filter(fn (array $row): bool => str_contains($row['path'], '/india/districts/'.$state.'/'))->pluck('state')->unique()->values();
            if ($codes->count() === 1) {
                $state = $codes->first();
            } else {
                $state = '__unlinked__';
            }
        }
        $query = app(CommunityData::class)->publishedRows()->where('r.state_code', str_pad($state, 2, '0', STR_PAD_LEFT));
        if (! empty($input['year'])) {
            $query->where('e.year', $input['year']);
        }
        if (! empty($input['district'])) {
            $query->whereIn('r.district_code', array_unique([$input['district'], str_pad($input['district'], 3, '0', STR_PAD_LEFT)]));
        }
        $query->where('r.level', $input['level'] ?? (! empty($input['village']) ? 'VILLAGE' : (! empty($input['district']) ? 'DISTRICT' : 'STATE')));
        if (! empty($input['village'])) {
            $code = $input['village'];
            $query->where(fn ($q) => $q->where('r.geography->Town/Village', $code)->orWhere('r.geography->TOWN_VILL', $code));
        }
        $query->where('r.residence', $input['residence'] ?? (($input['level'] ?? '') === 'VILLAGE' || ! empty($input['village']) ? 'Rural' : 'Total'));
        $rows = $query->select('r.*', 'e.year', 'e.name as edition_name')->orderByDesc('e.year')->orderBy('r.residence')->orderByDesc('e.id')->paginate(15)->withQueryString();
        $data = app(CommunityData::class);

        $electionEntries = $stateName && ($input['level'] ?? 'STATE') === 'STATE' ? DB::table('historical_constituency_index')->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($stateName))])->orderByDesc('year')->orderBy('constituency_name')->paginate(20, ['*'], 'elections_page')->withQueryString() : null;

        return view('community-scope', compact('rows', 'data', 'electionEntries'));
    }

    public function election(Request $request, int $entry): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => $request->getRequestUri()]));
        }
        $document = app(CommunityElectionCorrections::class)->document($entry);
        $changes = DB::table('community_data_proposals')->where('target_table', 'historical_constituency_index')->where('target_id', $entry)->where('status', 'approved')->orderByDesc('reviewed_at')->get();

        return view('community-election', compact('document', 'changes'));
    }

    public function proposeElection(Request $request, int $entry): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('community.election', $entry, false)]));
        }
        abort_unless($request->user()->email_verified_at, 403);
        $input = $request->validate(['field' => 'required|string|max:100', 'value' => 'nullable|string|max:1000', 'value_type' => 'required|in:text,number,missing', 'expected' => 'required|string|size:64', 'reason' => 'required|string|min:10|max:2000', 'reference_url' => 'nullable|url:http,https|max:2000']);
        $document = app(CommunityElectionCorrections::class)->document($entry);
        abort_unless($document['fields'][$input['field']]['editable'] ?? false, 422);
        abort_unless(hash_equals($document['expected'], $input['expected']), 409);
        $value = $input['value_type'] === 'missing' ? null : ($input['value'] ?? '');
        if ($input['value_type'] === 'number') {
            abort_unless(is_numeric($value) && is_finite((float) $value) && abs((float) $value) <= 9007199254740991, 422);
            $value += 0;
        }
        $before = $document['fields'][$input['field']]['value'];
        abort_if($before === $value, 422, 'The value is unchanged.');
        abort_if(DB::table('community_data_proposals')->where('target_table', 'historical_constituency_index')->where('target_id', $entry)->where('field', $input['field'])->where('user_id', $request->user()->id)->where('status', 'pending')->exists(), 409, 'You already have a pending suggestion for this field.');
        DB::table('community_data_proposals')->insert(['user_id' => $request->user()->id, 'target_table' => 'historical_constituency_index', 'target_id' => $entry, 'listing_name' => $document['entry']->constituency_name.' · '.$document['entry']->year, 'field' => $input['field'], 'before_value' => json_encode($before), 'proposed_value' => json_encode($value), 'expected' => $input['expected'], 'reason' => $input['reason'], 'reference_url' => $input['reference_url'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return redirect(CommunityData::publicEditReturn($request->input('return_to')) ?? route('community.election', $entry))->with('status', 'Suggestion submitted for administrator review. Track its decision in My contributions.');
    }

    public function mapData(Request $request): JsonResponse
    {
        $input = $request->validate(['file' => 'required|string|max:180']);

        return response()->json(app(CommunityMapCorrections::class)->read($input['file']))->header('Cache-Control', 'no-store');
    }

    public function village(Request $request, string $code): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => $request->getRequestUri()]));
        }
        $village = app(ElectoralVillageMap::class)->village($code);
        $urls = [$village['census_url'] ?? null, $village['census_directory']['edit_url'] ?? null];
        foreach ($village['census_history'] ?? [] as $history) {
            $urls[] = $history['pca']['edit_url'] ?? null;
            $urls[] = $history['url'] ?? null;
        }
        $ids = [];
        foreach (array_filter($urls) as $url) {
            if (preg_match('~/(?:data/census/records|india/census/places)/([0-9]+)(?:[?#]|$)~', $url, $match)) {
                $ids[] = (int) $match[1];
            }
        }
        $data = app(CommunityData::class);
        $rows = $data->publishedRows()->whereIn('r.id', array_unique($ids))->select('r.*', 'e.year', 'e.name as edition_name')->orderByDesc('e.year')->paginate(15);

        return view('community-scope', compact('rows', 'data'));
    }
}
