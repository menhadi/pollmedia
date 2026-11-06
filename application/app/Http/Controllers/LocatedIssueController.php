<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\IssueLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LocatedIssueController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['area' => 'nullable|string|max:100']);
        $area = $data['area'] ?? null;
        $issues = DB::table('citizen_issues')->whereIn('status', ['open', 'in_progress', 'resolved'])
            ->when(! $area, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($area, fn ($q) => $q->whereIn('id', DB::table('citizen_issue_scopes')->where('area_key', $area)->select('issue_id')))
            ->orderByDesc('created_at')->paginate(20)->withQueryString();
        $areaName = $area ? DB::table('citizen_issue_scopes')->where('area_key', $area)->value('name') : null;

        if (! $area && $request->filled('place')) {
            $issues = app(CitizenIssueController::class)->index($request)->getData()['issues'];
        }

        return view($request->boolean('feed') ? 'issue-location-feed' : ($request->boolean('reports') ? 'issue-reports' : 'located-issues'), compact('issues', 'area', 'areaName'));
    }

    public function locate(Request $request, IssueLocationResolver $resolver): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'nullable|numeric|min:0|max:100000']);
        $locations = $resolver->resolve((float) $data['latitude'], (float) $data['longitude']);
        $choices = [];
        foreach ($locations as $location) {
            $token = (string) Str::uuid();
            $choices[$token] = ['location' => $location, 'coordinates' => $data, 'expires' => now()->addMinutes(30)->timestamp];
        }
        $request->session()->put('issue_locations', array_slice(array_merge($request->session()->get('issue_locations', []), $choices), -20, null, true));
        $request->session()->put('issue_detected_token', count($choices) === 1 ? array_key_first($choices) : null);

        return response()->json(['choices' => collect($choices)->map(fn ($v, $key) => ['token' => $key] + $v['location'])->values(), 'accuracy' => $data['accuracy'] ?? null])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'location_token' => 'required|uuid', 'location_confirmed' => 'accepted',
            'reporter_token' => 'nullable|uuid',
            'display_name' => 'nullable|string|max:60',
            'different_location_confirmed' => 'nullable|boolean',
            'category' => ['required', Rule::in(['water', 'roads', 'sanitation', 'electricity', 'health', 'education', 'revenue', 'agriculture', 'welfare', 'transport', 'environment', 'other'])],
            'title' => 'required|string|min:3|max:160', 'description' => 'nullable|string|max:5000',
            'media' => 'nullable|array|max:3', 'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm|max:2048',
            'consent' => 'accepted',
        ]);
        $selected = $request->session()->get('issue_locations.'.$data['location_token']);
        abort_unless($selected && $selected['expires'] >= now()->timestamp, 422, 'Location confirmation expired. Locate and confirm again.');
        $reporterToken = $request->session()->get('issue_detected_token') ?: ($data['reporter_token'] ?? null);
        $reporter = $reporterToken ? $request->session()->get('issue_locations.'.$reporterToken) : null;
        abort_if($reporterToken && (! $reporter || $reporter['expires'] < now()->timestamp || empty($reporter['coordinates'])), 422, 'Reporting location expired. Detect it again.');
        $originKey = $reporter['location']['identity_key'] ?? collect($reporter['location']['scopes'] ?? [])->firstWhere('kind', 'village')['key'] ?? null;
        $problemKey = $selected['location']['identity_key'] ?? collect($selected['location']['scopes'])->firstWhere('kind', 'village')['key'] ?? null;
        if ($originKey && $originKey !== $problemKey && empty($data['different_location_confirmed'])) {
            throw ValidationException::withMessages(['different_location_confirmed' => 'Your detected location and problem location differ. Confirm that both places may be displayed before proceeding.']);
        }
        if (trim($data['description'] ?? '') === '' && ! $request->hasFile('media')) {
            throw ValidationException::withMessages(['description' => 'Add text, a photo or a video about the issue.']);
        }
        $id = (string) Str::ulid();
        $files = [];
        try {
            foreach ($request->file('media', []) as $file) {
                $path = $file->store('issue-media/'.$id, 'local');
                abort_unless($path, 503, 'The upload could not be saved. Please retry.');
                $files[] = ['issue_id' => $id, 'path' => $path, 'mime' => $file->getMimeType()];
            }
            DB::transaction(function () use ($id, $data, $selected, $reporter, $files): void {
                DB::table('citizen_issues')->insert(['id' => $id, 'category' => $data['category'], 'title' => $data['title'], 'description' => $data['description'] ?? '', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                $snapshot = $selected['location'];
                $snapshot['public_display_name'] = trim($data['display_name'] ?? '');
                $snapshot['reported_from'] = $reporter['location'] ?? null;
                $snapshot['reported_from_status'] = $reporter ? 'device_location_confirmed_by_reporter' : 'not_available';
                DB::table('citizen_issue_locations')->insert(['issue_id' => $id, 'private_coordinates' => Crypt::encryptString(json_encode(['problem' => $selected['coordinates'] ?? null, 'reported_from' => $reporter['coordinates'] ?? null])), 'snapshot' => json_encode($snapshot)]);
                foreach ($selected['location']['scopes'] as $scope) {
                    DB::table('citizen_issue_scopes')->insert(['issue_id' => $id, 'area_key' => $scope['key'], 'name' => $scope['name'], 'kind' => $scope['kind']]);
                }
                if ($files) {
                    DB::table('citizen_issue_media')->insert($files);
                }
            });
        } catch (\Throwable $e) {
            foreach ($files as $file) {
                Storage::disk('local')->delete($file['path']);
            }
            throw $e;
        }
        $request->session()->forget('issue_locations');
        $request->session()->forget('issue_detected_token');

        return redirect()->route('issues.show', $id)->with('status', 'Published to your confirmed areas as a citizen report. You can now submit it on the government portal and add its reference here.');
    }

    public function media(string $issue, int $media): BinaryFileResponse
    {
        abort_unless(DB::table('citizen_issues')->where('id', $issue)->whereIn('status', ['open', 'in_progress', 'resolved'])->exists(), 404);
        $file = DB::table('citizen_issue_media')->where('id', $media)->where('issue_id', $issue)->first();
        abort_unless($file, 404);

        return response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function options(Request $request, ElectoralVillageMap $map): JsonResponse
    {
        $d = $request->validate(['state' => 'nullable|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/', 'level' => 'nullable|in:subdistrict,village', 'subdistrict' => ['nullable', 'regex:/^(?:[0-9]{1,8}|unknown)$/']]);
        $path = $map->directory().'/links.sqlite';
        abort_unless(is_file($path), 503, 'Location directory is unavailable.');
        $key = 'issue-options:'.hash('sha256', $path.filemtime($path).filesize($path).json_encode($d));
        $options = Cache::remember($key, 3600, function () use ($path, $d): array {
            $db = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA query_only=ON');
            if (empty($d['state'])) {
                return $db->query('SELECT code,name FROM states ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
            }
            if (empty($d['district'])) {
                $sql = 'SELECT a.district AS code,MIN(d.district) AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? GROUP BY a.district ORDER BY name';
                $params = [$d['state']];
            } elseif (($d['level'] ?? '') === 'subdistrict') {
                $query = $db->prepare("SELECT a.subdistrict AS code,MIN(d.subdistrict) AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? AND COALESCE(a.subdistrict,'')<>'' AND COALESCE(d.subdistrict,'')<>'' GROUP BY a.subdistrict ORDER BY name");
                $query->execute([$d['state'], $d['district']]);
                $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) {
                    $missing = $db->prepare("SELECT COUNT(*) FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? AND (COALESCE(a.subdistrict,'')='' OR COALESCE(d.subdistrict,'')='')");
                    $missing->execute([$d['state'], $d['district']]);
                    if ($missing->fetchColumn() > 0) {
                        $rows[] = ['code' => 'unknown', 'name' => 'Subdistrict unavailable'];
                    }
                }

                return $rows;
            } else {
                $sql = 'SELECT DISTINCT d.lgd AS code,d.name || \' · \' || d.subdistrict AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? ORDER BY name';
                $params = [$d['state'], $d['district']];
                if (! empty($d['subdistrict'])) {
                    $filter = $d['subdistrict'] === 'unknown' ? " AND (COALESCE(a.subdistrict,'')='' OR COALESCE(d.subdistrict,'')='')" : ' AND a.subdistrict=?';
                    $sql = str_replace(' ORDER BY name', $filter.' ORDER BY name', $sql);
                    if ($d['subdistrict'] !== 'unknown') {
                        $params[] = $d['subdistrict'];
                    }
                }
            }
            $query = $db->prepare($sql);
            $query->execute($params);

            return $query->fetchAll(\PDO::FETCH_ASSOC);
        });

        return response()->json(['options' => $options]);
    }

    public function selectProblem(Request $request, IssueLocationResolver $resolver): JsonResponse
    {
        $d = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'required|regex:/^[0-9]{1,8}$/']);
        $location = $resolver->village($d['village']);
        abort_unless($location['state'] === $d['state'] && $location['district'] === $d['district'], 422, 'Village does not belong to the selected state and district.');
        $token = (string) Str::uuid();
        $choices = $request->session()->get('issue_locations', []);
        $choices[$token] = ['location' => $location, 'coordinates' => null, 'expires' => now()->addMinutes(30)->timestamp];
        $request->session()->put('issue_locations', array_slice($choices, -20, null, true));

        return response()->json(['token' => $token] + $location)->header('Cache-Control', 'no-store');
    }
}
