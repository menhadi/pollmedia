<?php

namespace App\Http\Controllers;

use App\Services\AiProviders;
use App\Services\SirRecordCorrection;
use App\Services\SirVisionExtractor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $providerOptions = array_intersect_key($providers->options(), array_flip(['deepseek', 'openai']));
        $settings = $providerOptions['openai'];

        return view('sir-review', compact('records', 'editions', 'reviews', 'settings', 'providerOptions'));
    }

    public function extract(Request $request, int $record, SirVisionExtractor $extractor): RedirectResponse
    {
        $input = $request->validate(['provider' => 'nullable|in:openai,deepseek', 'page_image' => 'nullable|file|image|mimetypes:image/png,image/jpeg,image/webp|max:7812', 'model' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/']]);
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
                $result = $extractor->extract($row, $input['model'] ?? null, $input['provider'] ?? 'openai', $request->file('page_image'));
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['vision' => $exception->getMessage()]);
            }
            $current = DB::table('sir_records')->find($record);
            abort_unless($current && self::fingerprint($current) === self::fingerprint($row), 409, 'Record changed during extraction. Reload before retrying.');
            DB::table('sir_extraction_reviews')->insert(['record_id' => $record, 'record_hash' => self::fingerprint($row), 'pdf_sha256' => $row->pdf_sha256, 'before_snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'suggestion' => json_encode($result['suggestion'], JSON_THROW_ON_ERROR), 'provider' => $result['provider'], 'image_sha256' => $result['image_sha256'] ?? null, 'image_mime' => $result['image_mime'] ?? null, 'image_source' => $result['image_source'] ?? null, 'model' => $result['model'], 'response_id' => $result['response_id'], 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

            return back()->with('status', 'Vision suggestion saved. Compare with the original PDF and review before applying.');
        } finally {
            $lock->release();
        }
    }

    public function image(int $review): BinaryFileResponse
    {
        $proposal = DB::table('sir_extraction_reviews')->find($review);
        abort_unless($proposal && preg_match('/^[a-f0-9]{64}$/', $proposal->image_sha256 ?? '') && in_array($proposal->image_mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);
        $path = storage_path('app/private/sir-vision-images/'.$proposal->image_sha256.'.image');
        abort_unless(is_file($path) && hash_equals($proposal->image_sha256, hash_file('sha256', $path)), 404);

        return response()->file($path, ['Content-Type' => $proposal->image_mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function decide(Request $request, int $review, SirRecordCorrection $correction): RedirectResponse
    {
        $input = $request->validate(['decision' => 'required|in:approve,reject']);
        $values = [];
        if ($input['decision'] === 'approve') {
            $values = $request->validate(SirRecordCorrection::rules());
            unset($values['verified']);
        }
        DB::transaction(function () use ($request, $review, $input, $values, $correction): void {
            $proposal = DB::table('sir_extraction_reviews')->where('id', $review)->lockForUpdate()->first();
            abort_unless($proposal && $proposal->status === 'pending', 409, 'This suggestion is no longer pending.');
            if ($input['decision'] === 'approve') {
                $record = DB::table('sir_records')->where('id', $proposal->record_id)->lockForUpdate()->first();
                abort_unless($record && self::fingerprint($record) === $proposal->record_hash, 409, 'Source row changed. This stale suggestion cannot be applied.');
                $correction->apply($record, $values);
            }
            DB::table('sir_extraction_reviews')->where('id', $review)->update(['status' => $input['decision'] === 'approve' ? 'approved' : 'rejected', 'accepted_values' => $input['decision'] === 'approve' ? json_encode($values, JSON_THROW_ON_ERROR) : null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', $input['decision'] === 'approve' ? 'Verified correction applied; English aliases and filter summaries updated.' : 'Suggestion rejected; public records unchanged.');
    }
}
