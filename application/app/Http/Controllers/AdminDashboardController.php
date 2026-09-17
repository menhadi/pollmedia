<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $counts = [
            'Place profiles' => DB::table('places')->count(),
            'Registered sources' => DB::table('data_sources')->count(),
            'Imports awaiting review' => DB::table('import_runs')->where('status', 'needs_review')->count(),
        ];
        $latest = DB::table('source_checks')->selectRaw('MAX(id) as id')->groupBy('data_source_id');
        $checks = DB::table('data_sources as s')
            ->leftJoin('source_checks as c', function ($join) use ($latest): void {
                $join->on('s.id', '=', 'c.data_source_id')->whereIn('c.id', $latest);
            })
            ->whereIn('s.key', array_keys(config('source-monitor.sources', [])))
            ->select('s.key as name', 's.url', 'c.status', 'c.checked_at')->orderBy('s.key')->get();
        $runs = DB::table('import_runs as r')->join('import_connectors as c', 'c.id', '=', 'r.import_connector_id')
            ->select('r.id', 'r.status', 'r.created_at', 'c.name')->orderByDesc('r.id')->limit(5)->get();

        return view('admin-dashboard', compact('counts', 'checks', 'runs'));
    }
}
