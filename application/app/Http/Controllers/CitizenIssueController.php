<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CitizenIssueController extends Controller
{
    private const CATEGORIES = ['water', 'roads', 'sanitation', 'electricity', 'health', 'education', 'other'];

    private const PUBLIC_STATUSES = ['open', 'in_progress', 'resolved'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'type' => ['nullable', 'string', 'max:80'],
            'place' => ['nullable', 'integer', 'exists:places,id'],
        ]);
        $country = strtoupper($filters['country'] ?? 'IN');
        $type = $filters['type'] ?? 'district';
        $countries = DB::table('places')->distinct()->orderBy('country_code')->pluck('country_code');
        $types = DB::table('places')->where('country_code', $country)->distinct()->orderBy('type')->pluck('type');
        if (! $types->contains($type)) {
            $type = $types->first() ?? 'district';
        }
        $places = DB::table('places')->where('country_code', $country)->where('type', $type)->orderBy('name')->get();
        $place = $filters['place'] ?? null;
        $issues = DB::table('citizen_issues')->whereIn('status', self::PUBLIC_STATUSES)
            ->whereIn('id', DB::table('citizen_issue_places')->whereIn('place_id', $places->pluck('id'))
                ->when($place, fn ($query) => $query->where('place_id', $place))->select('issue_id'))
            ->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('citizen-issues', compact('countries', 'types', 'country', 'type', 'places', 'place', 'issues') + ['categories' => self::CATEGORIES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'place_id' => ['required', 'integer', 'exists:places,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'title' => ['required', 'string', 'min:8', 'max:160'],
            'description' => ['required', 'string', 'min:30', 'max:5000'],
            'evidence_url' => ['nullable', 'url:https', 'max:2048'],
            'consent' => ['accepted'],
        ]);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($data, $id): void {
            DB::table('citizen_issues')->insert([
                'id' => $id, 'category' => $data['category'], 'title' => $data['title'],
                'description' => $data['description'], 'evidence_url' => $data['evidence_url'] ?? null,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('citizen_issue_places')->insert(['issue_id' => $id, 'place_id' => $data['place_id']]);
        });

        return redirect()->route('issues.index')->with('status', 'Report received. Reference: '.$id.'. It remains private until an administrator reviews it.');
    }

    public function show(string $issue): View
    {
        $record = DB::table('citizen_issues')->where('id', $issue)->whereIn('status', self::PUBLIC_STATUSES)->first();
        abort_unless($record, 404);

        return $this->detail($record, false);
    }

    public function reviewIndex(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'rejected', ...self::PUBLIC_STATUSES])]]);
        $status = $filters['status'] ?? 'pending';
        $issues = DB::table('citizen_issues')->where('status', $status)->orderBy('created_at')->paginate(20)->withQueryString();

        return view('citizen-issue-queue', compact('issues', 'status'));
    }

    public function review(string $issue): View
    {
        $record = DB::table('citizen_issues')->where('id', $issue)->first();
        abort_unless($record, 404);

        return $this->detail($record, true);
    }

    private function detail(object $record, bool $admin): View
    {
        $places = DB::table('places')->whereIn('id', DB::table('citizen_issue_places')->where('issue_id', $record->id)->select('place_id'))->get();
        $events = DB::table('citizen_issue_events')->where('issue_id', $record->id)
            ->when(! $admin, fn ($query) => $query->whereIn('to_status', self::PUBLIC_STATUSES))
            ->orderBy('id')->get();

        return view('citizen-issue-detail', compact('record', 'admin', 'places', 'events') + ['nextStatuses' => $this->nextStatuses($record->status)]);
    }

    private function nextStatuses(string $status): array
    {
        return match ($status) {
            'pending' => ['open', 'rejected'],
            'rejected' => ['open'],
            'open' => ['in_progress', 'resolved', 'rejected'],
            'in_progress' => ['open', 'resolved', 'rejected'],
            'resolved' => ['open', 'rejected'],
            default => [],
        };
    }

    public function moderate(Request $request, string $issue): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['rejected', ...self::PUBLIC_STATUSES])],
            'note' => ['required', 'string', 'min:15', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $issue, $data): void {
            $record = DB::table('citizen_issues')->where('id', $issue)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($record->revision === (int) $data['revision'], 409, 'This report changed. Reload before reviewing.');
            $allowed = $this->nextStatuses($record->status);
            abort_unless(in_array($data['status'], $allowed, true), 422, 'Invalid status transition.');
            DB::table('citizen_issues')->where('id', $issue)->update([
                'status' => $data['status'], 'revision' => $record->revision + 1, 'updated_at' => now(),
            ]);
            DB::table('citizen_issue_events')->insert([
                'issue_id' => $issue, 'reviewed_by' => $request->user()->id,
                'from_status' => $record->status, 'to_status' => $data['status'],
                'note' => $data['note'], 'created_at' => now(),
            ]);
        });

        return redirect()->route('issues.review', $issue)->with('status', 'Review saved. Public visibility has been updated.');
    }
}
