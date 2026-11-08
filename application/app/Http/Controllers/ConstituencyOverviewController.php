<?php

namespace App\Http\Controllers;

use App\Services\ConstituencyArchiveHistory;
use App\Services\ConstituencyUrls;
use App\Services\ElectionArchive;
use App\Services\ElectionPlaceIdentity;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use App\Services\PublicOfficeDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConstituencyOverviewController extends Controller
{
    public function named(Request $request, string $kind, string $state, string $name, HistoricalElectionArchive $history, ElectionArchive $archives, HistoricalElectionReview $reviews, HistoricalElectionAnalytics $analytics): View|RedirectResponse
    {
        $request->validate(['source_name' => 'nullable|string|max:160']);
        $identity = ConstituencyUrls::resolve($kind, $state, $name, $request->query('source_name'));
        abort_if($identity === null, 404);
        $request->merge($identity);

        return $this->index($request, $history, $archives, $reviews, $analytics);
    }

    public function index(Request $request, HistoricalElectionArchive $history, ElectionArchive $archives, HistoricalElectionReview $reviews, HistoricalElectionAnalytics $analytics): View|RedirectResponse
    {
        $input = $request->validate(['format' => 'nullable|in:report', 'kind' => 'required|in:pc,ac', 'state' => 'required|string|max:100', 'name' => 'required|string|max:160', 'edition' => 'nullable|regex:/^[a-f0-9]{24}$/', 'code' => 'nullable|integer|min:1|max:999999']);
        $kind = $input['kind'];
        $state = ElectionPlaceIdentity::state($input['state']);
        $name = $input['name'];
        $names = ConstituencyArchiveHistory::generalCategoryNames($name);
        $aliases = ConstituencyArchiveHistory::historyAliasRules($kind, $state, $name);
        $entries = ElectionArchive::publicIndex()->where('kind', $kind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)])->where(function ($query) use ($names, $aliases): void {
            $query->whereIn(DB::raw('LOWER(TRIM(constituency_name))'), $names);
            foreach ($aliases as $rule) {
                $query->orWhere(function ($aliasQuery) use ($rule): void {
                    $aliasQuery->where('record_code', $rule['code'])->whereIn(DB::raw('LOWER(TRIM(constituency_name))'), $rule['names']);
                    if (isset($rule['edition'])) {
                        $aliasQuery->where('edition_id', $rule['edition']);
                    }
                    if (isset($rule['after'])) {
                        $aliasQuery->where('year', '>', $rule['after']);
                    }
                });
            }
        })->orderByDesc('year')->orderBy('edition_id')->get();
        $entries = $entries->concat(app(ConstituencyArchiveHistory::class)->missingEntries($kind, $state, $name, $entries))->sortBy([['year', 'desc'], ['edition_id', 'asc']])->values();
        abort_if($entries->isEmpty(), 404);
        $modernSeatScope = false;
        $modernCode = ['ram nagar' => 267, 'nawabganj' => 121][mb_strtolower(trim($name))] ?? null;
        if ($kind === 'ac' && $state === 'Uttar Pradesh' && $modernCode !== null && ! isset($input['code'])) {
            $modern = $entries->filter(fn ($entry): bool => $entry->year >= 2012 && $entry->record_code === $modernCode);
            if ($modern->isNotEmpty() && ! $modern->groupBy('edition_id')->contains(fn ($group): bool => $group->count() > 1)) {
                $entries = $modern->values();
                $modernSeatScope = true;
            }
        }
        $ambiguousName = $entries->groupBy('edition_id')->contains(fn ($group): bool => $group->count() > 1);
        $exactSeatOnly = false;
        if ($ambiguousName) {
            if (! isset($input['edition'], $input['code'])) {
                return view('constituency-history-choices', compact('kind', 'state', 'name', 'entries'));
            }
            $exact = $entries->first(fn ($entry): bool => $entry->edition_id === $input['edition'] && $entry->record_code === (int) $input['code']);
            abort_if(! $exact, 404);
            $entries = collect([$exact]);
            $exactSeatOnly = true;
        } elseif (isset($input['code'])) {
            abort_if(! isset($input['edition']) || ! $entries->contains(fn ($entry): bool => $entry->edition_id === $input['edition'] && $entry->record_code === (int) $input['code']), 404);
        }
        if (! $request->routeIs('constituency.named') || $request->has('source_name')) {
            return redirect(ConstituencyUrls::url(['kind' => $kind, 'state' => $state, 'name' => $name] + $request->except(['kind', 'state', 'name', 'source_name'])), 301);
        }
        $rows = $entries->map(function ($entry) use ($history, $archives, $reviews, $analytics) {
            $record = null;
            $source = null;
            try {
                [$data] = $history->load($entry->edition_id, $archives, (int) $entry->record_code);
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

            $result = $record ? $analytics->singleSeatResult($record, $entry->edition_id) : null;

            return ['entry' => $entry, 'record' => $record, 'result' => $result,
                'source_candidate' => $record && ! $result ? $analytics->sourceOnlyCandidate($record) : null, 'source' => $source];
        });
        $chosen = isset($input['edition']) ? $rows->first(fn ($row) => $row['entry']->edition_id === $input['edition'] && (! isset($input['code']) || $row['entry']->record_code === (int) $input['code'])) : null;
        abort_if(isset($input['edition']) && ! $chosen, 404);
        $latest = $rows->first();
        $chosen ??= $latest;

        $related = collect();
        // UP identifiers establish state scope; a shared name alone is not a geographic link.
        $placeIds = ! $exactSeatOnly && $state === 'Uttar Pradesh' ? DB::table('places as p')->join('place_identifiers as i', 'i.place_id', '=', 'p.id')->join('source_releases as s', 's.id', '=', 'i.source_release_id')
            ->where('p.type', $kind)->whereRaw('LOWER(p.name) = ?', [mb_strtolower($name)])->where('i.namespace', 'electoral:IN:UP:'.$kind)->where('s.status', 'accepted')->distinct()->pluck('p.id') : collect();
        if ($placeIds->count() === 1) {
            $placeId = $placeIds->first();
            $links = DB::table('place_relationships as r')->join('source_releases as s', 's.id', '=', 'r.source_release_id')->where('s.status', 'accepted')->whereIn('r.type', ['assembly_segment_of', 'district_directory_lists'])
                ->when($kind === 'ac', fn ($query) => $query->where(fn ($query) => $query->where('r.from_place_id', $placeId)->orWhere('r.to_place_id', $placeId)))
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

        if (! $exactSeatOnly && $related->isEmpty() && $state === 'Uttar Pradesh') {
            $geography = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
            $normalize = fn ($value) => preg_replace('/[^a-z]/', '', strtolower(preg_replace('/\s*\((?:SC|ST)\)$/i', '', trim($value))));
            $pcs = collect($geography['pcs']);
            $acs = collect($geography['district_rows']);
            $matches = ($kind === 'pc' ? $pcs : $acs)->filter(fn ($row) => $normalize($row['name']) === $normalize($name));
            if ($matches->count() === 1) {
                $match = $matches->first();
                $linkedAcs = $kind === 'pc' ? $acs->whereIn('code', $match['ac_codes']) : collect([$match]);
                $linkedSeats = $kind === 'pc' ? $linkedAcs->map(fn ($row) => ['name' => $row['name'], 'type' => 'ac']) : $pcs->filter(fn ($row) => in_array($match['code'], $row['ac_codes']))->map(fn ($row) => ['name' => $row['name'], 'type' => 'pc']);
                $candidateNames = [];
                foreach ($linkedSeats as $seat) {
                    $namesForKind = $candidateNames[$seat['type']] ??= ElectionArchive::publicIndex()->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)])->where('kind', $seat['type'])->select('constituency_name')->distinct()->get();
                    $candidates = $namesForKind->filter(fn ($row) => $normalize($row->constituency_name) === $normalize($seat['name']))->pluck('constituency_name')->unique(fn ($n) => mb_strtolower($n));
                    if ($candidates->count() === 1) {
                        $related->push((object) ['name' => $seat['name'], 'type' => $seat['type'], 'url' => ConstituencyUrls::url(['kind' => $seat['type'], 'state' => $state, 'name' => $candidates->first()]), 'reference' => false]);
                    }
                }
                foreach ($linkedAcs->pluck('district')->unique() as $district) {
                    $profile = DB::table('places')->where('type', 'district')->where('country_code', 'IN')->whereRaw('LOWER(name) = ?', [mb_strtolower($district)])->first();
                    $related->push((object) ['name' => $district, 'type' => 'district', 'url' => $profile ? route('places.show', ['type' => 'district', 'slug' => substr($profile->slug, 9)]) : $geography['district_url'], 'reference' => ! $profile]);
                }
            }
        }

        foreach ($related as $place) {
            if (! isset($place->url) && in_array($place->type, ['ac', 'pc'], true) && $state === 'Uttar Pradesh') {
                $entry = ElectionArchive::publicIndex()->where('kind', $place->type)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)])->whereRaw('LOWER(constituency_name) = ?', [mb_strtolower($place->name)])->orderByDesc('year')->first();
                if ($entry) {
                    $place->url = ConstituencyUrls::url(['kind' => $place->type, 'state' => $state, 'name' => $entry->constituency_name]);
                }
            }
        }

        $historyRows = $analytics->distinctConstituencyHistoryRows($rows);
        $reportAlternatives = collect();
        $chartReport = null;
        $chartSourceRows = $rows;
        $reportAlternativeLabels = [];
        $reportComparisonNote = null;
        $selectedReportOmitsNota = false;
        if ($kind === 'ac' && $state === 'Meghalaya') {
            $pair = $rows->filter(fn (array $row): bool => (int) $row['entry']->year === 2023
                && $this->isReviewedMeghalayaReport($row, $reviews));
            if ($pair->count() === 2 && $pair->pluck('entry.edition_id')->unique()->count() === 2
                && $pair->pluck('entry.record_code')->unique()->count() === 1) {
                $reportAlternatives = $pair;
                $reportAlternativeLabels = [
                    '4bc1b1af27d38f017624a7d3' => '2023 Including AC-23 (candidate votes; NOTA not listed)',
                    'acfd4b614c6f42164b7844a6' => '2023 Excluding AC-23 (candidate votes and NOTA)',
                ];
                $selectedReportOmitsNota = $chosen['entry']->edition_id === '4bc1b1af27d38f017624a7d3';
                $reportComparisonNote = 'These reports agree on candidates, winner, margin and turnout for this seat. The including-AC-23 workbook omits NOTA; the excluding-AC-23 workbook lists it. Shares use all vote rows listed in the selected report, so their denominators differ. Both original reports are retained; no votes are added to either report.';
                $chartReport = $pair->first(fn (array $row): bool => $row['entry']->edition_id === $chosen['entry']->edition_id) ?? $pair->first();
                $chartSourceRows = $rows->reject(fn (array $row): bool => $pair->contains(fn (array $alternative): bool => $alternative['entry']->edition_id === $row['entry']->edition_id)
                    && $row['entry']->edition_id !== $chartReport['entry']->edition_id)->values();
            }
        }

        $directoryPeople = app(PublicOfficeDirectory::class)->forPlaces($placeIds->count() === 1 ? $placeIds->map(fn ($id): int => (int) $id)->all() : []);

        return view(($input['format'] ?? null) === 'report' ? 'constituency-history-report' : 'constituency-overview', compact('directoryPeople', 'kind', 'state', 'name', 'rows', 'historyRows', 'chosen', 'latest', 'related', 'exactSeatOnly', 'modernSeatScope', 'reportAlternatives', 'chartSourceRows', 'chartReport', 'reportAlternativeLabels', 'reportComparisonNote', 'selectedReportOmitsNota'));
    }

    /** Exact inspected source records only; report alternatives are not deduplicated or merged. */
    private function isReviewedMeghalayaReport(array $row, HistoricalElectionReview $reviews): bool
    {
        $hashes = [
            '4bc1b1af27d38f017624a7d3' => ['source' => 'eaba46674e0b097f97864d5cda40c9301e60ef4d943781cd912426e54cb11e63',
                7 => '79f42a5d0efea0324b916ab37ed38cfcff91867ab1459bd4ed9977ce6f92ef55',
                40 => '35fd9e581be152649482cf9babf9286624c7ab8a7775f97379fb64c80a079e80',
                53 => 'ddfb4e5959422caabf1cf5dd78cdd56cf29bee46ae1ebdaf8fb20bb31a704b5e',
                60 => '00427f2a1b3cc2c756782eda1792af009106142c3f5d5fc510eaf63d72f33aec',
                16 => 'e7b761c50704a5666507e24d50909c2e47cbc805855533c89de38ca3ed56fc4e',
                49 => 'f9607e7c405010145054dfb5bea99fc11db824c1dcd91da708b42cbe372e3795',
                57 => '32fc99a78a557e4b01bcb68a7846bc4eb2f95e88e6b24ea96f97c0992a910a10',
                59 => 'eaf4167f16de6ae1b4644b27f08d0768a33119c09002f73c53a1900994912963',
                2 => '6c4e40fe462709f6f684bba85738fa053a420cf1091b14e41d1116ab8af71d0d',
                10 => 'fdd285d18345011fe3f354be05ebb7403b333c942824d91ab623218064263c88',
                37 => 'cb86c2eb9976bb41e9f2f08d888383495a946738535ca927f498875308670ac5',
                56 => '9f529163edc964287985f858587d157c8587dedc68d92ec648b3c2268688a5c1',
                6 => 'f44c3c08b25f41b28620c2245f01b2ae1afde85c37871364b600a6c68b5e15c1',
                8 => '822fde6a680e81c034af5bada77979f1e19d96bf1d28114aa195eea686c5169a',
                30 => '0fbb2cd209cdd35c98829c61bf6564ec01fb66e09e17dbafdd2576068bed7478',
                54 => 'b6596a2cf9d612567fcdaebae78255b86fb165d684688d569d4789bed6705043',
                15 => '98f6955b8a6de39e1d7226d8a780c93ee1a28d53d4ebea21a9e702cce8c2f809',
                24 => '001df6726dd010f86460152cd964c1e3e0daec7be01ef2db676b37b162e47b4b',
                29 => 'ca644ca5c4ae7bb83eabf5a9056ac830bdf60d6af133b277b19d8d61d94201b8',
                36 => '4ea30784b2ed1e1cc1367d6bee27c3ebf75dbf2ef2132606ab88ac34a191b79f',
                13 => 'f167816b806a184f6fc2cd2fccb1b2e72c2f1c32a541b68efeeffb4fc27de63c',
                25 => '3f02b5f98b4e71828652a4b52fa88ff3c4dd779b7adbe76548ec701e087d5bae',
                31 => '5e38bfa006aca876079bc19d3d22215964e7e218ed86d1d663f599239247218b',
                34 => '32c262095b0f1412567c12740f6e888e820777e52eb9afe5ee6a9424a405151c',
                1 => 'eff9595f5f56e83e75e046ede15ae4d68abde5e92fa3f0f2de688f436760e095',
                4 => 'c215c03f45d408488ea807aa79b3412fcd8d6b36ec421628590f391b9ac2ddea',
                20 => '72921e2c25ccad93dda3c78b2512b12b4eb6222a5a16c6a92b70f939d1ed85b1',
                38 => 'a1f01cddedf7d48af5d5be6df9036b272fc7dd9bb1d599de0c5fabcbc44a95e5',
                9 => '36983eba9ebbfceefb630e41cfd5cd4cf0516161f9de8b2a9b8ed97073ef814c',
                21 => 'a3573a3d876c125394b221fdac44723c964b13ce0d7c8fade85c804ff2248a04',
                22 => '7d06470ce230a1658263232a5414d1fa1c990af9f9ed88e57fe2db6bd425283b',
                32 => '744102ad3ba14201495ead817b81946f1813c7afbad8e34e78f4870d6f947685',
                14 => 'd45bbf17184c5c3b68cf872936c697adcf294c2c75363c2fa0d980d1488f7975',
                17 => 'e59eeb7bc3880f600cc03124fce6efdb8a105de647e23a3cbedf86ed0e42e409',
                46 => '2edad6088605fe034990ee71d56b884ffe1df99b111ac2893441567efbc24a84',
                50 => 'e7a0f5e17732d9d769c8686b6aa4945f79864e56eb5cafa4127ebaa3baf2246a',
                3 => '50489149c8ec3e4c7b404902e7ee17d1daf5758073e5ba0872d23e387489b3fd',
                27 => '8fefe8660747a58ba1b60737fc7e26671be36515194944ddb58f313ebf333db4',
                44 => '9ca4bbf8f2769ac2b88c7ba7dd77161929409690cf6a496d612f835272497460',
                47 => 'ad59b95154dc59029d8629b98dcd572c2d0261641b3e9c49ed4bd59d1d88e860',
                33 => '1703764a9e3921d7f7431f93436600a4e98aa6aefa240c8a9c7fa864935c9cdc',
                35 => 'afd53edb9fca98dba14230f976e4c5d3819c6d8b3690053f71b439c768982b41',
                39 => '39976edd007e38a748d440cb5f5ca6a16249225517c324d04bd8c47941d63074',
                52 => '9f46ad7e3fbff74c288e0364f3321a04293b5c7096d773512c9847c3fb8abd4d',
                42 => '82d9a2d6e0971200cc7157ac825262bbe411a02765f6508d475e17d82ef46baa',
                48 => '20b9fe3aad467b01b7f70a2b823eb54b51cd3652ab6abdcf75b9b03e2d9d92df',
                55 => '6862177dc7ca94fbf379df93ce3f915634edb37952bcd6971e2c76e3257097f2',
                58 => 'e56da88cea8315ae37cf34ac3613411599c993364b7d72156df8d9ad8be3b082',
                26 => '2dae45c327bb3dcbd15a8b73c22f34675e3e819bd9eec14b6fd8a3261876668c',
                28 => 'efc75cb5b76412348d91fc42f113dddc198b0691823806aeda74a228a347a92d',
                41 => 'f7b6571b51a65ff5a7d3e5bb689ac3477b49d763636d3a56162bed5f4ea2bad8',
                5 => '2c8fcadd715b59722ed0282bda108fd7cfa37fe58a9d664e51eccbce94761f63',
                19 => '9b21b02dc86a5ed07de5f03be4d33988e882d6807cdeea7aa9b42495d5c2a754',
                45 => 'd300a07ad575a545470d017dbb3088ae4289cd3ab82eebbe656cabf211d62fcf',
                51 => '43783c6aef87758f6a1342fc85224842b813ce4294ca702ab3fd6869575694dd',
                11 => 'd13819da0e2c3f2c30d846cf7648d4774d4843da652ab96629a25bb520fd3dd8',
                12 => 'f52921c3a4d01d1abad8723a2f21bbe96f496d816b423e1ce82cb05da9bc534f',
                18 => '9d8e09c4f70849562b220ac44a88ea46382f1e926f60e1f4b55286af9bddaea9',
                43 => '7c37b0a0f1f91184d2bf82b2e8c4dbe083b144f21f5282f7f648fc30b007ff66'],
            'acfd4b614c6f42164b7844a6' => ['source' => '3de56b600190c89e52226a1fc4e5c130979a281eccd2ae7343fb04c141c72f3d',
                7 => 'fcc25aae2d4633c61d2cf1a7cd41ff857b9a40c38cadff0f426181f93c34ad03',
                40 => '66ba706b3703e151736434a8bd96dd24e68aa7fe5bf5758722798f3a27f4487d',
                53 => '9e800cf90afeff686ffab322a846af0d2b185fdba3af6079aa1d9d1a8f924dc1',
                60 => 'b73f366d3af68777537ff5c5982553535beef7c8721dab62840c34cad562b2fe',
                16 => '70c4a23cd56b7e17c21fc4c3c87849906ae1da9911a17545084a375c5fd4074d',
                49 => '5e991940329f89cf81ac59b4dc45b84f835d267d56bdf18c2a989d5838c9d34a',
                57 => '6092c7eeb241acf8797add0f4212779d25c70b7681cc1992ecaad63b4fe13230',
                59 => 'de6fe5844290803d7b179dc79b9e9b3672b9349e8b506b649074fcd369033b57',
                2 => '01387f9015df3eff674e867397ca9465137f9d8f9fef51ecb1d1706d24910c4f',
                10 => '9fe1c761cb6d11a42289233ebc60d3e05970c7ef72ae1a7fab2773830dee80de',
                37 => '82cdeca53c69db6e79c605ad23b1cfa218256dadedaa18b8b0f9042786848669',
                56 => 'f12ddda56251a8430290aef08e39fbb8094ba3dac79c28b985a61d9c5d9f7dd3',
                6 => '196b39f893d9e1c366bc305733f10181cf06d93fc5df679fc808b3a481f55f57',
                8 => '6f2041cacf38662be223a940136ccbbe7bce70bac8190fe1cf06d30611579720',
                30 => '516895bcbefbb2edd6590785d101e9e455f8811228b8e548baac97093f51ac2c',
                54 => '53172bdd422cb6d5b57b5fd96cc38bb7279a099bc48a29d75b02427e81fcbb35',
                15 => '65b200103b3aa6e44c68e41630bc7c702293f5f5c677c3122c276acafa5f7ec0',
                24 => 'fb3f42c39ed15a2624aa6e86fc3cbc84bb78bc7452ea56edc0ea1d7e68619215',
                29 => '3d1c5403935bd34c6cb9ac0e3863b90d6e1fbe281748bde13eb2f424e9af24b9',
                36 => 'e2923f12c32ee9b09deaf02adbdfaa8bd491cb732ad6ae9b0474b333f03bbde0',
                13 => '4e3840bb129df47b3b76c52517c430d50bd46759661985991b7d72b5e28502e1',
                25 => '799a93a9cb050463b0968456323e039b8f42f68a6aff43e1e504fc1374d4c4e1',
                31 => 'cad6d09a6e6676ce8a2589d778d8209c87c1cc2a0a9f3b245de894d5b8087bdd',
                34 => '15cb16577a33e7dd771451eb88f25df95efdecdb4fdae8d0537bfd167e268876',
                1 => '814311868ddbda64bafc7874e767cb493e5f4d7cd9cc1e9bb8a2c57a1e3b5fcc',
                4 => 'b25acc34045748b162bc39e05986ccd2570b5b10b4cbdce0180c60c5dd11dacd',
                20 => '0ad24de1940e24eb039dae7a8e52d1367bcd7c651ee56409411cbdb21c7b18ef',
                38 => 'fca5a3bf84f0c5891c151562f6b64fee1e9de7216917ac62eb9c7d9ba1d0b30f',
                9 => '73ce8b0f023c1cf6b54226e6dd8613089b24f6fdc933de43cf0013c0989414cc',
                21 => '3fe45afabaf7b671a83016c4175ffb8e5155140a0bbbcc3608c9e2fd7b8e9cf5',
                22 => 'ef60d6c489af38d114a7d4322d964f1569208739bd9855cde643d786dc374af1',
                32 => '56f77c6d93aa9f969c05eb714ddd48785f5a96bc6cbb5cf5eeca8cacfd9cc599',
                14 => 'a1474a2d85eff983ddca28e006f4448ffa3e4479a152626b301c11279d2fa7ba',
                17 => '0b4db342aca3a59e07395f9faf0054b14dfa6d3c6a3c1d5a6ef298cfe23eea46',
                46 => '083a48abc2455f1cd86a7e0f503eba0d47245b836eb4d9812d1e0fe3f6f5e22f',
                50 => '88a0bd03f1eae10933745d7f7c5c94f65f2cb1552c039333315d6405d38e8880',
                3 => 'ebb08061f712c5546dbc10c4a243f7291988e87de5ee8f6678204459e62eadfd',
                27 => 'c53276bc3bd910aaba2dd5b8319627e441a5dc09c1289c5d97a45b2d548fb56b',
                44 => 'c957304c9693d33f271008a444987d20a497e0f64c1560d3352c3afec2ea9cbb',
                47 => 'f5d393c493256f521aceffc91460e7320a87644837894720db62650362cf8eaf',
                33 => '6420a7672e409e4d2bb7e23c8a7674ab958f8da3f9db9fdc703417e9950470dc',
                35 => '52347501e1e17256122e0095de57abcb9522a1f9bb4362fbcac7ea0ff2a76838',
                39 => '59dfdbfa741cf54d2623a8ee1591bb5fb9af4dc6deb7bc4a38892c6b2b1da551',
                52 => '77548b46cf94eb599a3743a98bb9ae25554c7e0cda02a92cdffbc14bdbaa0af6',
                42 => '05894cdfe4f8a176d55e61fef33a10d8bba3bb343c4359fc5604d328266c4468',
                48 => '5e88fdb90c1ee8659a1e0ee8893a45e50dfb322c545589992c74fc9146eef964',
                55 => 'dd67e56a8bedc14b4f086025c6c03615ab865732cd8eb1027507dcaeb216cced',
                58 => 'a35036bc16a03729e5bd6548e533e98c1e0263bbd95181d88fef27dccede158b',
                26 => 'f56fb95239016f84bde13c43762d105b05fccaded202dff8c19bc59108e1f5b2',
                28 => '2c6de7dca7b14ba185f5d96923021a8ade1d2da2bdde573264f3abaf041c687e',
                41 => '44cbcdb992272e66d2b3f35ac1653103c0089d66d2be5700a61ac976d6913e6c',
                5 => 'eb289e22e82e5da168d430e8e2ecde0d2883a7f2ad34382f0f37b33d6c589f94',
                19 => '9b4e9d381dff52512c14fa649c245b5de584c3fbb809efbb75bb9c6449f31f0e',
                45 => '103bd346dead38c39fd51e38215a896d6670e84ac39a20197f0a6f05b4b44d39',
                51 => '3f3a6c5d9009f10886a0cdf9b38d9b6a5e71f56d416361c8e545f972bd0154a5',
                11 => '23a730c88b2cb6a9ed4424a3ed91529c48c4c2930b84c15a013de5a6a45955b7',
                12 => 'e5347fb2f2088e8372925b378add7453f22e6e07ebc4701f7bed86154990da22',
                18 => '4266428976a547808a1e37542e662f1979ecb86aa6b1a972c95b3717bafc7dc1',
                43 => '783e4a8fe03d50449d89daecf79974efc036ad279a5d1f05fe509c6171a48b7b'],
        ];
        $source = $hashes[$row['entry']->edition_id] ?? [];
        $expected = $source[$row['entry']->record_code] ?? null;
        $record = $row['record'];
        if (! $expected || ! $record || ($record['has_warning'] ?? true)
            || ($record['review_fingerprint'] ?? null) !== $expected) {
            return false;
        }
        unset($record['review'], $record['review_id'], $record['review_fingerprint'], $record['review_archive'], $record['has_warning']);

        return $reviews->fingerprint($record, $source['source']) === $expected;
    }
}
