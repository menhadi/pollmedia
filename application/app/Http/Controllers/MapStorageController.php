<?php

namespace App\Http\Controllers;

use App\Jobs\QueueStorageCategory;
use App\Jobs\ScanMapAssets;
use App\Services\MapAssetStorage;
use App\Services\SiteSettings;
use App\Services\StorageUsageAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MapStorageController extends Controller
{
    public function index(Request $request, MapAssetStorage $storage): View
    {
        $input = $request->validate(['location' => 'nullable|in:local,cloud', 'category' => 'nullable|in:satellite,jjm,soil']);
        $files = isset($input['category']) ? $storage->categoryQuery($input['category']) : DB::table('map_storage_files');
        if (($input['location'] ?? null) === 'local') {
            $files->whereNull('profile_id');
        } elseif (($input['location'] ?? null) === 'cloud') {
            $files->whereNotNull('profile_id');
        }

        $categories = [];
        foreach (MapAssetStorage::CATEGORIES as $key => $label) {
            $categories[$key] = ['label' => $label, 'local' => $storage->categoryQuery($key)->whereNull('profile_id')->sum('bytes'),
                'cloud' => $storage->categoryQuery($key)->whereNotNull('profile_id')->sum('bytes'), 'count' => $storage->categoryQuery($key)->count()];
        }

        return view('map-storage', ['files' => $files->orderByDesc('bytes')->paginate(50)->withQueryString(),
            'categories' => $categories, 'audit' => app(StorageUsageAudit::class)->report(),
            'profiles' => DB::table('pdf_storage_profiles')->select('id', 'name', 'provider', 'bucket', 'region', 'endpoint', 'prefix', 'tested_at')->get(),
            'policy' => $storage->policy(), 'transfers' => DB::table('map_storage_transfers')->orderByDesc('id')->limit(30)->get(),
            'localBytes' => DB::table('map_storage_files')->whereNull('profile_id')->sum('bytes'),
            'cloudBytes' => DB::table('map_storage_files')->whereNotNull('profile_id')->sum('bytes'),
            'freeBytes' => disk_free_space(base_path())]);
    }

    public function save(Request $request, SiteSettings $settings): RedirectResponse
    {
        $input = $request->validate(['target' => 'required|integer|min:0', 'delivery' => 'required|in:stream,direct', 'cors_confirmed' => 'sometimes|accepted']);
        $target = $input['target'] ? (int) $input['target'] : null;
        if ($target && ! DB::table('pdf_storage_profiles')->where('id', $target)->whereNotNull('tested_at')->exists()) {
            throw ValidationException::withMessages(['target' => 'Save and test the bucket connection first.']);
        }
        if ($input['delivery'] === 'direct' && ! $request->boolean('cors_confirmed')) {
            throw ValidationException::withMessages(['delivery' => 'Confirm that the bucket allows browser GET requests from this website, or use website streaming.']);
        }
        $settings->save('satellite.storage', ['profile_id' => $target, 'delivery' => $input['delivery']], 'Map and satellite storage destination changed');

        return back()->with('status', 'Storage preference saved for new satellite publications. Existing files stay in place until you select Move.');
    }

    public function scan(): RedirectResponse
    {
        ScanMapAssets::dispatch();

        return back()->with('status', 'Disk audit and eligible file scan queued. Refresh to see the report. Scanning does not move files.');
    }

    public function categoryMove(Request $request): RedirectResponse
    {
        $input = $request->validate(['category' => 'required|in:satellite,jjm,soil', 'location' => 'required|in:local,cloud',
            'target' => 'required|integer|min:0', 'confirm_move' => 'required|accepted']);
        $target = $input['target'] ? (int) $input['target'] : null;
        if ($target && ! DB::table('pdf_storage_profiles')->where('id', $target)->whereNotNull('tested_at')->exists()) {
            throw ValidationException::withMessages(['target' => 'Save and test the destination bucket first.']);
        }
        QueueStorageCategory::dispatch($input['category'], $input['location'], $target, (int) DB::table('map_storage_files')->max('id'), $request->user()->id);

        return back()->with('status', 'Category move queued for currently registered files. New files added later are excluded. Originals are removed only after verification.');
    }

    public function download(int $file, MapAssetStorage $storage): Response
    {
        $record = DB::table('map_storage_files')->find($file);
        abort_unless($record && $storage->allowed($record->path), 404);
        $storage->assertLocalPath($record->path);
        $disk = $record->profile_id ? $storage->remote($record->profile_id) : $storage->local();
        $stream = $disk->readStream($record->profile_id ? $record->object_key : $record->path);
        abort_unless(is_resource($stream), 503);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, basename($record->path), ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function move(Request $request, MapAssetStorage $storage): RedirectResponse
    {
        $input = $request->validate(['files' => 'required|array|min:1|max:50', 'files.*' => 'integer|distinct|exists:map_storage_files,id', 'target' => 'required|integer|min:0']);
        try {
            foreach ($input['files'] as $id) {
                $storage->queue((int) $id, $input['target'] ? (int) $input['target'] : null, $request->user()->id);
            }
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['target' => $error->getMessage()]);
        }

        return back()->with('status', 'Moves queued. Each original is removed only after destination verification. Select the same file again to retry failed moves or pending cleanup.');
    }

    public function asset(Request $request, MapAssetStorage $storage): Response
    {
        $input = $request->validate(['path' => 'required|string|max:1024']);
        $path = 'public/'.ltrim($input['path'], '/');
        abort_unless(str_starts_with($path, 'public/maps/') && $storage->allowed($path), 404);
        $file = DB::table('map_storage_files')->where('path_hash', hash('sha256', $path))->first();
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = ['json' => 'application/json', 'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$extension];
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff'];
        if (! $file?->profile_id) {
            $storage->assertLocalPath($path);
            abort_unless($storage->local()->exists($path), 404);

            return response()->file($storage->local()->path($path), $headers);
        }
        $disk = $storage->remote($file->profile_id);
        if ($extension !== 'json' && $storage->policy()['delivery'] === 'direct') {
            return redirect()->away($disk->temporaryUrl($file->object_key, now()->addMinutes(10)))->withHeaders(['Cache-Control' => 'no-store']);
        }
        $stream = $disk->readStream($file->object_key);
        abort_unless(is_resource($stream), 503);

        return response()->stream(function () use ($stream): void {
            try {
                while (! feof($stream)) {
                    echo fread($stream, 1048576);
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
