<?php

namespace App\Http\Controllers;

use App\Services\ReportArchive;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportArchiveController extends Controller
{
    public function index(): View
    {
        $reports = DB::table('report_drafts')->orderByDesc('id')->paginate(12);

        return view('report-archive', compact('reports'));
    }

    public function store(Request $request, ReportController $reports, ReportArchive $archive): RedirectResponse
    {
        $input = $request->validate(['edition' => 'required|in:quarterly,annual']);
        $archive->save($reports->draft($input['edition']));

        return redirect()->route('reports.archive')->with('status', 'Dated draft saved. This copy will not change when source data changes.');
    }

    public function download(string $report): Response
    {
        $snapshot = DB::table('report_drafts')->where('id', $report)->first();
        abort_unless($snapshot && Storage::disk('local')->exists($snapshot->path), 404);
        $html = Storage::disk('local')->get($snapshot->path);
        abort_unless(hash_equals($snapshot->sha256, hash('sha256', $html)), 409, 'Archived file integrity check failed.');

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="pilibhit-'.$snapshot->edition.'-'.$snapshot->id.'-draft.html"')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
