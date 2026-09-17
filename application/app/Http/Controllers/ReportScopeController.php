<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportScopeController extends Controller
{
    public function index(): View
    {
        $scopes = DB::table('report_scopes')->orderBy('label')->get();
        $countries = DB::table('places')->select('country_code')->distinct()->orderBy('country_code')->pluck('country_code');
        $namespaces = DB::table('place_identifiers as i')->join('places as p', 'p.id', '=', 'i.place_id')
            ->join('source_releases as r', 'r.id', '=', 'i.source_release_id')->where('r.status', 'accepted')
            ->select('p.country_code', 'i.namespace')->distinct()->orderBy('p.country_code')->orderBy('i.namespace')->get();

        return view('report-scopes', compact('scopes', 'countries', 'namespaces'));
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'key' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:report_scopes,key',
            'label' => 'required|string|max:150', 'country_code' => 'required|regex:/^[A-Z]{2}$/|exists:places,country_code',
            'timezone' => 'required|timezone', 'selection' => 'required|in:country,identifiers',
            'namespaces' => 'required_if:selection,identifiers|array|max:30', 'namespaces.*' => 'required|string|max:255|distinct',
            'automatic' => 'nullable|boolean',
        ]);
        $namespaces = $input['selection'] === 'identifiers' ? $input['namespaces'] : [];
        foreach ($namespaces as $namespace) {
            $exists = DB::table('place_identifiers as i')->join('places as p', 'p.id', '=', 'i.place_id')
                ->join('source_releases as r', 'r.id', '=', 'i.source_release_id')->where('r.status', 'accepted')
                ->where('p.country_code', $input['country_code'])->where('i.namespace', $namespace)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['namespaces' => 'Choose identifiers backed by accepted sources in the selected country.']);
            }
        }
        DB::table('report_scopes')->insert([
            'key' => $input['key'], 'label' => $input['label'], 'country_code' => $input['country_code'],
            'timezone' => $input['timezone'], 'selection' => $input['selection'], 'namespaces' => json_encode($namespaces),
            'adapter' => 'coverage', 'automatic' => $request->boolean('automatic'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('report-scopes.index')->with('status', 'Report area configured. Coverage uses recorded data and accepted identifiers.');
    }

    public function schedule(Request $request, string $key): RedirectResponse
    {
        $request->validate(['automatic' => 'required|boolean']);
        abort_unless(DB::table('report_scopes')->where('key', $key)->exists(), 404);
        DB::table('report_scopes')->where('key', $key)->update(['automatic' => $request->boolean('automatic'), 'updated_at' => now()]);

        return back()->with('status', 'Report scheduling updated.');
    }
}
