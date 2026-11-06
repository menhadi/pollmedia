<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IssueParticipationController extends Controller
{
    private function published(string $issue): void
    {
        abort_unless(DB::table('citizen_issues')->where('id', $issue)->whereIn('status', ['open', 'in_progress', 'resolved'])->exists(), 404);
    }

    public function support(Request $request, string $issue): RedirectResponse
    {
        $this->published($issue);
        $request->validate(['support' => 'required_without:vote|boolean', 'vote' => 'nullable|integer|in:-1,0,1']);
        $direction = $request->has('vote') ? (int) $request->input('vote') : ($request->boolean('support') ? 1 : 0);
        if (! $request->session()->has('issue_participant')) {
            $request->session()->put('issue_participant', (string) Str::uuid());
        }
        $key = hash_hmac('sha256', $request->session()->get('issue_participant'), config('app.key'));
        if ($direction !== 0) {
            DB::table('citizen_issue_supports')->upsert([['issue_id' => $issue, 'participant_hash' => $key, 'direction' => $direction, 'created_at' => now(), 'updated_at' => now()]], ['issue_id', 'participant_hash'], ['direction', 'updated_at']);
        } else {
            DB::table('citizen_issue_supports')->where('issue_id', $issue)->where('participant_hash', $key)->delete();
        }

        return back()->with('status', 'Your reaction has been saved.');
    }

    public function contribute(Request $request, string $issue): RedirectResponse
    {
        $this->published($issue);
        $d = $request->validate(['kind' => ['required', Rule::in(['evidence', 'update', 'official_update', 'flag'])], 'body' => 'required|string|min:20|max:3000', 'evidence_url' => 'nullable|url:https|max:2048', 'portal' => ['nullable', 'required_if:kind,official_update', Rule::in(array_keys(config('grievance_portals')))], 'reference' => 'nullable|required_if:kind,official_update|string|max:160', 'official_status' => 'nullable|required_if:kind,official_update|string|max:120', 'observed_on' => 'nullable|required_if:kind,official_update|date_format:Y-m-d|before_or_equal:today', 'consent' => 'accepted']);
        DB::table('citizen_issue_contributions')->insert(['issue_id' => $issue, 'kind' => $d['kind'], 'body' => $d['body'], 'evidence_url' => $d['evidence_url'] ?? null, 'portal' => $d['kind'] === 'official_update' ? $d['portal'] : null, 'private_reference' => $d['kind'] === 'official_update' ? Crypt::encryptString($d['reference']) : null, 'official_status' => $d['kind'] === 'official_update' ? $d['official_status'] : null, 'observed_on' => $d['kind'] === 'official_update' ? $d['observed_on'] : null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Contribution received for review. Government references remain private.');
    }

    public function review(Request $request, string $issue, int $contribution): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['published', 'rejected'])], 'verified' => 'nullable|accepted']);
        DB::transaction(function () use ($issue, $contribution, $d, $request) {
            $row = DB::table('citizen_issue_contributions')->where('id', $contribution)->where('issue_id', $issue)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->kind === 'flag' && $d['decision'] === 'published', 422, 'Flags remain private.');
            abort_if($row->kind === 'official_update' && $d['decision'] === 'published' && empty($d['verified']), 422, 'Confirm the official record before publishing.');
            DB::table('citizen_issue_contributions')->where('id', $contribution)->update(['status' => $d['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Contribution review saved. The community issue status is unchanged.');
    }
}
