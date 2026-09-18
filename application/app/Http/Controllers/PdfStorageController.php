<?php

namespace App\Http\Controllers;

use App\Jobs\ScanPdfInventory;
use App\Jobs\TransferPdf;
use App\Services\PdfStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdfStorageController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['location' => 'nullable|in:local,cloud']);
        $query = DB::table('pdf_storage_files');
        if (($input['location'] ?? null) === 'local') {
            $query->whereNull('profile_id');
        } elseif (($input['location'] ?? null) === 'cloud') {
            $query->whereNotNull('profile_id');
        }

        return view('pdf-storage', ['files' => $query->orderBy('id')->paginate(50)->withQueryString(),
            'profiles' => DB::table('pdf_storage_profiles')->select('id', 'name', 'provider', 'bucket', 'region', 'endpoint', 'prefix', 'tested_at')->get(),
            'transfers' => DB::table('pdf_storage_transfers')->orderByDesc('id')->limit(30)->get(),
            'localBytes' => DB::table('pdf_storage_files')->whereNull('profile_id')->sum('bytes'),
            'cloudBytes' => DB::table('pdf_storage_files')->whereNotNull('profile_id')->sum('bytes')]);
    }

    public function save(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => 'required|string|max:100', 'provider' => 'required|in:r2,s3',
            'bucket' => ['required', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'region' => ['required', 'regex:/^[a-z0-9-]{1,40}$/'], 'endpoint' => 'nullable|url:https|max:255',
            'prefix' => ['required', 'regex:/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*$/', 'max:100'],
            'access_key' => 'required|string|max:255', 'secret_key' => 'required|string|max:4096']);
        $endpoint = $input['endpoint'] ?? null;
        if ($input['provider'] === 'r2') {
            if (! $endpoint || ! preg_match('~^https://[a-f0-9]{32}(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com/?$~', $endpoint)) {
                throw ValidationException::withMessages(['endpoint' => 'Use the R2 S3 API endpoint shown in your Cloudflare account.']);
            }
            $input['region'] = 'auto';
        } elseif ($endpoint) {
            throw ValidationException::withMessages(['endpoint' => 'Leave the endpoint empty for Amazon S3. Select R2 for a Cloudflare endpoint.']);
        }
        DB::table('pdf_storage_profiles')->insert(['name' => $input['name'], 'provider' => $input['provider'], 'bucket' => $input['bucket'],
            'region' => $input['region'], 'endpoint' => $endpoint ? rtrim($endpoint, '/') : null, 'prefix' => $input['prefix'],
            'credentials' => Crypt::encryptString(json_encode(['key' => $input['access_key'], 'secret' => $input['secret_key']])), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Bucket profile saved. Test the connection before transferring PDFs.');
    }

    public function credentials(Request $request, int $profile): RedirectResponse
    {
        $input = $request->validate(['access_key' => 'required|string|max:255', 'secret_key' => 'required|string|max:4096']);
        abort_unless(DB::table('pdf_storage_profiles')->where('id', $profile)->exists(), 404);
        DB::table('pdf_storage_profiles')->where('id', $profile)->update(['credentials' => Crypt::encryptString(json_encode(['key' => $input['access_key'], 'secret' => $input['secret_key']])), 'tested_at' => null, 'updated_at' => now()]);

        return back()->with('status', 'Credentials replaced. Test the connection again.');
    }

    public function test(int $profile, PdfStorage $storage): RedirectResponse
    {
        try {
            $storage->test($profile);
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['storage' => 'Connection test failed. Check the endpoint, credentials, bucket and read/write/delete permissions.']);
        }

        return back()->with('status', 'Connection verified: test object written, read and removed.');
    }

    public function scan(): RedirectResponse
    {
        ScanPdfInventory::dispatch();
        Cache::put('pdf-inventory-status', 'Inventory scan queued. The PDF storage worker must be running.', 86400);

        return back()->with('status', 'Inventory scan queued. Refresh to see progress.');
    }

    public function move(Request $request): RedirectResponse
    {
        $input = $request->validate(['files' => 'required|array|min:1|max:50', 'files.*' => 'integer|distinct|exists:pdf_storage_files,id',
            'target' => 'required|integer|min:0', 'remove_source' => 'nullable|boolean']);
        $target = $input['target'] ? (int) $input['target'] : null;
        if ($target) {
            abort_unless(DB::table('pdf_storage_profiles')->where('id', $target)->whereNotNull('tested_at')->exists(), 422, 'Test the destination connection first.');
        }
        foreach ($input['files'] as $id) {
            Cache::lock('pdf-file-'.$id, 1800)->block(3, function () use ($id, $target, $input, $request): void {
                $active = DB::table('pdf_storage_transfers')->where('file_id', $id)->whereIn('status', ['queued', 'running'])->exists();
                if ($active) {
                    return;
                }
                $transfer = DB::table('pdf_storage_transfers')->insertGetId(['file_id' => $id, 'target_profile_id' => $target,
                    'remove_source' => (bool) ($input['remove_source'] ?? false), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                TransferPdf::dispatch($transfer);
            });
        }

        return back()->with('status', 'Transfers queued. Refresh this page to see progress.');
    }
}
