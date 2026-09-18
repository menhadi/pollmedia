<?php

namespace App\Http\Controllers;

use App\Services\ArchiveFiles;
use App\Services\OfficialDownload;
use App\Services\OfficialImport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): View
    {
        $connectors = DB::table('import_connectors')->orderByDesc('id')->get();
        foreach ($connectors as $connector) {
            $connector->latest = DB::table('import_runs')->where('import_connector_id', $connector->id)->orderByDesc('id')->first(['id', 'status', 'created_at']);
            $connector->pending = DB::table('import_runs')->where('import_connector_id', $connector->id)->where('status', 'needs_review')->count();
        }
        $runs = DB::table('import_runs as r')->join('import_connectors as c', 'c.id', '=', 'r.import_connector_id')->select('r.id', 'r.status', 'r.created_at', 'c.name')->orderByDesc('r.id')->paginate(20);
        $identifiers = DB::table('place_identifiers')->select('namespace', 'version')->distinct()->get()->map(fn ($row) => $row->namespace.'|'.$row->version);

        return view('imports-index', compact('connectors', 'runs', 'identifiers'));
    }

    public function store(Request $request, OfficialDownload $download): RedirectResponse
    {
        $identifiers = DB::table('place_identifiers')->select('namespace', 'version')->distinct()->get()->map(fn ($row) => $row->namespace.'|'.$row->version)->all();
        $input = $request->validate(['name' => 'required|string|max:150', 'url' => 'required|url|max:3000', 'format' => 'required|in:json,csv,xls,xlsx,pdf',
            'record_key' => 'required|string|max:150', 'sheet' => 'nullable|string|max:100', 'json_path' => 'nullable|regex:/^[a-zA-Z0-9_.-]+$/|max:150',
            'header_row' => 'required|integer|min:1|max:100', 'table_index' => 'required|integer|min:1|max:20',
            'filter_column' => 'nullable|required_with:filter_value|string|max:150', 'filter_value' => 'nullable|required_with:filter_column|string|max:150',
            'identifier' => ['nullable', Rule::in($identifiers)], 'automatic' => 'nullable|boolean']);
        try {
            $download->validateUrl($input['url']);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['url' => $error->getMessage()]);
        }
        DB::table('import_connectors')->insert(['name' => $input['name'], 'url' => $input['url'], 'format' => $input['format'], 'record_key' => $input['record_key'],
            'options' => json_encode(collect($input)->only(['sheet', 'json_path', 'header_row', 'table_index', 'identifier', 'filter_column', 'filter_value'])->all()),
            'automatic' => $request->boolean('automatic'), 'next_check_at' => now(), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('imports.index')->with('status', 'Source configured. Fetch it now or let its automatic daily check run.');
    }

    public function fetch(int $connector, OfficialImport $importer): RedirectResponse
    {
        abort_unless(DB::table('import_connectors')->where('id', $connector)->exists(), 404);
        try {
            $id = $importer->run($connector);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['source' => $error->getMessage()]);
        }

        return redirect()->route('imports.run', $id);
    }

    public function upload(Request $request, int $connector, OfficialImport $importer): RedirectResponse
    {
        $source = DB::table('import_connectors')->find($connector);
        abort_unless($source, 404);
        $request->validate(['file' => ['required', 'file', 'max:19531', 'extensions:'.$source->format]]);
        try {
            $id = $importer->run($connector, $request->file('file')->getRealPath());
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['source' => $error->getMessage()]);
        }

        return redirect()->route('imports.run', $id);
    }

    public function schedule(Request $request, int $connector): RedirectResponse
    {
        $input = $request->validate(['automatic' => 'required|boolean']);
        abort_unless(DB::table('import_connectors')->where('id', $connector)->exists(), 404);
        DB::table('import_connectors')->where('id', $connector)->update(['automatic' => (bool) $input['automatic'], 'next_check_at' => now(), 'updated_at' => now()]);

        return redirect()->route('imports.index')->with('status', 'Automatic checking preference saved.');
    }

    public function show(Request $request, int $run): View
    {
        $record = DB::table('import_runs')->find($run);
        abort_unless($record, 404);
        $connector = DB::table('import_connectors')->find($record->import_connector_id);
        $data = json_decode($record->extracted ?? 'null', true);
        $summary = json_decode($record->summary ?? '{}', true);
        $input = $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) ($input['page'] ?? 1);
        $rows = new LengthAwarePaginator(array_slice($data['rows'] ?? [], ($page - 1) * 50, 50), count($data['rows'] ?? []), 50, $page, ['path' => route('imports.run', $run)]);

        return view('import-run', compact('record', 'connector', 'data', 'summary', 'rows'));
    }

    public function review(Request $request, int $run): RedirectResponse
    {
        $input = $request->validate(['decision' => 'required|in:accept,reject']);
        DB::transaction(function () use ($run, $input, $request): void {
            $record = DB::table('import_runs')->where('id', $run)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($record->status === 'needs_review', 409);
            $connector = DB::table('import_connectors')->where('id', $record->import_connector_id)->lockForUpdate()->first();
            if ($input['decision'] === 'accept') {
                $summary = json_decode($record->summary, true);
                abort_if(! $summary['key_column_present'] || $summary['missing_keys'] || $summary['duplicate_keys'], 422, 'Missing or duplicate record codes must be resolved before accepting.');
                abort_unless($record->base_run_id === $connector->accepted_run_id, 409, 'The reviewed baseline changed. Fetch the source again to recompute its differences.');
                DB::table('import_connectors')->where('id', $connector->id)->update(['accepted_run_id' => $run, 'updated_at' => now()]);
            }
            DB::table('import_runs')->where('id', $run)->update(['status' => $input['decision'] === 'accept' ? 'accepted' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return redirect()->route('imports.run', $run)->with('status', 'Review saved. This is an import baseline; public pages require a dataset-specific publication mapping.');
    }

    public function download(int $run): StreamedResponse
    {
        $record = DB::table('import_runs')->find($run);
        abort_unless($record && $record->raw_path && app(ArchiveFiles::class)->exists($record->raw_path), 404);

        return app(ArchiveFiles::class)->download($record->raw_path, 'official-source-'.$record->id.'.'.pathinfo($record->raw_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff']);
    }
}
