<?php

namespace App\Http\Controllers;

use App\Services\SourceChangeMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AuthorityReviewController extends Controller
{
    public function index(): View
    {
        $sources = DB::table('data_sources')->whereIn('key', array_keys(config('source-monitor.sources', [])))->orderBy('key')->get();
        foreach ($sources as $source) {
            $source->latest = DB::table('source_checks')->where('data_source_id', $source->id)->orderByDesc('id')->first();
            $source->successful = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', '!=', 'failed')->orderByDesc('id')->first();
            $source->baseline = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', 'baseline')->orderBy('id')->first();
            $source->tables = json_decode($source->successful->content ?? '[]', true) ?: [];
            $source->baselineTables = json_decode($source->baseline->content ?? '[]', true) ?: [];
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
}
