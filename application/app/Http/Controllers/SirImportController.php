<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SirImportController extends Controller
{
    public function index(): View
    {
        $batches = DB::table('sir_import_batches')->orderByDesc('id')->paginate(25);
        $coverage = DB::table('sir_records')->where('document_type', 'electoral_roll')->select('state_code', 'state_name', 'year')->selectRaw('COUNT(*) AS records_count, COUNT(DISTINCT ac_code) AS acs_count, COUNT(DISTINCT edition_key) AS editions_count')->groupBy('state_code', 'state_name', 'year')->orderBy('state_name')->orderBy('year')->get();

        return view('sir-imports', compact('batches', 'coverage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate(['records_file' => 'required|file|max:48828', 'pdf_file' => 'required|file|mimes:pdf|max:97656', 'sha256' => 'required|regex:/^[a-f0-9]{64}$/']);
        $json = $request->file('records_file')->getRealPath();
        $pdf = $request->file('pdf_file')->getRealPath();
        if (! hash_equals($input['sha256'], hash_file('sha256', $json))) {
            return back()->withErrors(['records_file' => 'The JSON checksum does not match.']);
        }
        try {
            $data = json_decode(file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['document_type'] ?? '') !== 'electoral_roll' || empty($data['records']) || ! preg_match('/^[a-f0-9]{64}$/', $data['edition_key'] ?? '') || ! empty($data['held_rows']) || count($data['records']) !== ($data['printed_electors'] ?? null)) {
                throw new \RuntimeException('Include every printed entry in an electoral-roll package; uncertain names must be included and flagged.');
            }
        } catch (\Throwable $exception) {
            return back()->withErrors(['records_file' => $exception->getMessage()]);
        }
        $lock = Cache::lock('sir-import:'.$data['edition_key'], 600);
        if (! $lock->get()) {
            return back()->withErrors(['records_file' => 'This edition is already being imported.']);
        }
        $folder = storage_path('app/private/sir-imports/'.Str::uuid());
        try {
            if (DB::table('sir_import_batches')->where('json_sha256', $input['sha256'])->where('status', 'imported')->exists()) {
                return back()->with('status', 'This exact package is already imported. Reviewed corrections are preserved.');
            }
            File::ensureDirectoryExists($folder);
            File::copy($json, $folder.'/records.json');
            File::copy($pdf, $folder.'/original.pdf');
            DB::table('sir_import_batches')->updateOrInsert(['json_sha256' => $input['sha256']], ['status' => 'importing', 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $result = Artisan::call('sir:import-records', ['file' => $folder.'/records.json', '--sha256' => $input['sha256'], '--pdf' => $folder.'/original.pdf']);
            DB::table('sir_import_batches')->where('json_sha256', $input['sha256'])->update(['status' => $result === 0 ? 'imported' : 'failed', 'message' => mb_substr(Artisan::output(), 0, 4000), 'updated_at' => now()]);
            if ($result !== 0) {
                return back()->withErrors(['records_file' => trim(Artisan::output())]);
            }
            DB::table('sir_import_batches')->where('json_sha256', $input['sha256'])->update(['edition_key' => $data['edition_key'], 'pdf_sha256' => $data['pdf_sha256'], 'state_name' => $data['state_name'] ?? $data['state_code'], 'year' => $data['year'] ?? null, 'ac_code' => $data['ac_code'], 'part' => $data['records'][0]['part'], 'records_count' => count($data['records'])]);

            return back()->with('status', count($data['records']).' entries imported and available on the public SIR page.');
        } finally {
            if (is_dir($folder)) {
                File::deleteDirectory($folder);
            }
            $lock->release();
        }
    }
}
