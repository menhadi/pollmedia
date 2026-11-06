<?php

namespace App\Services;

use App\Http\Controllers\ListingController;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CommunityElectionCorrections
{
    public function document(int $id): array
    {
        $entry = DB::table('historical_constituency_index')->find($id) ?? abort(404);
        [$source] = app(HistoricalElectionArchive::class)->load($entry->edition_id, app(ElectionArchive::class));
        $original = collect($source['records'])->firstWhere('code', $entry->record_code) ?? abort(404);
        $current = app(HistoricalElectionReview::class)->apply($entry->edition_id, $original, $source['source_sha256']);
        $expected = hash('sha256', json_encode([$source['source_sha256'], $original, $current['review_id']]));
        unset($current['review'],$current['review_id'],$current['review_fingerprint'],$current['has_warning']);
        $current += ['electors' => null, 'votes_polled' => null, 'valid_candidate_votes' => null, 'winner' => null, 'margin' => null, 'number_of_seats' => 1, 'candidates' => []];
        $current['candidates'] = array_map(fn (array $row): array => $row + ['candidate_name' => '', 'party_at_election' => '', 'votes' => null, 'general_votes' => null, 'postal_votes' => null], $current['candidates']);
        $fields = [];
        foreach (Arr::dot($current) as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $editable = (bool) preg_match('/^(?:name|constituency_name|electors|votes_polled|valid_candidate_votes|number_of_seats|candidates\.[0-9]+\.(?:candidate_name|party_at_election|votes|general_votes|postal_votes))$/D', $key);
            $fields[$key] = ['label' => str_replace(['_', '.'], [' ', ' · '], $key), 'value' => $value, 'editable' => $editable];
        }

        return compact('entry', 'source', 'original', 'current', 'expected', 'fields');
    }

    public function publish(Request $request, object $proposal, mixed $value, string $note): void
    {
        $document = $this->document($proposal->target_id);
        abort_unless(hash_equals($document['expected'], $proposal->expected), 409, 'This source record changed. Reload before reviewing.');
        abort_unless($document['fields'][$proposal->field]['editable'] ?? false, 422);
        $old = $document['fields'][$proposal->field]['value'];
        if (is_int($old) || is_float($old) || preg_match('/^(?:electors|votes_polled|valid_candidate_votes|number_of_seats|candidates\.[0-9]+\.(?:votes|general_votes|postal_votes))$/D', $proposal->field)) {
            abort_unless($value === null || (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0), 422);
            $value = $value === null ? null : $value + 0;
        } else {
            abort_unless($value === null || is_scalar($value), 422);
        }
        $record = $document['current'];
        Arr::set($record, $proposal->field, $value);
        if (in_array($proposal->field, ['name', 'constituency_name'], true)) {
            foreach (['name', 'constituency_name'] as $nameField) {
                if (array_key_exists($nameField, $record)) {
                    $record[$nameField] = $value;
                }
            }
        }
        $request->merge(['table' => 'historical_constituency_index', 'id' => $proposal->target_id, 'expected' => $document['expected'], 'record' => json_encode($record, JSON_THROW_ON_ERROR), 'reason' => 'Community suggestion #'.$proposal->id.': '.$note, 'reference_url' => $proposal->reference_url]);
        app(ListingController::class)->save($request);
    }
}
