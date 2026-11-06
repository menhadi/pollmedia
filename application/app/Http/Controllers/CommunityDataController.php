<?php

namespace App\Http\Controllers;

use App\Services\CommunityData;
use App\Services\CommunityElectionCorrections;
use App\Services\DataCorrections;
use App\Services\ElectoralVillageMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDO;

class CommunityDataController extends Controller
{
    public function account(Request $request): Response|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login'));
        }
        $status = $request->validate(['status' => 'nullable|in:pending,approved,rejected'])['status'] ?? null;
        $own = DB::table('community_data_proposals')->where('user_id', $request->user()->id);
        $counts = (clone $own)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $proposals = DB::table('community_data_proposals as p')
            ->leftJoin('census_catalogue_rows as r', 'r.id', '=', 'p.record_id')
            ->leftJoin('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->where('p.user_id', $request->user()->id)
            ->when($status, fn (Builder $query) => $query->where('p.status', $status))
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->select('p.*', 'r.name as place_name', 'e.year', 'e.name as edition_name')
            ->paginate(20)->withQueryString();

        return response()->view('community-dashboard', compact('counts', 'proposals', 'status'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $request, CommunityData $data): View
    {
        $input = $request->validate(['year' => 'nullable|in:2001,2011', 'edition' => 'nullable|integer|min:1', 'state' => 'nullable|regex:/^[0-9]{1,2}$/',
            'district' => 'nullable|regex:/^[0-9]{1,3}$/', 'level' => 'nullable|in:STATE,DISTRICT,SUB-DISTRICT,VILLAGE,TOWN,WARD,SOURCE RECORD',
            'residence' => 'nullable|in:Total,Rural,Urban,Unresolved', 'topic' => 'nullable|in:'.implode(',', array_keys(CommunityData::TOPICS)),
            'measure' => 'nullable|string|max:100', 'q' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac', 'seat' => 'nullable|regex:/^[0-9]{1,4}$/',
            'dataset' => 'nullable|in:people,facilities,originals']);
        $year = (int) ($input['year'] ?? 2011);
        if (($input['dataset'] ?? '') === 'originals') {
            $input['level'] = 'SOURCE RECORD';
            $input['residence'] = 'Unresolved';
            $input['topic'] = 'other';
            unset($input['state'], $input['district'], $input['kind'], $input['seat'], $input['measure']);
        }
        if (! empty($input['state'])) {
            $input['state'] = str_pad($input['state'], 2, '0', STR_PAD_LEFT);
        }
        $level = $input['level'] ?? (! empty($input['district']) || ! empty($input['seat']) || ($year === 2001 && ! empty($input['state'])) ? 'VILLAGE' : (! empty($input['state']) ? 'DISTRICT' : 'STATE'));
        $residence = $input['residence'] ?? (in_array($level, ['VILLAGE', 'SUB-DISTRICT'], true) ? 'Rural' : ($level === 'TOWN' ? 'Urban' : 'Total'));
        $dataset = $input['dataset'] ?? 'people';
        $stateOptions = $data->cachedRows('states:'.$year, fn () => $data->publishedRows()->where('e.year', $year)->where('r.level', 'STATE')->where('r.residence', 'Total')->where('r.state_code', '!=', '00')->select('r.state_code')->selectRaw('MIN(r.name) as name')->groupBy('r.state_code')->orderBy('name')->get());
        $districtOptions = ! empty($input['state']) ? $data->cachedRows('districts:'.$year.':'.$input['state'], fn () => $data->publishedRows()->where('e.year', $year)->where('r.state_code', $input['state'])->where('r.level', 'DISTRICT')->where('r.residence', 'Total')->select('r.district_code')->selectRaw('MIN(r.name) as name')->groupBy('r.district_code')->orderBy('name')->get()) : collect();
        if ($year === 2001 && ! empty($input['state'])) {
            $districtOptions = $data->cachedRows('original-districts:2001:'.$input['state'], fn () => $data->publishedRows()->where('e.year', 2001)->where('e.source_key', 'like', 'census-2001-villages-%')->where('r.state_code', $input['state'])->where('r.level', 'VILLAGE')->select('r.district_code')->selectRaw("'Census 2001 district' as name")->groupBy('r.district_code')->orderBy('r.district_code')->get());
        }
        $scopeName = ! empty($input['district']) ? ($districtOptions->firstWhere('district_code', $input['district'])?->name ?? 'District '.$input['district'])
            : (! empty($input['state']) ? ($stateOptions->firstWhere('state_code', $input['state'])?->name ?? 'State '.$input['state']) : 'India');
        if ($year === 2001 && ! empty($input['district']) && $districtOptions->contains('district_code', $input['district'])) {
            $scopeName .= ' '.$input['district'];
        }
        $base = $data->publishedRows()->where('e.year', $year);
        foreach (['state' => 'r.state_code', 'district' => 'r.district_code'] as $key => $column) {
            if (! empty($input[$key])) {
                $base->where($column, $input[$key]);
            }
        }
        $membershipNote = null;
        if (! empty($input['seat'])) {
            abort_unless(! empty($input['state']) && ! empty($input['kind']), 422, 'Select a state and constituency type.');
            [$ids, $scopeName, $membershipNote] = $this->constituencyRecords($input['state'], $input['kind'], $input['seat'], $year, $dataset, $data);
            $base = $data->publishedRows()->where('e.year', $year)->whereIn('r.id', $ids);
            $level = 'VILLAGE';
            $residence = 'Rural';
        }
        $base->where('r.level', $level)->where('r.residence', $residence);
        $allSources = $year === 2001 && $level === 'VILLAGE' && in_array($dataset, ['people', 'facilities'], true) && empty($input['edition']);
        if ($allSources) {
            $base->where('e.source_key', 'like', $dataset === 'facilities' ? 'census-2001-village-directory-all-%' : 'census-2001-villages-%');
        }
        $options = $data->cachedRows('editions:'.$year, fn () => DB::table('census_editions as e')->join('census_publications as p', 'p.edition_id', '=', 'e.id')->where('e.year', $year)->where('e.status', 'published')->orderBy('e.name')->select('e.id', 'e.name', 'e.source_key')->get());
        if (isset($input['edition'])) {
            $edition = DB::table('census_editions')->where('id', $input['edition'])->where('year', $year)->whereIn('id', $options->pluck('id'))->first() ?? abort(404);
        } else {
            $candidates = clone $base;
            if ($dataset === 'facilities') {
                $candidates->where('e.source_key', 'like', 'census-2001-village-directory-%');
            } elseif ($dataset === 'originals') {
                $candidates->where('e.source_key', 'like', '%source-only%');
            } else {
                $candidates->where('e.source_key', 'not like', '%source-only%')->where('e.source_key', 'not like', 'census-2001-village-directory-%')->where('e.source_key', 'not like', 'census-a02-%');
            }
            $editionId = null;
            if ($dataset === 'people') {
                foreach (['india-pca-'.$year, 'india-basic-'.$year.'-total'] as $preferred) {
                    $editionId = (clone $candidates)->where('e.source_key', $preferred)->value('r.edition_id');
                    if ($editionId) {
                        break;
                    }
                }
            }
            $editionId ??= $candidates->select('r.edition_id')->groupBy('r.edition_id')->orderByRaw('COUNT(*) DESC')->orderByDesc('r.edition_id')->value('r.edition_id');
            $edition = $editionId ? DB::table('census_editions')->find($editionId) : null;
        }
        $fields = $edition ? $data->fields($edition) : [];
        $topic = $input['topic'] ?? 'education';
        $topicFields = array_filter($fields, fn (array $field): bool => $field['topic'] === $topic);
        $measure = isset($input['measure'], $topicFields[$input['measure']]) ? $input['measure'] : array_key_first($topicFields);
        if (! isset($input['topic']) && ! $topicFields && $fields) {
            $measure = array_key_first($fields);
            $topic = $fields[$measure]['topic'];
            $topicFields = array_filter($fields, fn (array $field): bool => $field['topic'] === $topic);
        }
        $query = clone $base;
        if (! $allSources || ! $edition) {
            $query->where('r.edition_id', $edition?->id ?? 0);
        }
        $revision = DB::table('community_data_proposals')->where('status', 'approved')->count();
        $facts = $edition && $measure && $data->isRankable($measure, $fields[$measure])
            ? Cache::remember('community.facts:'.hash('sha256', json_encode([$query->toSql(), $query->getBindings(), $measure, $revision])), 300, fn () => $data->quickFacts($query, $measure)) : null;
        if (! empty($input['q'])) {
            $query->where('r.name', 'like', '%'.$input['q'].'%');
        }
        $rows = $query->select('r.*')->orderBy($level === 'SOURCE RECORD' ? 'r.source_row' : 'r.name')->orderBy('r.id')->paginate(50)->withQueryString();
        $recentChanges = DB::table('community_data_proposals as c')->join('census_catalogue_rows as r', 'r.id', '=', 'c.record_id')
            ->where('c.status', 'approved')->when($edition && ! $allSources, fn (Builder $q) => $q->where('r.edition_id', $edition->id))
            ->when($edition && $allSources, fn (Builder $q) => $q->whereIn('r.id', (clone $base)->select('r.id')))
            ->when(! $edition, fn (Builder $q) => $q->whereRaw('1=0'))->orderByDesc('c.reviewed_at')->limit(10)->select('c.*', 'r.name')->get();

        return view('community-data', compact('input', 'year', 'level', 'residence', 'dataset', 'edition', 'options', 'fields', 'topic', 'topicFields', 'measure', 'rows', 'facts', 'scopeName', 'membershipNote', 'recentChanges', 'data', 'stateOptions', 'districtOptions', 'allSources'));
    }

    private function constituencyRecords(string $state, string $kind, string $seat, int $year, string $dataset, CommunityData $data): array
    {
        $membership = app(ElectoralVillageMap::class)->membership((string) (int) $state, $kind, $seat);
        $lgds = array_column($membership['villages'], 'lgd');
        $ids = [];
        if ($year === 2001) {
            $path = $dataset === 'facilities' ? config('census-directory.index_path', database_path('fixtures/village-directory-2001-links.sqlite')) : database_path('fixtures/national-2001-profile-links.sqlite');
            if (is_file($path)) {
                $index = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $query = $index->prepare($dataset === 'facilities' ? "SELECT edition_id,record_key FROM links WHERE lgd=? AND source_key LIKE 'census-2001-village-directory-all-%'" : 'SELECT identity_json FROM identities WHERE lgd=?');
                foreach ($lgds as $lgd) {
                    $query->execute([(string) $lgd]);
                    $link = $query->fetch(PDO::FETCH_ASSOC);
                    if (! $link) {
                        continue;
                    }
                    if ($dataset === 'facilities') {
                        $id = $data->publishedRows()->where('r.edition_id', $link['edition_id'])->where('r.record_key', $link['record_key'])->value('r.id');
                    } else {
                        $identity = json_decode($link['identity_json'], true);
                        $id = $identity['record_id'] ?? null;
                    }
                    if ($id) {
                        $ids[] = (int) $id;
                    }
                }
            }
        } else {
            $directory = app(ElectoralVillageMap::class)->database();
            $query = $directory->prepare('SELECT state,census2011 FROM directory WHERE lgd=?');
            $codes = [];
            foreach ($lgds as $lgd) {
                $query->execute([(string) $lgd]);
                $links = $query->fetchAll(PDO::FETCH_ASSOC);
                if (count($links) === 1 && preg_match('/^[0-9]{6}$/', $links[0]['census2011'])) {
                    $codes[$links[0]['state']][] = $links[0]['census2011'];
                }
            }
            foreach ($codes as $sourceState => $sourceCodes) {
                foreach (array_chunk(array_unique($sourceCodes), 500) as $chunk) {
                    $ids = array_merge($ids, $data->publishedRows()->where('e.year', 2011)->where('r.state_code', str_pad((string) $sourceState, 2, '0', STR_PAD_LEFT))->where('r.level', 'VILLAGE')->where('r.residence', 'Rural')->whereIn('r.geography->Town/Village', $chunk)->pluck('r.id')->all());
                }
            }
        }

        return [array_unique($ids), $membership['name'].' constituency', $membership['note'].' Historical values are listed only through existing verified identities; historical constituency boundaries are not asserted.'];
    }

    public function show(int $record, CommunityData $data): View
    {
        $row = $data->record($record);
        $fields = $data->fields($row);
        $values = json_decode($row->values, true);
        $values['listing_name'] = $row->name;
        $changes = DB::table('community_data_proposals')->where('record_id', $record)->where('status', 'approved')->orderByDesc('reviewed_at')->get();
        $mine = auth()->check() ? DB::table('community_data_proposals')->where('record_id', $record)->where('user_id', auth()->id())->orderByDesc('id')->limit(20)->get() : collect();

        return view('community-record', compact('row', 'fields', 'values', 'changes', 'mine', 'data'));
    }

    public function propose(Request $request, int $record, CommunityData $data): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('community.record', $record, false)]));
        }
        abort_unless($request->user()->email_verified_at, 403, 'Verify your email before suggesting a correction.');
        $input = $request->validate(['field' => 'required|string|max:100', 'value_type' => 'required|in:text,number,missing', 'value' => 'nullable|string|max:1000', 'reason' => 'required|string|min:10|max:2000', 'reference_url' => 'nullable|url:http,https|max:2000', 'expected' => 'required|size:64']);
        $row = $data->record($record);
        $fields = $data->fields($row);
        abort_unless(isset($fields[$input['field']]) && $fields[$input['field']]['editable'], 422, 'Select an original value field. Identity and source fields cannot be edited.');
        abort_unless(hash_equals($data->fingerprint($row), $input['expected']), 409, 'This record changed. Reload before suggesting a correction.');
        $value = $input['value_type'] === 'missing' ? null : ($input['value'] ?? '');
        if ($input['value_type'] === 'number') {
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > 9007199254740991) {
                throw ValidationException::withMessages(['value' => 'Enter a finite number. Use text for original codes.']);
            }
            $value = str_contains((string) $value, '.') || stripos((string) $value, 'e') !== false ? (float) $value : (int) $value;
        }
        if ($input['field'] === 'listing_name') {
            abort_unless(is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255, 422, 'Enter a place name of up to 255 characters.');
        }
        $before = $input['field'] === 'listing_name' ? $row->name : (json_decode($row->values, true)[$input['field']] ?? null);
        abort_if($before === $value, 422, 'The suggested value is unchanged.');
        $duplicate = DB::table('community_data_proposals')->where('record_id', $record)->where('user_id', $request->user()->id)->where('field', $input['field'])->where('status', 'pending')->exists();
        abort_if($duplicate, 409, 'You already have a pending suggestion for this field.');
        DB::table('community_data_proposals')->insert(['record_id' => $record, 'user_id' => $request->user()->id, 'field' => $input['field'], 'before_value' => json_encode($before), 'proposed_value' => json_encode($value), 'expected' => $input['expected'], 'reason' => $input['reason'], 'reference_url' => $input['reference_url'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return redirect(CommunityData::publicEditReturn($request->input('return_to')) ?? route('community.record', $record))->with('status', 'Suggestion submitted for admin review. Published values have not changed.');
    }

    public function review(Request $request): View
    {
        $status = $request->validate(['status' => 'nullable|in:pending,approved,rejected'])['status'] ?? 'pending';
        $proposals = DB::table('community_data_proposals as c')->leftJoin('census_catalogue_rows as r', 'r.id', '=', 'c.record_id')->leftJoin('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->where('c.status', $status)->orderBy('c.id')->select('c.*', 'r.name', 'r.values as current_values', 'r.flags', 'r.edition_id', 'e.year')->paginate(30)->withQueryString();

        return view('community-review', compact('proposals', 'status'));
    }

    public function decide(Request $request, int $proposal, CommunityData $data, DataCorrections $corrections): RedirectResponse
    {
        $input = $request->validate(['decision' => 'required|in:approved,rejected', 'review_note' => 'required|string|min:10|max:2000', 'amend' => 'nullable|boolean', 'approved_value' => 'nullable|string|max:1000', 'approved_type' => 'nullable|in:text,number,missing']);
        DB::transaction(function () use ($proposal, $input, $request, $data, $corrections): void {
            $item = DB::table('community_data_proposals')->where('id', $proposal)->lockForUpdate()->first() ?? abort(404);
            abort_unless($item->status === 'pending', 409, 'This suggestion has already been reviewed.');
            $approved = json_decode($item->proposed_value, true);
            if (! empty($input['amend'])) {
                $approved = ($input['approved_type'] ?? 'text') === 'missing' ? null : ($input['approved_value'] ?? '');
                if (($input['approved_type'] ?? '') === 'number') {
                    abort_unless(is_numeric($approved) && is_finite((float) $approved) && abs((float) $approved) <= 9007199254740991, 422);
                    $approved = $approved + 0;
                }
            }
            if ($input['decision'] === 'approved' && $item->target_table === 'historical_constituency_index') {
                abort_if((int) $item->user_id === (int) $request->user()->id, 403);
                app(CommunityElectionCorrections::class)->publish($request, $item, $approved, $input['review_note']);
            } elseif ($input['decision'] === 'approved' && $item->target_table) {
                abort_if((int) $item->user_id === (int) $request->user()->id, 403);
                abort_unless(in_array($item->target_table, ['places', 'observations'], true), 422);
                $target = $corrections->record($item->target_table, $item->target_id);
                $placeId = $item->target_table === 'places' ? $target->id : $target->place_id;
                [, $documents] = app(CommunityListingController::class)->documents($placeId);
                abort_unless(collect($documents)->contains(fn (array $document): bool => $document['table'] === $item->target_table && $document['id'] === (int) $item->target_id && $document['field'] === $item->field), 422);
                if ($item->target_table === 'places') {
                    abort_unless(is_string($approved) && trim($approved) !== '' && mb_strlen($approved) <= 255, 422);
                } else {
                    abort_unless($approved === null || (is_numeric($approved) && is_finite((float) $approved) && abs((float) $approved) <= 9007199254740991), 422);
                }
                $corrections->save($item->target_table, $item->target_id, [$item->field => $approved], $item->expected, 'Community suggestion #'.$proposal.': '.$input['review_note']);
            } elseif ($input['decision'] === 'approved') {
                abort_if((int) $item->user_id === (int) $request->user()->id, 403, 'Another administrator must review your own suggestion.');
                $row = $data->record($item->record_id, true);
                abort_unless(hash_equals($data->fingerprint($row), $item->expected), 409, 'The published record changed. Reject this stale suggestion and request a new one.');
                $fields = $data->fields($row);
                abort_unless(isset($fields[$item->field]) && $fields[$item->field]['editable'], 422);
                $values = json_decode($row->values, true);
                $values[$item->field] = $approved;
                if ($item->field === 'listing_name') {
                    abort_unless(is_string($approved) && trim($approved) !== '' && mb_strlen($approved) <= 255, 422);
                }
                $original = DB::table('census_catalogue_rows')->where('id', $item->record_id)->first();
                $corrections->save('census_catalogue_rows', $item->record_id, ($item->field === 'listing_name' ? ['name' => $approved] : ['values' => json_encode($values, JSON_THROW_ON_ERROR)]), hash('sha256', json_encode($original)), 'Community suggestion #'.$proposal.': '.$input['review_note']);
                $flags = json_decode($row->flags, true) ?? [];
                $flags[] = 'Community correction #'.$proposal.' approved '.now()->utc()->format('Y-m-d H:i').' UTC for '.$item->field.'. Original source files are retained; see the public record change history.';
                DB::table('census_catalogue_rows')->where('id', $item->record_id)->update(['flags' => json_encode($flags, JSON_THROW_ON_ERROR)]);
            }
            DB::table('community_data_proposals')->where('id', $proposal)->update(['approved_value' => $input['decision'] === 'approved' ? json_encode($approved) : null, 'status' => $input['decision'], 'reviewer_id' => $request->user()->id, 'review_note' => $input['review_note'], 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('community.review')->with('status', 'Review saved.');
    }
}
