<?php

namespace App\Http\Controllers;

use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoricalExtractionController extends Controller
{
    public function show(Request $request, string $archive, ElectionArchive $service): View
    {
        $input = $request->validate(['code' => 'nullable|integer|min:1|max:999999']);
        [$data, $source] = app(HistoricalElectionArchive::class)->load($archive, $service);
        $reviews = app(HistoricalElectionReview::class);
        $data['records'] = array_map(fn (array $record): array => $reviews->apply($archive, $record, $data['source_sha256']), $data['records']);
        $data['review_count'] = count(array_filter($data['records'], fn (array $record): bool => $record['has_warning']));
        $selected = isset($input['code']) ? collect($data['records'])->firstWhere('code', (int) $input['code']) : null;
        abort_if(isset($input['code']) && ! $selected, 404);

        $history = $selected ? DB::table('historical_election_reviews')->where('archive', $archive)->where('code', $selected['code'])->latest('id')->get() : collect();

        return view('historical-extraction', compact('archive', 'data', 'source', 'selected', 'history'));
    }

    public function review(Request $request, string $archive, int $code, ElectionArchive $service, HistoricalElectionReview $reviews): RedirectResponse
    {
        $input = $request->validate([
            'action' => 'required|in:accept,correct', 'reason' => 'required|string|min:5|max:4000',
            'reference_url' => 'nullable|url:http,https|max:2000', 'fingerprint' => 'required|string|size:64', 'review_id' => 'required|integer|min:0',
            'name' => 'required_if:action,correct|string|max:250',
            'electors' => 'required_if:action,correct|integer|min:0|max:1000000000',
            'votes_polled' => 'required_if:action,correct|integer|min:0|max:1000000000',
            'valid_candidate_votes' => 'required_if:action,correct|integer|min:0|max:1000000000',
            'candidates' => 'required_if:action,correct|array|min:1|max:200',
            'candidates.*.candidate_name' => 'required|string|max:250', 'candidates.*.party_at_election' => 'required|string|max:100',
            'candidates.*.votes' => 'nullable|integer|min:0|max:1000000000',
            'candidates.*.general_votes' => 'nullable|integer|min:0|max:1000000000',
            'candidates.*.postal_votes' => 'nullable|integer|min:0|max:1000000000',
        ]);
        [$data] = app(HistoricalElectionArchive::class)->load($archive, $service);
        $original = collect($data['records'])->firstWhere('code', $code);
        abort_unless($original, 404);
        $reviews->save($archive, $original, $data['source_sha256'], $input, $request->user()->id);

        return redirect()->route('election-archives.extraction', [$archive, 'code' => $code])->with('status', 'Review saved. The displayed record has updated.');
    }
}
