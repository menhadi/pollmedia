<?php

namespace App\Http\Controllers;

use App\Services\AiProviders;
use App\Services\AiSeoGenerator;
use App\Services\SeoPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SeoController extends Controller
{
    public function index(Request $request, SeoPages $seo): View
    {
        $input = $request->validate(['type' => 'nullable|in:village,ac,pc,district', 'year' => 'nullable|in:2001,2011', 'page' => 'nullable|integer|min:1']);
        $type = $input['type'] ?? 'ac';
        $year = $input['year'] ?? '2011';
        $catalog = $seo->catalog($type, $year)->values();
        $page = (int) ($input['page'] ?? 1);
        $pages = new LengthAwarePaginator($catalog->slice(($page - 1) * 50, 50), $catalog->count(), 50, $page, ['path' => route('seo.index'), 'query' => $request->query()]);
        $batches = DB::table('seo_batches')->orderByDesc('created_at')->limit(10)->get();
        $history = DB::table('seo_revisions')->orderByDesc('id')->paginate(20, ['*'], 'history_page');
        $current = DB::table('seo_metadata')->get()->keyBy('path');

        return view('seo-index', compact('pages', 'type', 'year', 'batches', 'history', 'current') + ['aiProviders' => app(AiProviders::class)->options()]);
    }

    public function create(Request $request, SeoPages $seo): RedirectResponse
    {
        $input = $request->validate(['type' => 'required|in:village,ac,pc,district', 'year' => 'required|in:2001,2011', 'paths' => 'required|array|min:1|max:50', 'paths.*' => 'required|string|distinct|max:255', 'generation' => 'nullable|in:template,ai', 'provider' => 'required_if:generation,ai|nullable|in:openai,deepseek,gemini,claude']);
        $catalog = $seo->catalog($input['type'], $input['year']);
        $items = [];
        foreach ($input['paths'] as $path) {
            abort_unless($catalog->has($path), 422, 'Select a page from the available catalog.');
            $item = $catalog[$path];
            $current = $seo->current($path);
            $item['base_revision'] = (int) ($current->revision_id ?? 0);
            $item['previous_title'] = $current->title ?? null;
            $item['previous_description'] = $current->description ?? null;
            $items[] = $item;
        }
        $generation = null;
        if (($input['generation'] ?? 'template') === 'ai') {
            if (count($items) > 10) {
                throw ValidationException::withMessages(['generation' => 'Select at most 10 pages per AI draft. Templates support 50.']);
            }
            $limiter = 'seo-ai:'.$request->user()->id;
            if (RateLimiter::tooManyAttempts($limiter, 10)) {
                throw ValidationException::withMessages(['generation' => 'AI generation is limited to 10 requests per hour per administrator.']);
            }
            $lock = Cache::lock($limiter.':running', 90);
            if (! $lock->get()) {
                throw ValidationException::withMessages(['generation' => 'An AI request is already running. Wait for its result.']);
            }
            try {
                RateLimiter::hit($limiter, 3600);
                $result = app(AiSeoGenerator::class)->generate($items, $input['provider']);
                $items = $result['items'];
                $generation = json_encode($result['generation'], JSON_THROW_ON_ERROR);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['generation' => $exception->getMessage()]);
            } finally {
                $lock->release();
            }
        }
        $id = (string) Str::ulid();
        DB::table('seo_batches')->insert(['id' => $id, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => now(), 'user_id' => $request->user()->id, 'ai_generation' => $generation]);

        return redirect()->route('seo.draft', $id);
    }

    public function draft(string $batch): View
    {
        $draft = DB::table('seo_batches')->find($batch);
        abort_unless($draft, 404);
        $items = json_decode($draft->items, true, 512, JSON_THROW_ON_ERROR);

        return view('seo-draft', compact('draft', 'items'));
    }

    public function save(Request $request, string $batch): RedirectResponse
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'items' => 'required|array|min:1|max:50',
            'items.*.title' => 'required|string|max:180', 'items.*.description' => 'required|string|max:500']);
        DB::transaction(function () use ($batch, $input): void {
            $draft = DB::table('seo_batches')->where('id', $batch)->lockForUpdate()->first();
            abort_unless($draft, 404);
            abort_if($draft->applied_at || $draft->version !== (int) $input['version'], 409, 'This draft has changed. Reload before editing.');
            $items = json_decode($draft->items, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(array_keys($items) === array_keys($input['items']), 422);
            foreach ($items as $index => &$item) {
                $item['title'] = $input['items'][$index]['title'];
                $item['description'] = $input['items'][$index]['description'];
            }
            DB::table('seo_batches')->where('id', $batch)->update(['items' => json_encode($items, JSON_THROW_ON_ERROR), 'version' => $draft->version + 1]);
        });

        return redirect()->route('seo.draft', $batch)->with('status', 'Edits saved. Review the saved previews before applying.');
    }

    public function apply(Request $request, string $batch, SeoPages $seo): RedirectResponse
    {
        $input = $request->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($batch, $input, $seo): void {
            $draft = DB::table('seo_batches')->where('id', $batch)->lockForUpdate()->first();
            abort_unless($draft, 404);
            abort_if($draft->applied_at || $draft->version !== (int) $input['version'], 409, 'This draft has changed or was already applied. Reload to review.');
            foreach (json_decode($draft->items, true, 512, JSON_THROW_ON_ERROR) as $item) {
                $seo->apply($item['path'], $item['title'], $item['description'], $item['base_revision'], 'Applied draft '.$batch);
            }
            DB::table('seo_batches')->where('id', $batch)->update(['applied_at' => now()]);
        });

        return redirect()->route('seo.draft', $batch)->with('status', 'Saved metadata applied. Each change is recorded in history.');
    }

    public function restore(Request $request, int $revision, SeoPages $seo): RedirectResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($revision, $input, $seo): void {
            $record = DB::table('seo_revisions')->find($revision);
            abort_unless($record, 404);
            $seo->apply($record->path, $record->before_title, $record->before_description, (int) $input['expected_revision'], 'Restored state before revision '.$revision);
        });

        return redirect()->route('seo.index')->with('status', 'Previous metadata restored. The restoration is recorded in history.');
    }
}
