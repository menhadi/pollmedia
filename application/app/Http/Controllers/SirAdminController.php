<?php

namespace App\Http\Controllers;

use App\Services\SirCorrectionStatus;
use App\Services\SirNameSearch;
use App\Services\SirRecordCorrection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SirAdminController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['year' => 'nullable|integer|between:1800,2100', 'state_code' => 'nullable|string|max:10', 'pc_code' => 'nullable|string|max:10', 'ac_code' => 'nullable|string|max:10', 'part' => 'nullable|integer|min:1', 'serial' => 'nullable|integer|min:1', 'name' => 'nullable|string|max:255', 'status' => 'nullable|in:all,correction,pending', 'per_page' => 'nullable|in:50,100,500']);
        $query = DB::table('sir_records')->where('document_type', 'electoral_roll');
        foreach (['year', 'state_code', 'pc_code', 'ac_code', 'part', 'serial'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $query->where($field, $input[$field]);
            }
        }
        if (! empty($input['name'])) {
            $pattern = SirNameSearch::pattern($input['name']);
            $latin = SirNameSearch::pattern(SirNameSearch::latin($input['name']));
            $query->where(fn ($row) => $row->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(relative_name) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("name_latin LIKE ? ESCAPE '!'", [$latin])->orWhereRaw("relative_name_latin LIKE ? ESCAPE '!'", [$latin]));
        }
        if (($input['status'] ?? '') === 'correction') {
            SirCorrectionStatus::filter($query);
        }
        if (($input['status'] ?? '') === 'pending') {
            $query->whereIn('id', DB::table('sir_extraction_reviews')->select('record_id')->where('status', 'pending'));
        }
        $records = $query->orderBy('year')->orderBy('state_code')->orderBy('ac_code')->orderBy('part')->orderBy('serial')->paginate((int) ($input['per_page'] ?? 50))->withQueryString();
        $scope = DB::table('sir_records')->where('document_type', 'electoral_roll');
        $years = (clone $scope)->whereNotNull('year')->distinct()->orderByDesc('year')->pluck('year');
        $states = (clone $scope)->select('state_code', 'state_name')->distinct()->orderBy('state_name')->get();
        if (! empty($input['year'])) {
            $scope->where('year', $input['year']);
        }
        if (! empty($input['state_code'])) {
            $scope->where('state_code', $input['state_code']);
        }
        $pcs = (clone $scope)->whereNotNull('pc_source_url')->select('pc_code', 'pc_name')->distinct()->orderBy('pc_name')->get();
        if (! empty($input['pc_code'])) {
            $scope->where('pc_code', $input['pc_code']);
        }
        $acs = (clone $scope)->select('ac_code', 'ac_name')->distinct()->orderBy('ac_name')->get();
        if (! empty($input['ac_code'])) {
            $scope->where('ac_code', $input['ac_code']);
        }
        $parts = (clone $scope)->select('part', 'station')->distinct()->orderBy('part')->get();

        return view('sir-admin', compact('records', 'years', 'states', 'pcs', 'acs', 'parts'));
    }

    public function edit(int $record): View
    {
        $row = DB::table('sir_records')->where('document_type', 'electoral_roll')->find($record);
        abort_unless($row, 404);
        $history = DB::table('sir_extraction_reviews')->where('record_id', $record)->orderByDesc('id')->get();

        return view('sir-admin-edit', ['record' => $row, 'history' => $history]);
    }

    public function update(Request $request, int $record, SirRecordCorrection $correction): RedirectResponse
    {
        $input = $request->validate(SirRecordCorrection::rules() + ['record_hash' => 'required|regex:/^[a-f0-9]{64}$/', 'keep_flagged' => 'nullable|boolean', 'note' => 'nullable|required_if:keep_flagged,1|string|max:2000']);
        DB::transaction(function () use ($request, $record, $input, $correction): void {
            $row = DB::table('sir_records')->where('document_type', 'electoral_roll')->where('id', $record)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->pdf_sha256, 409, 'Attach the original PDF before verifying corrections.');
            abort_unless(hash_equals(SirReviewController::fingerprint($row), $input['record_hash']), 409, 'Record changed. Reload before saving.');
            $values = array_intersect_key($input, SirRecordCorrection::rules());
            unset($values['verified']);
            $note = ! empty($input['keep_flagged']) ? $input['note'] : null;
            $correction->apply($row, $values, $note);
            DB::table('sir_extraction_reviews')->where('record_id', $record)->where('status', 'pending')->update(['status' => 'superseded', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            DB::table('sir_extraction_reviews')->insert(['record_id' => $record, 'record_hash' => $input['record_hash'], 'pdf_sha256' => $row->pdf_sha256, 'before_snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'suggestion' => json_encode($values, JSON_THROW_ON_ERROR), 'accepted_values' => json_encode($values + ['field_notes' => $note], JSON_THROW_ON_ERROR), 'provider' => 'manual', 'model' => 'administrator', 'status' => $note ? 'approved_flagged' : 'approved', 'requested_by' => $request->user()->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Correction saved with review history. English search aliases updated.');
    }
}
