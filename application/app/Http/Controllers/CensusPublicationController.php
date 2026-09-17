<?php

namespace App\Http\Controllers;

use App\Services\CensusPublication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CensusPublicationController extends Controller
{
    public function show(int $run, CensusPublication $service): View
    {
        $preview = $service->preview($run);
        $history = DB::table('import_publications')->orderByDesc('id')->get();

        return view('census-publication', compact('preview', 'history'));
    }

    public function publish(Request $request, int $run, CensusPublication $service): RedirectResponse
    {
        $input = $request->validate(['base_release_id' => 'required|integer']);
        $service->publish($run, (int) $input['base_release_id'], $request->user()->id);

        return redirect()->route('imports.census', $run)->with('status', 'Census village figures published. The previous edition remains in publication history.');
    }

    public function restore(Request $request, int $publication, CensusPublication $service): RedirectResponse
    {
        $input = $request->validate(['base_release_id' => 'required|integer']);
        $service->restore($publication, (int) $input['base_release_id'], $request->user()->id);
        $run = DB::table('import_publications')->where('id', $publication)->value('import_run_id');

        return redirect()->route('imports.census', $run)->with('status', 'Previous Census figures restored. Both publication and rollback remain in history.');
    }
}
