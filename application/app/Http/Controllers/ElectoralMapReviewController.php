<?php

namespace App\Http\Controllers;

use App\Services\ElectoralMapCatalogue;
use App\Services\SiteSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ElectoralMapReviewController extends Controller
{
    public function index(Request $request, ElectoralMapCatalogue $maps, SiteSettings $settings): View
    {
        $input = $request->validate(['state' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac', 'page' => 'nullable|integer|min:1']);
        $catalogue = $maps->all();
        $slug = $input['state'] ?? 'uttar-pradesh';
        abort_unless(isset($catalogue['states'][$slug]), 404);
        $state = $catalogue['states'][$slug];
        $data = json_decode(file_get_contents(public_path('maps/electoral/'.$state['file'])), true, flags: JSON_THROW_ON_ERROR);
        $rows = collect($data['features'])->filter(fn ($f) => ! empty($f['properties']['review_notes']) && (! isset($input['kind']) || $f['properties']['kind'] === $input['kind']))->values();
        $page = (int) ($input['page'] ?? 1);
        $reviews = $settings->get('boundary_reviews', []);
        $items = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('electoral-map-review', compact('catalogue', 'state', 'items', 'reviews'));
    }

    public function save(Request $request, ElectoralMapCatalogue $maps, SiteSettings $settings): RedirectResponse
    {
        $input = $request->validate(['state' => 'required|string|max:100', 'feature' => ['required', 'regex:/^(pc|ac)-[0-9]+$/'],
            'hash' => 'required|regex:/^[a-f0-9]{64}$/', 'reason' => 'required|string|min:10|max:2000',
            'evidence_url' => 'nullable|url:https|max:2000']);
        $state = $maps->all()['states'][$input['state']] ?? null;
        abort_unless($state, 404);
        abort_unless(hash_equals($state['sha256'], $input['hash']), 409, 'The source layer changed. Reload before recording a review.');
        $data = json_decode(file_get_contents(public_path('maps/electoral/'.$state['file'])), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(collect($data['features'])->contains(fn ($f) => $f['id'] === $input['feature']), 404);
        $reviews = $settings->get('boundary_reviews', []);
        $reviews[$input['hash'].':'.$input['feature']] = ['status' => 'acknowledged', 'note' => $input['reason'],
            'evidence_url' => $input['evidence_url'] ?? null, 'user_id' => $request->user()->id, 'at' => now()->toIso8601String()];
        $settings->save('boundary_reviews', $reviews, $input['reason']);

        return back()->with('status', 'Review recorded. Original geometry and source flags remain preserved.');
    }
}
