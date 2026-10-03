<?php

namespace App\Http\Controllers;

use App\Services\ElectionGeographySummary;
use App\Services\ElectionPlaceIdentity;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HomeElectionSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OverviewController extends Controller
{
    public function index(Request $request, ElectionGeographySummary $summary, ?string $state = null): View|JsonResponse
    {
        if ($state === null && $request->has('finder')) {
            $finder = $request->validate(['kind' => 'required|in:pc,ac', 'state' => 'required|string|max:100']);

            return response()->json(['seats' => app(HomeElectionSummary::class)->seats($finder['kind'], $finder['state'])]);
        }
        if ($state === null) {
            $states = $summary->states();
            $electionDashboard = app(HomeElectionSummary::class)->dashboard();

            return view('overview', compact('states', 'electionDashboard'));
        }
        $stateSummary = $state !== null ? $summary->state($state) : null;
        $input = $request->validate(['format' => 'nullable|in:report', 'suggest' => 'nullable|in:pc,ac', 'pc_q' => 'nullable|string|max:100', 'ac_q' => 'nullable|string|max:100', 'pc_scope' => 'nullable|in:current,archive', 'ac_scope' => 'nullable|in:current,archive', 'seat_q' => 'nullable|string|max:100', 'pc_page' => 'nullable|integer|min:1|max:10000', 'ac_page' => 'nullable|integer|min:1|max:10000', 'q' => 'nullable|string|max:100', 'type' => 'nullable|in:pc,ac,district', 'place' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:10000', 'pc_edition' => 'nullable|regex:/^[a-f0-9]{24}$/', 'ac_edition' => 'nullable|regex:/^[a-f0-9]{24}$/', 'election' => 'nullable|in:ac,pc', 'edition' => 'nullable|regex:/^[a-f0-9]{24}$/', 'party' => 'nullable|string|max:100']);
        $query = trim($input['q'] ?? '');
        $type = $input['type'] ?? '';
        $available = DB::table('places')->where(function ($query): void {
            $query->whereIn('slug', ['pc-pilibhit', 'district-pilibhit', 'district-bareilly', 'ac-pilibhit', 'ac-baheri', 'ac-barkhera', 'ac-puranpur', 'ac-bisalpur'])
                ->orWhereIn('id', DB::table('place_identifiers')->where('namespace', 'electoral:IN:UP:ac')->where('version', 'eci-election-2022')->select('place_id'))
                ->orWhereIn('id', DB::table('place_identifiers')->where('namespace', 'electoral:IN:UP:pc')->where('version', 'delimitation-order-34')->select('place_id'))
                ->orWhereIn('id', DB::table('place_relationships as r')->join('source_releases as s', 's.id', '=', 'r.source_release_id')->join('data_sources as d', 'd.id', '=', 's.data_source_id')->where('d.key', 'up-statewide-district-geography')->where('s.status', 'accepted')->select('r.to_place_id'));
        })->when($stateSummary && $stateSummary['slug'] !== 'uttar-pradesh', fn ($query) => $query->whereRaw('1 = 0'))->orderBy('name')->get();
        $coverage = $available->countBy('type');
        $options = $available;
        $selected = $input['place'] ?? '';
        $filtered = $available->filter(fn ($place) => ($type === '' || $place->type === $type) && ($selected === '' || $place->slug === $selected) && ($query === '' || str_contains(mb_strtolower($place->name), mb_strtolower($query))));
        $page = (int) ($input['page'] ?? 1);
        $places = new LengthAwarePaginator($filtered->forPage($page, 24)->values(), $filtered->count(), 24, $page, ['path' => $request->url(), 'query' => $request->query(), 'fragment' => 'politics']);
        $years = DB::table('election_contests as e')->join('source_releases as r', 'r.id', '=', 'e.source_release_id')->where('e.active', true)->where('r.status', 'accepted')->select('e.place_id', 'e.year')->get()->groupBy('place_id');
        $title = $stateSummary['name'] ?? 'India';
        $states = $summary->states();

        if ($stateSummary) {
            $kind = $input['election'] ?? 'pc';
            $electionSections = [];
            foreach (['pc', 'ac'] as $sectionKind) {
                $sectionHistory = app(HistoricalElectionAnalytics::class)->forState($title, $sectionKind);
                $requestedEdition = $input[$sectionKind.'_edition'] ?? ($sectionKind === $kind ? ($input['edition'] ?? null) : null);
                $sectionEdition = $requestedEdition ?? ($sectionHistory[0]['id'] ?? null);
                $sectionElection = collect($sectionHistory)->firstWhere('id', $sectionEdition);
                abort_if($requestedEdition !== null && ! $sectionElection, 404);
                $electionSections[$sectionKind] = ['history' => $sectionHistory, 'edition' => $sectionEdition, 'election' => $sectionElection];
            }
            $history = $electionSections[$kind]['history'];
            $edition = $electionSections[$kind]['edition'];
            $election = $electionSections[$kind]['election'];
            $party = $input['party'] ?? null;
            $partyOptions = collect($history)->flatMap(fn (array $row): array => array_column($row['parties'], 'party'))->unique()->sort()->values();

            if (($input['format'] ?? null) === 'report') {
                abort_if(! $history, 404);

                return view('state-election-report', compact('title', 'state', 'kind', 'history'));
            }

            $seatQuery = trim($input['seat_q'] ?? '');
            $seatDirectories = [];
            $seatScopes = [];
            $seatQueries = [];
            $seatLatestYears = [];
            foreach (['pc', 'ac'] as $seatKind) {
                $seatQueries[$seatKind] = trim($input[$seatKind.'_q'] ?? $seatQuery);
                $seatScopes[$seatKind] = $input[$seatKind.'_scope'] ?? 'current';
                $base = DB::table('historical_constituency_index')->where('kind', $seatKind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($title)]);
                $seatLatestYears[$seatKind] = (clone $base)->max('year');
                $latestEdition = (clone $base)->where('year', $seatLatestYears[$seatKind])->select('edition_id')->groupBy('edition_id')->orderByRaw('COUNT(*) DESC')->orderByDesc('edition_id')->value('edition_id');
                $seatDirectories[$seatKind] = DB::table('historical_constituency_index')
                    ->where('kind', $seatKind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($title)])
                    ->when($seatScopes[$seatKind] === 'current', fn ($builder) => $builder->where('edition_id', $latestEdition))
                    ->when($seatScopes[$seatKind] === 'archive', fn ($builder) => $builder->where('year', '<', $seatLatestYears[$seatKind]))
                    ->when($seatQueries[$seatKind] !== '', fn ($builder) => $builder->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($seatQueries[$seatKind]).'%']))
                    ->selectRaw('LOWER(constituency_name) as name, MIN(year) as first_year, MAX(year) as last_year')
                    ->groupByRaw('LOWER(constituency_name)')->when($seatScopes[$seatKind] === 'current', fn ($builder) => $builder->groupBy('record_code'))->orderBy('name')->paginate(24, ['*'], $seatKind.'_page')->withQueryString()->fragment($seatKind.'-constituencies');
            }

            if ($request->expectsJson() && isset($input['suggest'])) {
                $suggestKind = $input['suggest'];

                return response()->json(['suggestions' => $seatDirectories[$suggestKind]->getCollection()->take(10)->map(fn ($seat) => [
                    'label' => Str::title($seat->name),
                    'type' => ($seatScopes[$suggestKind] === 'archive' ? 'Archive · ' : '').strtoupper($suggestKind),
                    'period' => $seat->first_year.'–'.$seat->last_year.' · '.$title,
                    'url' => route('constituency.overview', ['kind' => $suggestKind, 'state' => $title, 'name' => $seat->name]),
                ])->values()]);
            }

            return view('state-election-dashboard', compact('title', 'state', 'stateSummary', 'states', 'places', 'options', 'query', 'type', 'selected', 'kind', 'history', 'edition', 'election', 'party', 'partyOptions', 'seatQuery', 'seatDirectories', 'seatScopes', 'seatQueries', 'seatLatestYears', 'electionSections'));
        }

        abort(404);
    }
}
