<?php

namespace App\Http\Controllers;

use App\Jobs\SyncElectionReleasesJob;
use App\Services\DataCorrections;
use App\Services\ManagedTasks;
use App\Services\OfficialDownload;
use App\Services\SeoPages;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SiteManagementController extends Controller
{
    public function index(SiteSettings $settings, ManagedTasks $tasks): View
    {
        return view('site-management', ['appearance' => $settings->appearance(), 'tasks' => $tasks->all(), 'apis' => $this->apis(), 'apiSettings' => $settings->get('apis', []), 'health' => Schema::hasTable('task_health') ? DB::table('task_health')->get() : collect(), 'connectors' => DB::table('import_connectors')->orderBy('name')->get(), 'ready' => Schema::hasTable('site_settings'), 'changes' => Schema::hasTable('site_changes') ? DB::table('site_changes')->orderByDesc('id')->limit(20)->get() : collect()]);
    }

    private function apis(): array
    {
        $result = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $result[$route->uri()] = ['methods' => implode(', ', $route->methods()), 'handler' => match ($route->uri()) {
                    'api/sir' => 'Published electoral-roll context', 'api/census' => 'Published Pilibhit Census data', 'api/geography' => 'Published Survey of India geography', 'api/maps/pilibhit-villages' => 'Pilibhit village boundary GeoJSON', default => 'Public read-only data endpoint'
                }];
            }
        }

        return $result;
    }

    public function appearance(Request $request, SiteSettings $settings): RedirectResponse
    {
        $rules = ['name' => 'required|string|max:80', 'tagline' => 'required|string|max:180', 'footer_text' => 'required|string|max:1000', 'header_template' => 'required|in:standard,compact', 'footer_template' => 'required|in:columns,compact', 'css' => 'nullable|string|max:15000', 'links' => 'required|array|max:10', 'links.*.label' => 'required|string|max:50', 'links.*.url' => ['required', 'string', 'max:500', 'regex:~^/(?!/)[a-zA-Z0-9/_?=&%#.-]*$~']];
        foreach (['primary', 'background', 'surface', 'text', 'muted', 'border', 'accent'] as $color) {
            $rules[$color] = ['required', 'regex:/^#[a-fA-F0-9]{6}$/'];
        }
        foreach (['logo', 'favicon'] as $image) {
            $rules[$image] = ['nullable', 'string', 'max:500', 'regex:~^/(?!/)[a-zA-Z0-9/_-]+\.(png|jpg|jpeg|webp|ico)$~'];
        }
        foreach (['header_background', 'header_text'] as $color) {
            $rules[$color] = ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'];
        }
        $rules['contact_email'] = 'nullable|email|max:254';
        $rules['socials'] = 'sometimes|array:Facebook,X,Instagram,YouTube,LinkedIn';
        $rules['socials.*'] = ['nullable', 'url:https', 'max:500'];
        $rules['palette'] = 'nullable|array';
        foreach (config('site.palette', []) as $key => $value) {
            $rules['palette.'.$key] = ['nullable', 'regex:/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/'];
        }
        $data = $request->validate($rules);
        if (preg_match('/[<>@\\\\]|url\s*\(|expression|behavior|-moz-binding|image-set|https?:|src\s*\(/i', $data['css'] ?? '')) {
            throw ValidationException::withMessages(['css' => 'Use local CSS rules only; markup, escapes, imports and external resources are not allowed.']);
        }
        $data['palette'] = array_intersect_key($data['palette'] ?? [], config('site.palette', []));
        $settings->save('appearance', $data);

        return back()->with('status', 'Appearance saved.');
    }

    public function asset(Request $request, SiteSettings $settings): RedirectResponse
    {
        $request->validate(['kind' => 'required|in:logo,favicon', 'image' => 'required|file|mimes:png,jpg,jpeg,webp,ico|max:1024']);
        $file = $request->file('image');
        $extension = strtolower($file->extension());
        abort_unless(in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'ico'], true), 422);
        $name = Str::uuid().'.'.$extension;
        $file->move(public_path('brand-assets'), $name);
        $appearance = $settings->appearance();
        $appearance[$request->input('kind')] = '/brand-assets/'.$name;
        $settings->save('appearance', $appearance, 'Uploaded '.$request->input('kind'));

        return back()->with('status', 'Brand image saved.');
    }

    public function connector(Request $request, int $id, OfficialDownload $download): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:200', 'url' => 'required|url:https|max:2000', 'automatic' => 'required|boolean']);
        try {
            $download->validateUrl($data['url']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        DB::transaction(function () use ($id, $data) {
            $before = DB::table('import_connectors')->where('id', $id)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('site_changes')->insert(['target' => 'connector:'.$id, 'before_value' => json_encode(['name' => $before->name, 'url' => $before->url, 'automatic' => $before->automatic]), 'after_value' => json_encode($data), 'reason' => 'Connector configuration update', 'user_id' => auth()->id(), 'created_at' => now()]);
            DB::table('import_connectors')->where('id', $id)->update($data + ['updated_at' => now()]);
        });

        return back()->with('status', 'Connector saved. No fetch was performed.');
    }

    private function sourceRecord(Request $request): array
    {
        $input = $request->validate(['source' => 'required|regex:/^\d{4}-\d+-[a-f0-9]{16}$/', 'sheet' => 'required|integer|min:0|max:100', 'page' => 'required|integer|min:1|max:100000', 'row' => 'required|string|max:100', 'district' => 'nullable|regex:/^[a-f0-9]{16}$/']);
        $query = Request::create('/india/census/source-tables', 'GET', array_diff_key($input, ['row' => true]));
        $data = app(HistoricalCensusTableController::class)->index($query)->getData();
        $row = collect($data['rows'])->first(fn ($row) => (string) $row['source_row'] === (string) $input['row']);
        abort_unless($row, 404);

        return [$input, $row, $data];
    }

    public function sourceEditor(Request $request): View
    {
        [$input,$row,$data] = $this->sourceRecord($request);

        return view('source-correction', compact('input', 'row', 'data'));
    }

    public function sourceSave(Request $request): RedirectResponse
    {
        [$input,$row,$source] = $this->sourceRecord($request);
        $data = $request->validate(['cells' => 'required|json', 'reason' => 'required|string|min:10|max:2000', 'revision' => 'required|integer|min:0']);
        $cells = json_decode($data['cells'], true);
        abort_unless(is_array($cells) && array_is_list($cells) && count($cells) === count($row['cells']), 422, 'Keep the original number of cells.');
        foreach ($cells as $cell) {
            abort_unless($cell === null || is_scalar($cell), 422, 'Cells must be text, numbers, booleans or null.');
        }
        DB::transaction(function () use ($input, $row, $data, $cells) {
            $target = 'source:'.$input['source'].':'.$input['sheet'].':'.$input['row'];
            $lockKey = 'source-lock:'.hash('sha256', $target);
            DB::table('site_settings')->insertOrIgnore(['key' => $lockKey, 'value' => 'null', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('site_settings')->where('key', $lockKey)->lockForUpdate()->first();

            $current = DB::table('site_changes')->where('target', $target)->orderByDesc('id')->lockForUpdate()->first();
            abort_unless((int) ($current->id ?? 0) === (int) $data['revision'], 409, 'This row changed. Reload the editor.');
            DB::table('site_changes')->insert(['target' => $target, 'before_value' => json_encode($row['cells']), 'after_value' => json_encode($cells), 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
        });

        return back()->with('status', 'Source row correction saved. The original file and its checksum are unchanged.');
    }

    public function task(Request $request, string $key, SiteSettings $settings, ManagedTasks $tasks): RedirectResponse
    {
        abort_unless(isset($tasks->definitions()[$key]), 404);
        $data = $request->validate(['schedule' => ['required', Rule::in(array_keys(ManagedTasks::FREQUENCIES))], 'enabled' => 'required|boolean']);
        $data['enabled'] = (bool) $data['enabled'];
        $all = $settings->get('tasks', []);
        $all[$key] = $data;
        $settings->save('tasks', $all);

        return back()->with('status', 'Schedule saved for the next scheduler tick. Active jobs are not interrupted.');
    }

    public function syncElections(): RedirectResponse
    {
        SyncElectionReleasesJob::dispatch()->onConnection('election_sync')->onQueue('election-sync');

        return back()->with('status', 'Election release check queued. The result will appear under monitoring after the dedicated worker runs.');
    }

    public function api(Request $request, SiteSettings $settings): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', Rule::in(array_keys($this->apis()))], 'enabled' => 'required|boolean', 'limit' => 'required|integer|min:1|max:10000']);
        $all = $settings->get('apis', []);
        $all[$data['path']] = ['enabled' => (bool) $data['enabled'], 'limit' => (int) $data['limit']];
        $settings->save('apis', $all);

        return back()->with('status', 'API control saved.');
    }

    public function seo(Request $request, SeoPages $seo): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1000', 'regex:~^/(?!/)[a-zA-Z0-9/_?=&%#.-]*$~'], 'title' => 'required|string|max:160', 'description' => 'required|string|max:320', 'revision' => 'required|integer|min:0']);
        DB::transaction(fn () => $seo->apply($data['path'], $data['title'], $data['description'], (int) $data['revision'], 'manual'));

        return back()->with('status', 'Page metadata saved.');
    }

    public function editor(Request $request, DataCorrections $corrections): View
    {
        $table = $request->string('table', 'census_catalogue_rows')->toString();
        abort_unless(isset(DataCorrections::FIELDS[$table]), 404);
        $id = $request->integer('id');
        $record = $id ? $corrections->record($table, $id) : null;
        $search = $request->validate(['q' => 'nullable|string|max:100'])['q'] ?? '';
        $nameColumn = in_array('name', DataCorrections::FIELDS[$table], true) ? 'name' : (in_array('candidate_name', DataCorrections::FIELDS[$table], true) ? 'candidate_name' : null);
        $records = DB::table($table)->when($search !== '' && $nameColumn, fn ($q) => $q->where($nameColumn, 'like', '%'.$search.'%'))->orderByDesc('id')->paginate(25)->withQueryString();

        return view('data-editor', ['table' => $table, 'record' => $record, 'records' => $records, 'fields' => DataCorrections::FIELDS[$table], 'history' => $id ? DB::table('site_changes')->where('target', $table.':'.$id)->orderByDesc('id')->get() : collect()]);
    }

    public function correct(Request $request, DataCorrections $corrections): RedirectResponse
    {
        $data = $request->validate(['table' => ['required', Rule::in(array_keys(DataCorrections::FIELDS))], 'id' => 'required|integer|min:1', 'expected' => 'required|string|size:64', 'reason' => 'required|string|min:10|max:2000', 'fields' => 'required|array']);
        $record = $corrections->record($data['table'], (int) $data['id']);
        $rules = [];
        foreach (DataCorrections::FIELDS[$data['table']] as $field) {
            $rules['fields.'.$field] = match ($field) {
                'values' => 'required|json', 'name','candidate_name','party_at_election' => 'required|string|max:255', 'status' => 'required|in:accepted,pending,reviewed,rejected', default => 'nullable|numeric|min:0'
            };
        }
        $fields = $request->validate($rules)['fields'];
        if (isset($fields['values'])) {
            $decoded = json_decode($fields['values'], true);
            $original = json_decode($record->values, true);
            abort_unless(is_array($decoded) && array_keys($decoded) === array_keys($original), 422, 'Retain the existing source field names.');
            foreach ($decoded as $value) {
                abort_unless($value === null || is_numeric($value), 422, 'Census measures must be numeric or null.');
            }
        }
        $corrections->save($data['table'], (int) $data['id'], $fields, $data['expected'], $data['reason']);

        return back()->with('status', 'Correction saved with its previous value and reason. Original source files are unchanged.');
    }
}
