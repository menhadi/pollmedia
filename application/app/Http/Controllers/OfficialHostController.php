<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficialHostController extends Controller
{
    public function index(): View
    {
        $hosts = DB::table('official_source_hosts')->orderBy('country_code')->orderBy('host')->get();

        return view('official-hosts', compact('hosts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['host' => strtolower(trim((string) $request->input('host')))]);
        $input = $request->validate([
            'host' => ['required', 'string', 'max:253', 'unique:official_source_hosts,host', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'country_code' => 'required|regex:/^[A-Z]{2}$/', 'publisher' => 'required|string|max:150',
            'verification_note' => 'required|string|min:20|max:2000', 'confirmed' => 'accepted',
        ]);
        if (collect(config('imports.official_host_suffixes', []))->contains(fn ($suffix) => $input['host'] === $suffix || str_ends_with($input['host'], '.'.$suffix))) {
            throw ValidationException::withMessages(['host' => 'This host is already covered by a configured host family.']);
        }
        DB::table('official_source_hosts')->insert([
            'host' => $input['host'], 'country_code' => $input['country_code'], 'publisher' => $input['publisher'],
            'verification_note' => $input['verification_note'], 'reviewed_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('official-hosts.index')->with('status', 'Official host registered. Only this exact host is enabled.');
    }

    public function toggle(Request $request, int $host): RedirectResponse
    {
        $input = $request->validate(['enabled' => 'required|boolean']);
        abort_unless(DB::table('official_source_hosts')->where('id', $host)->exists(), 404);
        DB::table('official_source_hosts')->where('id', $host)->update(['enabled' => $input['enabled'], 'updated_at' => now()]);

        return back()->with('status', 'Official host access updated.');
    }
}
