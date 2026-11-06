<?php

namespace App\Http\Controllers;

use App\Services\AgriExampleReports;
use App\Services\FarmerAi;
use App\Services\FarmerEvidence;
use App\Services\FarmerGovernmentAdvisory;
use App\Services\FarmerLanguages;
use App\Services\FarmerReports;
use App\Services\MyPlaceDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FarmerAssessmentController extends Controller
{
    public function index(Request $request, AgriExampleReports $examples): View
    {
        $initialPlace = $request->validate(['state' => 'nullable|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/']);
        if (! empty($initialPlace['state']) && ! empty($initialPlace['district'])) {
            $context = app(MyPlaceDirectory::class)->context($initialPlace['state'], $initialPlace['district'], $initialPlace['village'] ?? null);
            $initialPlace['subdistrict'] = $context['village']['subdistrict_code'] ?? null;
        }

        return view('farmer-assistant', ['initialPlace' => $initialPlace ?: null, 'languages' => FarmerLanguages::ALL, 'labels' => app(FarmerLanguages::class)->labels(app()->getLocale() === 'hi' ? 'hi' : 'en'), 'examples' => $examples->catalogue()]);
    }

    public function example(): RedirectResponse
    {
        return redirect()->route('farmers.examples.show', ['report' => 'field-planning', 'language' => 'en'], 301);
    }

    public function exampleReport(string $report, string $language, AgriExampleReports $examples): View
    {
        return view('agri-example-report', $examples->find($report, $language));
    }

    public function exampleDownload(string $report, string $language, AgriExampleReports $examples): BinaryFileResponse
    {
        $file = $examples->pdf($report, $language);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function language(string $language, FarmerLanguages $languages): JsonResponse
    {
        abort_unless(isset(FarmerLanguages::ALL[$language]), 404);
        try {
            return response()->json(['labels' => $languages->labels($language)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function draft(Request $request, FarmerReports $reports): JsonResponse
    {
        $input = $request->validate(['language' => ['required', Rule::in(array_keys(FarmerLanguages::ALL))], 'purpose' => 'required|in:crop_check,field_plan,unsure',
            'story' => 'nullable|string|max:4000', 'location_token' => 'required|uuid', 'location_confirmed' => 'accepted', 'consent' => 'accepted',
            'photos' => 'required|array|min:1|max:3', 'photos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', 'soil_report' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $location = $request->session()->get('issue_locations.'.$input['location_token']);
        abort_unless($location && $location['expires'] >= now()->timestamp, 422, 'Confirm the field location again.');
        $id = (string) Str::ulid();
        $photos = [];
        try {
            foreach ($request->file('photos', []) as $file) {
                $photos[] = $reports->photo($file, $id);
            }
            if ($request->hasFile('soil_report')) {
                $photos[] = $reports->photo($request->file('soil_report'), $id, 'soil_report');
            }
            $data = ['language' => $input['language'], 'purpose' => $input['purpose'], 'story' => $input['story'] ?? '', 'location' => $location['location'], 'coordinates' => $location['coordinates'] ?? null, 'photos' => $photos, 'consent_at' => now()->toIso8601String(), 'status' => 'draft', 'suggestions' => null, 'followups' => []];
            DB::table('farmer_assessments')->insert(['id' => $id, 'owner_hash' => $reports->owner($request), 'private_payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'expires_at' => now()->addDays(config('farmer.retention_days', 30)), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Storage::disk('local')->delete($p['path']);
            } throw $e;
        }

        return response()->json(['id' => $id, 'url' => route('farmers.show', $id), 'suggest_url' => route('farmers.suggest', $id), 'report_url' => route('farmers.generate', $id)])->header('Cache-Control', 'private, no-store');
    }

    public function suggest(string $assessment, Request $request, FarmerReports $reports, FarmerAi $ai): JsonResponse
    {
        $found = $reports->find($assessment, $request);
        $data = $found['data'];
        if ($data['suggestions'] !== null) {
            return response()->json(['suggestions' => $data['suggestions']])->header('Cache-Control', 'no-store');
        }
        try {
            $data['suggestions'] = $ai->suggest(['language' => FarmerLanguages::ALL[$data['language']][0], 'purpose' => $data['purpose'], 'farmer_words' => $data['story'], 'locality' => $this->locality($data['location']), 'assessment_date' => now()->toDateString(), 'soil_report_photo_included' => collect($data['photos'])->contains('kind', 'soil_report')], $data['photos']);
            $reports->save($assessment, $data);

            return response()->json(['suggestions' => $data['suggestions']])->header('Cache-Control', 'no-store');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'manual_review_available' => true], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function generate(string $assessment, Request $request, FarmerReports $reports, FarmerEvidence $evidence, FarmerAi $ai): JsonResponse
    {
        $rules = array_fill_keys(['crop', 'stage', 'age', 'concern', 'irrigation', 'field_state', 'area'], 'nullable|string|max:200');
        $confirmed = $request->validate($rules + ['answers' => 'nullable|string|max:2000', 'confirmed' => 'accepted']);
        unset($confirmed['confirmed']);
        $reports->find($assessment, $request);

        return Cache::lock('farmer-generate:'.$assessment, 150)->block(2, function () use ($assessment, $request, $reports, $evidence, $ai, $confirmed): JsonResponse {
            $data = $reports->find($assessment, $request)['data'];
            if (isset($data['report']) && ($data['confirmed'] ?? null) === $confirmed && ! $request->boolean('refresh_advisories')) {
                return response()->json(['url' => route('farmers.show', $assessment)]);
            }
            $data['confirmed'] = $confirmed;
            $reports->save($assessment, $data);
            try {
                $data['evidence'] = $evidence->collect($data['location'], $data['coordinates']);
                $government = app(FarmerGovernmentAdvisory::class)->collect($data['location']);
                $data['evidence'] = array_values(array_filter($data['evidence'], fn ($e) => ! (($e['kind'] ?? '') === 'government_guidance' && str_contains($e['note'] ?? '', 'No current advisory'))));
                $data['evidence'][] = $government;
                $data['report'] = $ai->report(['language' => FarmerLanguages::ALL[$data['language']][0], 'purpose' => $data['purpose'], 'confirmed_details' => $confirmed, 'farmer_words' => $data['story'], 'unconfirmed_photo_observations' => $data['suggestions']['observations'] ?? [], 'uncertainty' => $data['suggestions']['uncertainty'] ?? 'Photos have not been assessed. No visual findings may be asserted.', 'locality' => $this->locality($data['location']), 'evidence' => $data['evidence'], 'assessment_date' => now()->toDateString()]);
                $data['government'] = in_array($government['status'], ['available', 'reference_only'], true) ? $ai->government($government, FarmerLanguages::ALL[$data['language']][0], $confirmed) : null;
                $data['labels'] = app(FarmerLanguages::class)->labels($data['language']);
                $data['status'] = 'reported';
                $data['report_created_at'] = now()->toIso8601String();
                $reports->save($assessment, $data);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 503)->header('Cache-Control', 'no-store');
            }

            return response()->json(['url' => route('farmers.show', $assessment)])->header('Cache-Control', 'no-store');
        });
    }

    public function show(string $assessment, Request $request, FarmerReports $reports): View
    {
        $found = $reports->find($assessment, $request);
        if (! isset($found['data']['report'])) {
            return view('farmer-assistant', ['languages' => FarmerLanguages::ALL, 'labels' => FarmerLanguages::LABELS, 'draft' => ['id' => $assessment, 'url' => route('farmers.show', $assessment), 'suggest_url' => route('farmers.suggest', $assessment), 'report_url' => route('farmers.generate', $assessment), 'data' => $found['data']]]);
        }

        return view('farmer-report', ['id' => $assessment, 'data' => $found['data'], 'labels' => $found['data']['labels'], 'language' => $found['data']['language'], 'expires' => $found['record']->expires_at]);
    }

    public function media(string $assessment, int $photo, Request $request, FarmerReports $reports): BinaryFileResponse
    {
        $data = $reports->find($assessment, $request)['data'];
        $file = $data['photos'][$photo] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return response()->file(Storage::disk('local')->path($file['path']), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function followup(string $assessment, Request $request, FarmerReports $reports): RedirectResponse
    {
        $input = $request->validate(['observation' => 'required|string|max:2000']);
        $data = $reports->find($assessment, $request)['data'];
        abort_if(count($data['followups']) >= 20, 422, 'Follow-up limit reached.');
        $data['followups'][] = ['at' => now()->toIso8601String(), 'observation' => $input['observation']];
        $reports->save($assessment, $data);

        return redirect()->route('farmers.show', $assessment);
    }

    public function destroy(string $assessment, Request $request, FarmerReports $reports): RedirectResponse
    {
        $data = $reports->find($assessment, $request)['data'];
        foreach ($data['photos'] as $photo) {
            Storage::disk('local')->delete($photo['path']);
        }
        DB::table('farmer_assessments')->where('id', $assessment)->where('owner_hash', $reports->owner($request))->delete();

        return redirect()->route('farmers.index');
    }

    private function locality(array $location): array
    {
        return ['name' => $location['name'], 'areas' => collect($location['scopes'] ?? [])->whereIn('kind', ['village', 'district', 'state'])->map(fn ($s) => ['kind' => $s['kind'], 'name' => $s['name']])->values()->all()];
    }
}
