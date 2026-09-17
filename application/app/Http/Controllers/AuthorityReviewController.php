<?php

namespace App\Http\Controllers;

use App\Services\OfficeholderDirectory;
use App\Services\SourceChangeMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthorityReviewController extends Controller
{
    public function history(Request $request): View
    {
        $input = $request->validate(['office' => 'nullable|integer|exists:offices,id']);
        $office = $input['office'] ?? null;
        $offices = DB::table('offices')->orderBy('title')->orderBy('key')->get();
        $history = DB::table('office_assignments as a')->join('offices as o', 'o.id', '=', 'a.office_id')
            ->leftJoin('people as p', 'p.id', '=', 'a.person_id')
            ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->leftJoin('authority_reviews as review', 'review.office_assignment_id', '=', 'a.id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'review.reviewed_by')
            ->when($office, fn ($query) => $query->where('a.office_id', $office))
            ->select('a.*', 'o.title', 'o.key as office_key', 'p.display_name', 'r.url', 'r.sha256',
                'review.note', 'review.created_at as reviewed_at', 'reviewer.name as reviewer_name')
            ->orderByDesc('a.verified_at')->orderByDesc('a.id')->paginate(20)->withQueryString();

        return view('authority-history', compact('history', 'offices', 'office'));
    }

    public function index(): View
    {
        $sources = DB::table('data_sources')->whereIn('key', array_keys(config('source-monitor.sources', [])))->orderBy('key')->get();
        foreach ($sources as $source) {
            $source->latest = DB::table('source_checks')->where('data_source_id', $source->id)->orderByDesc('id')->first();
            $source->successful = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', '!=', 'failed')->orderByDesc('id')->first();
            $source->baseline = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', 'baseline')->orderBy('id')->first();
            $source->tables = json_decode($source->successful->content ?? '[]', true) ?: [];
            $source->baselineTables = json_decode($source->baseline->content ?? '[]', true) ?: [];
            $source->offices = DB::table('office_assignments as a')->join('offices as o', 'o.id', '=', 'a.office_id')
                ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')->whereNull('a.superseded_at')
                ->where('r.data_source_id', $source->id)->select('o.id', 'o.title', 'a.id as assignment_id')->get();
            foreach ($source->offices as $office) {
                $office->places = DB::table('office_jurisdictions as j')->join('places as p', 'p.id', '=', 'j.place_id')
                    ->where('j.office_id', $office->id)->whereNull('j.valid_to')
                    ->select('p.name', 'p.type')->distinct()->get();
            }
        }
        $assignments = DB::table('office_assignments as a')->join('offices as o', 'o.id', '=', 'a.office_id')
            ->leftJoin('people as p', 'p.id', '=', 'a.person_id')->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->whereNull('a.superseded_at')->select('o.title', 'p.display_name', 'a.status', 'a.verified_at', 'a.effective_from', 'r.url')
            ->orderBy('o.title')->get();

        return view('authority-review', compact('sources', 'assignments'));
    }

    public function check(string $key, SourceChangeMonitor $monitor): RedirectResponse
    {
        $url = config('source-monitor.sources', [])[$key] ?? null;
        abort_unless($url && DB::table('data_sources')->where('key', $key)->where('url', $url)->exists(), 404);
        $status = $monitor->check($key, $url);

        return redirect()->route('authorities.index')->with('status', $status === 'failed'
            ? 'The official directory could not be checked. Published officeholders and prior evidence are retained.'
            : 'Directory checked. Review the captured evidence below; published officeholders have not been changed.');
    }

    public function replace(Request $request, OfficeholderDirectory $directory): RedirectResponse
    {
        $input = $request->validate([
            'check_id' => 'required|integer', 'office_id' => 'required|integer',
            'assignment_id' => 'required|integer', 'name' => 'required|string|max:200',
            'note' => 'required|string|min:10|max:2000', 'confirmed' => 'accepted',
        ]);
        try {
            DB::transaction(function () use ($input, $request, $directory): void {
                $check = DB::table('source_checks')->where('id', $input['check_id'])->first();
                abort_unless($check && in_array($check->status, ['baseline', 'changed', 'unchanged']), 422, 'A successful directory check is required.');
                $source = DB::table('data_sources')->where('id', $check->data_source_id)->lockForUpdate()->first();
                abort_unless((config('source-monitor.sources', [])[$source->key] ?? null) === $source->url, 422);
                abort_unless(DB::table('source_checks')->where('data_source_id', $source->id)->max('id') === $check->id, 409, 'Reopen the latest source evidence before publishing.');
                abort_unless(hash_equals($check->sha256 ?? '', hash('sha256', $check->content ?? '')), 422, 'Snapshot fingerprint does not match.');
                $name = trim($input['name']);
                $cells = collect(json_decode($check->content, true))->flatten();
                abort_unless($cells->contains(fn ($cell) => is_string($cell) && trim($cell) === $name), 422, 'Use the exact name from a captured directory cell.');
                $active = DB::table('office_assignments')->where('office_id', $input['office_id'])->whereNull('superseded_at')->lockForUpdate()->get();
                abort_unless($active->count() === 1 && $active->first()->id === (int) $input['assignment_id'], 409, 'The office assignment has changed; reload the review.');
                $old = $active->first();
                abort_unless(DB::table('source_releases')->where('id', $old->source_release_id)->where('data_source_id', $source->id)->exists(), 422, 'This source is not linked to the selected office.');
                abort_if(DB::table('authority_reviews')->where('source_check_id', $check->id)->where('office_id', $input['office_id'])->exists(), 409);
                $person = DB::table('people')->where('id', $old->person_id)->first();
                $personId = $person && $person->display_name === $name ? $person->id
                    : DB::table('people')->insertGetId(['key' => 'review-'.Str::ulid(), 'display_name' => $name]);
                $version = 'directory-check-'.$check->id;
                $release = DB::table('source_releases')->where('data_source_id', $source->id)->where('version_key', $version)->value('id');
                $release ??= DB::table('source_releases')->insertGetId([
                    'data_source_id' => $source->id, 'version_key' => $version, 'sha256' => $check->sha256,
                    'url' => $source->url, 'retrieved_at' => $check->checked_at, 'status' => 'accepted',
                    'payload' => $check->content, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $assignment = $directory->replace((int) $input['office_id'], $personId, $release, $check->checked_at);
                DB::table('authority_reviews')->insert([
                    'source_check_id' => $check->id, 'office_id' => $input['office_id'], 'office_assignment_id' => $assignment,
                    'reviewed_by' => $request->user()->id, 'note' => $input['note'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['office_id' => $error->getMessage()]);
        }

        return redirect()->route('authorities.index')->with('status', 'Reviewed officeholder published. Prior assignments are retained; no appointment date was inferred.');
    }
}
