<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DataFeedbackController extends Controller
{
    public function create(Request $request): View
    {
        return view('data-feedback', ['path' => str_starts_with($request->string('path')->toString(), '/') && ! str_starts_with($request->string('path')->toString(), '//') ? $request->string('path')->toString() : '/']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['category' => 'required|in:data,source,navigation,accessibility,other', 'path' => ['required', 'string', 'max:2000', 'regex:~^/(?!/)[^\\\\<>\r\n]*$~'], 'details' => 'required|string|min:10|max:5000']);
        $id = (string) Str::ulid();
        DB::table('data_feedback')->insert($data + ['id' => $id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Report received. Reference: '.$id.'. An administrator will review it.');
    }

    public function index(): View
    {
        return view('feedback-queue', ['reports' => DB::table('data_feedback')->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('created_at')->paginate(30)]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:open,reviewing,resolved,declined', 'admin_note' => 'nullable|string|max:5000']);
        abort_unless(DB::table('data_feedback')->where('id', $id)->exists(), 404);
        DB::table('data_feedback')->where('id', $id)->update($data + ['updated_at' => now()]);

        return back()->with('status', 'Report updated.');
    }
}
