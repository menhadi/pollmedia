<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PollingSourceController extends Controller
{
    public function index(Request $request): View
    {
        $disk = Storage::disk('local');
        $root = 'polling-station-sources/';
        $index = $disk->exists($root.'index.json') ? json_decode($disk->get($root.'index.json'), true, 512, JSON_THROW_ON_ERROR) : ['states' => [], 'sources' => []];
        $states = collect($index['states'])->sortBy('state')->values();
        $all = collect($index['sources']);
        $input = $request->validate(['state' => ['nullable', Rule::in($states->pluck('state')->all())], 'source' => ['nullable', 'regex:/^[a-f0-9]{24}$/'], 'page' => ['nullable', 'integer', 'min:1']]);
        $choices = $all->filter(fn ($source) => ! isset($input['state']) || $source['state'] === $input['state'])->values();
        $source = isset($input['source']) ? $choices->firstWhere('id', $input['source']) : ($choices->first(fn ($entry) => count($entry['pages']) > 0) ?? $choices->first());
        abort_if(isset($input['source']) && ! $source, 404);
        $page = (int) ($input['page'] ?? 1);
        $data = null;
        if ($source && $source['pages']) {
            $metadata = collect($source['pages'])->firstWhere('page', $page);
            abort_unless($metadata && preg_match('/^[a-f0-9]{24}$/', $source['folder']) && preg_match('/^[a-f0-9]{64}$/', $source['sha256']) && preg_match('/^\d+(?:-[a-f0-9]{16})?\.json$/', $metadata['file']), 404);
            $path = $disk->path($root.$source['folder'].'/'.$source['sha256'].'-tables/'.$metadata['file']);
            abort_unless(is_file($path) && filesize($path) <= 16000000 && hash_equals($metadata['sha256'], hash_file('sha256', $path)), 503, 'Extracted page integrity check failed.');
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return view('polling-source-tables', compact('index', 'states', 'all', 'choices', 'source', 'input', 'page', 'data'));
    }
}
