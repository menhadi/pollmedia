<?php

namespace App\Http\Controllers;

use App\Services\DataCorrections;
use App\Services\ElectionArchive;
use App\Services\ElectionPlaceIdentity;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ListingController extends Controller
{
    public function index(Request $request, string $section): View
    {
        abort_if($request->has('table'), 404);
        $input = $request->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac', 'state' => 'nullable|string|max:100', 'year' => 'nullable|integer|min:1800|max:2100', 'id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $documents = collect();
        $warnings = [];
        $states = collect();
        $years = collect();
        if ($section === 'elections') {
            $base = DB::table('historical_constituency_index')->where('kind', $input['kind'] ?? 'pc');
            $states = (clone $base)->distinct()->pluck('state_label')->map(fn (string $state): string => ElectionPlaceIdentity::state($state))->unique()->sort()->values();
            $base->when(! empty($input['state']), fn ($query) => $query->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($input['state']))]));
            $years = (clone $base)->distinct()->orderByDesc('year')->pluck('year');
            $records = (clone $base)->when(! empty($input['q']), fn ($query) => $query->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($input['q']).'%']))->when(! empty($input['year']), fn ($query) => $query->where('year', $input['year']))->selectRaw('MIN(id) as id, MAX(year) as year, kind, constituency_name, '.ElectionPlaceIdentity::stateSql().' as state_label, COUNT(DISTINCT year) as available_years')->groupBy('kind', 'constituency_name')->groupByRaw(ElectionPlaceIdentity::stateSql())->orderBy('constituency_name')->paginate(30)->withQueryString();
            if (! empty($input['id'])) {
                $anchor = DB::table('historical_constituency_index')->find($input['id']);
                abort_unless($anchor, 404);
                $entries = DB::table('historical_constituency_index')->where('kind', $anchor->kind)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower(ElectionPlaceIdentity::state($anchor->state_label))])->where('constituency_name', $anchor->constituency_name)->orderByDesc('year')->get();
                foreach ($entries as $entry) {
                    try {
                        [$data] = app(HistoricalElectionArchive::class)->load($entry->edition_id, app(ElectionArchive::class));
                    } catch (HttpException $exception) {
                        if (! in_array($exception->getStatusCode(), [404, 409, 503], true)) {
                            throw $exception;
                        }
                        $warnings[] = $entry->constituency_name.' '.$entry->year.': the source record is unavailable or its source verification failed. Other available years can still be edited.';

                        continue;
                    }
                    $original = collect($data['records'])->firstWhere('code', $entry->record_code);
                    if (! $original) {
                        continue;
                    }
                    $record = app(HistoricalElectionReview::class)->apply($entry->edition_id, $original, $data['source_sha256']);
                    $version = $record['review_id'];
                    unset($record['review'], $record['review_id'], $record['review_fingerprint'], $record['has_warning']);
                    $record = $this->electionFields($record);
                    $documents->push($this->document('historical_constituency_index', $entry->id, $entry->constituency_name.' · '.$entry->year, $record, hash('sha256', json_encode([$data['source_sha256'], $original, $version])), $data['source_url'], route('constituency.overview', ['kind' => $entry->kind, 'state' => ElectionPlaceIdentity::state($entry->state_label), 'name' => $entry->constituency_name, 'edition' => $entry->edition_id, 'code' => $entry->record_code]), ['code', 'status', 'winner', 'margin', 'admin_listing_correction'], $entry->edition_id));
                }
            }
        } elseif ($section === 'census') {
            $years = DB::table('census_editions')->distinct()->orderByDesc('year')->pluck('year');
            $records = DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')->when(! empty($input['q']), fn ($query) => $query->where('r.name', 'like', '%'.$input['q'].'%'))->when(! empty($input['year']), fn ($query) => $query->where('e.year', $input['year']))->select('r.*', 'e.year')->orderBy('r.name')->paginate(30)->withQueryString();
            if (! empty($input['id'])) {
                $record = DB::table('census_catalogue_rows')->find($input['id']);
                abort_unless($record, 404);
                $edition = DB::table('census_editions')->find($record->edition_id);
                $fields = (array) $record;
                foreach (['values', 'geography', 'flags'] as $key) {
                    $fields[$key] = json_decode($fields[$key], true, 512, JSON_THROW_ON_ERROR);
                }
                $documents->push($this->document('census_catalogue_rows', $record->id, $record->name.' · Census '.$edition->year, $fields, hash('sha256', json_encode($record)), $edition->source_url, route('civic.place', ['record' => $record->id]), ['id', 'edition_id', 'record_key', 'source_row']));
            }
        } else {
            $rows = app(DataCorrections::class)->sirRows()->filter(fn ($row) => empty($input['q']) || str_contains(mb_strtolower($row->name), mb_strtolower($input['q'])))->values();
            $records = new LengthAwarePaginator($rows->slice(($request->integer('page', 1) - 1) * 30, 30), $rows->count(), 30, $request->integer('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
            if (! empty($input['id'])) {
                $row = app(DataCorrections::class)->record('sir_parts', (int) $input['id']);
                $documents->push($this->document('sir_parts', $row->id, $row->name, (array) $row, hash('sha256', json_encode($row)), $row->source_url ?? '', url('/india/sir'), ['id', 'part']));
            }
        }

        return view('listing-editor', compact('section', 'input', 'states', 'years', 'records', 'documents', 'warnings'));
    }

    private function document(string $table, int $id, string $label, array $fields, string $expected, string $source, string $public, array $readonly, ?string $archive = null): array
    {
        $attachments = DB::table('site_settings')->where('key', 'like', 'listing-file:'.$table.':'.$id.':%')->get();

        return compact('table', 'id', 'label', 'fields', 'expected', 'source', 'public', 'readonly', 'attachments', 'archive');
    }

    public function save(Request $request): RedirectResponse
    {
        $input = $request->validate(['table' => 'required|in:historical_constituency_index,census_catalogue_rows,sir_parts', 'id' => 'required|integer|min:1', 'expected' => 'required|string|size:64', 'record' => 'required|json', 'reason' => 'required|string|min:5|max:2000', 'reference_url' => 'nullable|url:http,https|max:2000']);
        $fields = json_decode($input['record'], true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($fields), 422);
        DB::transaction(function () use ($input, $fields, $request): void {
            if ($input['table'] === 'historical_constituency_index') {
                $entry = DB::table('historical_constituency_index')->where('id', $input['id'])->lockForUpdate()->first();
                abort_unless($entry, 404);
                [$data] = app(HistoricalElectionArchive::class)->load($entry->edition_id, app(ElectionArchive::class));
                $original = collect($data['records'])->firstWhere('code', $entry->record_code);
                abort_unless($original, 404);
                $reviews = app(HistoricalElectionReview::class);
                $current = $reviews->apply($entry->edition_id, $original, $data['source_sha256']);
                abort_unless(hash_equals($input['expected'], hash('sha256', json_encode([$data['source_sha256'], $original, $current['review_id']]))), 409, 'This listing changed. Reload before saving.');
                unset($current['review'], $current['review_id'], $current['review_fingerprint'], $current['has_warning']);
                $current = $this->electionFields($current);
                $this->validateFields($current, $fields, ['code', 'status', 'winner', 'margin', 'admin_listing_correction']);
                $candidates = collect($fields['candidates'] ?? [])->reject(fn ($row) => ($row['is_nota'] ?? false) || strtoupper($row['party_at_election'] ?? '') === 'NOTA')->sortByDesc('votes')->values();
                foreach ($fields['candidates'] ?? [] as $candidate) {
                    if (isset($candidate['general_votes'], $candidate['postal_votes'], $candidate['votes']) && $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes']) {
                        throw ValidationException::withMessages(['record' => 'General and postal votes must equal candidate total votes.']);
                    }
                }
                if (isset($fields['valid_candidate_votes']) && $candidates->every(fn ($row) => isset($row['votes'])) && $candidates->sum('votes') !== $fields['valid_candidate_votes']) {
                    throw ValidationException::withMessages(['record' => 'Candidate votes must equal valid candidate votes.']);
                }
                if (isset($fields['votes_polled'], $fields['electors']) && $fields['votes_polled'] > $fields['electors']) {
                    throw ValidationException::withMessages(['record' => 'Votes polled cannot exceed registered electors.']);
                }
                if (isset($fields['votes_polled']) && collect($fields['candidates'] ?? [])->sum('votes') > $fields['votes_polled']) {
                    throw ValidationException::withMessages(['record' => 'Candidate votes including NOTA cannot exceed votes polled.']);
                }
                unset($fields['winner'], $fields['margin']);
                if (($fields['number_of_seats'] ?? 1) === 1 && count($candidates) > 1 && isset($candidates[0]['votes'], $candidates[1]['votes']) && $candidates[0]['votes'] > $candidates[1]['votes']) {
                    $fields['winner'] = $candidates[0]['candidate_name'];
                    $fields['margin'] = $candidates[0]['votes'] - $candidates[1]['votes'];
                }
                $fields['status'] = 'corrected';
                $fields['admin_listing_correction'] = true;
                DB::table('historical_election_reviews')->insert(['archive' => $entry->edition_id, 'code' => $entry->record_code, 'fingerprint' => $reviews->fingerprint($original, $data['source_sha256']), 'action' => 'correct', 'reason' => $input['reason'], 'reference_url' => $input['reference_url'] ?? null, 'record' => json_encode($fields, JSON_THROW_ON_ERROR), 'reviewed_by' => $request->user()->id, 'created_at' => now()]);
                $displayName = $fields['constituency_name'] ?? $fields['name'] ?? $entry->constituency_name;
                DB::table('historical_constituency_index')->where('id', $entry->id)->update(['constituency_name' => $displayName, 'status' => 'corrected', 'has_warning' => false]);
            } else {
                if ($input['table'] === 'sir_parts') {
                    DB::table('site_settings')->insertOrIgnore(['key' => 'sir-edit-lock', 'value' => 'null', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('site_settings')->where('key', 'sir-edit-lock')->lockForUpdate()->first();
                }
                $current = $input['table'] === 'sir_parts' ? app(DataCorrections::class)->record('sir_parts', (int) $input['id']) : DB::table('census_catalogue_rows')->where('id', $input['id'])->lockForUpdate()->first();
                abort_unless($current, 404);
                abort_unless(hash_equals($input['expected'], hash('sha256', json_encode($current))), 409, 'This listing changed. Reload before saving.');
                $before = (array) $current;
                if ($input['table'] === 'census_catalogue_rows') {
                    foreach (['values', 'geography', 'flags'] as $key) {
                        $before[$key] = json_decode($before[$key], true, 512, JSON_THROW_ON_ERROR);
                    }
                }
                $this->validateFields($before, $fields, ['id', 'edition_id', 'record_key', 'source_row', 'part']);
                if ($input['table'] === 'sir_parts') {
                    DB::table('site_settings')->updateOrInsert(['key' => 'sir-part:'.$input['id']], ['value' => json_encode($fields, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $update = $fields;
                    foreach (['values', 'geography', 'flags'] as $key) {
                        $update[$key] = json_encode($fields[$key], JSON_THROW_ON_ERROR);
                    }
                    unset($update['id'], $update['edition_id'], $update['record_key'], $update['source_row']);
                    DB::table('census_catalogue_rows')->where('id', $input['id'])->update($update);
                }
                DB::table('site_changes')->insert(['target' => $input['table'].':'.$input['id'], 'before_value' => json_encode($before), 'after_value' => json_encode($fields), 'reason' => $input['reason'], 'user_id' => $request->user()->id, 'created_at' => now()]);
            }
        });

        return back()->with('status', 'Listing saved. Public tables, charts and representative results now use the corrected values.');
    }

    private function electionFields(array $record): array
    {
        $record += ['electors' => null, 'votes_polled' => null, 'valid_candidate_votes' => null, 'winner' => null, 'margin' => null, 'number_of_seats' => 1, 'candidates' => []];
        $record['candidates'] = array_map(fn (array $row): array => $row + ['candidate_name' => '', 'party_at_election' => '', 'votes' => null, 'general_votes' => null, 'postal_votes' => null], $record['candidates']);

        return $record;
    }

    private function validateFields(array $before, array $after, array $readonly = []): void
    {
        abort_unless(array_keys($before) === array_keys($after), 422, 'Keep the listing field structure.');
        foreach ($before as $key => $value) {
            if (in_array($key, $readonly, true)) {
                abort_unless($after[$key] === $value, 422, 'Listing identity and calculated fields cannot be changed directly.');
            } elseif (is_array($value)) {
                abort_unless(is_array($after[$key]), 422);
                $this->validateFields($value, $after[$key]);
            } else {
                abort_unless($after[$key] === null || is_scalar($after[$key]), 422);
                if (is_int($value) || is_float($value)) {
                    abort_unless($after[$key] === null || (is_numeric($after[$key]) && ($after[$key] >= 0 || $after[$key] === $value)), 422, 'Numeric measures must be nonnegative numbers or empty.');
                } elseif (is_bool($value)) {
                    abort_unless(is_bool($after[$key]), 422);
                } elseif (is_string($value)) {
                    abort_unless(is_string($after[$key]) && (mb_strlen($after[$key]) <= 4000 || $after[$key] === $value), 422);
                }
            }
        }
    }
}
