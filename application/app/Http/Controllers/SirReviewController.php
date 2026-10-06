<?php

namespace App\Http\Controllers;

use App\Services\AiProviders;
use App\Services\SirNameSearch;
use App\Services\SirVisionExtractor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SirReviewController extends Controller
{
    public static function fingerprint(object $record): string
    {
        return hash('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR));
    }

    public function index(Request $request, AiProviders $providers): View
    {
        $input = $request->validate(['status' => 'nullable|in:uncertain,age,all', 'edition_key' => 'nullable|regex:/^[a-f0-9]{64}$/', 'part' => 'nullable|integer|min:1', 'serial' => 'nullable|integer|min:1']);
        $query = DB::table('sir_records')->where('document_type', 'electoral_roll');
        if (($input['status'] ?? 'uncertain') === 'uncertain') {
            $query->where('extraction_status', 'ocr_uncertain');
        }
        if (($input['status'] ?? '') === 'age') {
            $query->where(fn ($row) => $row->whereNull('age')->orWhere('age', '<', 18))
                ->where(fn ($row) => $row->where('extraction_status', '!=', 'reviewed')->orWhereNotNull('field_notes'));
        }
        foreach (['edition_key', 'part', 'serial'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        $records = $query->orderBy('edition_key')->orderBy('part')->orderBy('serial')->paginate(15)->withQueryString();
        $editions = DB::table('sir_records')->where('document_type', 'electoral_roll')->select('edition_key', 'edition', 'ac_code')->distinct()->get();
        $reviews = DB::table('sir_extraction_reviews')->whereIn('record_id', $records->pluck('id'))->orderByDesc('id')->get()->groupBy('record_id');
        $settings = $providers->options()['openai'];

        return view('sir-review', compact('records', 'editions', 'reviews', 'settings'));
    }

    public function extract(Request $request, int $record, SirVisionExtractor $extractor): RedirectResponse
    {
        $input = $request->validate(['model' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/']]);
        $lock = Cache::lock('sir-vision-'.$record, 150);
        if (! $lock->get()) {
            return back()->withErrors(['vision' => 'This card is already being extracted. Wait for that request to finish.']);
        }
        try {
            $row = DB::table('sir_records')->where('document_type', 'electoral_roll')->find($record);
            abort_unless($row, 404);
            if (DB::table('sir_extraction_reviews')->where('record_id', $record)->where('status', 'pending')->exists()) {
                return back()->withErrors(['vision' => 'Review or reject the pending suggestion before requesting another extraction.']);
            }
            try {
                $result = $extractor->extract($row, $input['model'] ?? null);
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['vision' => $exception->getMessage()]);
            }
            $current = DB::table('sir_records')->find($record);
            abort_unless($current && self::fingerprint($current) === self::fingerprint($row), 409, 'Record changed during extraction. Reload before retrying.');
            DB::table('sir_extraction_reviews')->insert(['record_id' => $record, 'record_hash' => self::fingerprint($row), 'pdf_sha256' => $row->pdf_sha256, 'before_snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'suggestion' => json_encode($result['suggestion'], JSON_THROW_ON_ERROR), 'model' => $result['model'], 'response_id' => $result['response_id'], 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

            return back()->with('status', 'Vision suggestion saved. Compare with the original PDF and review before applying.');
        } finally {
            $lock->release();
        }
    }

    public function decide(Request $request, int $review): RedirectResponse
    {
        $input = $request->validate(['decision' => 'required|in:approve,reject']);
        $values = [];
        if ($input['decision'] === 'approve') {
            $values = $request->validate(['verified' => 'accepted', 'name' => 'required|string|max:255', 'relative_name' => 'required|string|max:255', 'relationship' => 'required|in:Father,Mother,Husband,Wife,Other', 'house_number' => 'nullable|string|max:255', 'age' => 'nullable|integer|between:0,120', 'gender' => 'nullable|string|max:100', 'section_number' => 'nullable|string|max:50', 'section_name' => 'nullable|string|max:255', 'ward_number' => 'nullable|string|max:50', 'elector_id' => 'nullable|string|max:100']);
            unset($values['verified']);
        }
        DB::transaction(function () use ($request, $review, $input, $values): void {
            $proposal = DB::table('sir_extraction_reviews')->where('id', $review)->lockForUpdate()->first();
            abort_unless($proposal && $proposal->status === 'pending', 409, 'This suggestion is no longer pending.');
            if ($input['decision'] === 'approve') {
                $record = DB::table('sir_records')->where('id', $proposal->record_id)->lockForUpdate()->first();
                abort_unless($record && self::fingerprint($record) === $proposal->record_hash, 409, 'Source row changed. This stale suggestion cannot be applied.');
                $name = SirNameSearch::latin($values['name']);
                $relative = SirNameSearch::latin($values['relative_name']);
                DB::table('sir_records')->where('id', $record->id)->update($values + ['name_latin' => $name, 'relative_name_latin' => $relative, 'name_latin_key' => SirNameSearch::key($name), 'relative_name_latin_key' => SirNameSearch::key($relative), 'extraction_status' => 'reviewed', 'serial_verified' => true, 'field_notes' => null, 'extraction_note' => 'Admin verified against original PDF.']);
                $key = 'sir-roll-meta:'.$record->edition_key;
                $meta = DB::table('site_settings')->where('key', $key)->lockForUpdate()->first();
                if ($meta) {
                    $details = json_decode($meta->value, true, 512, JSON_THROW_ON_ERROR);
                    $details['uncertain_records'] = DB::table('sir_records')->where('edition_key', $record->edition_key)->where('extraction_status', 'ocr_uncertain')->count();
                    DB::table('site_settings')->where('key', $key)->update(['value' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            }
            DB::table('sir_extraction_reviews')->where('id', $review)->update(['status' => $input['decision'] === 'approve' ? 'approved' : 'rejected', 'accepted_values' => $input['decision'] === 'approve' ? json_encode($values, JSON_THROW_ON_ERROR) : null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', $input['decision'] === 'approve' ? 'Verified correction applied; English aliases and filter summaries updated.' : 'Suggestion rejected; public records unchanged.');
    }
}
