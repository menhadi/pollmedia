<?php

namespace App\Http\Controllers;

use App\Services\ArchiveFiles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ByElectionResultController extends Controller
{
    public function index(Request $request): View
    {
        $disk = app(ArchiveFiles::class);
        $root = 'election-by-elections/structured/';
        $index = $disk->exists($root.'index.json') ? json_decode($disk->get($root.'index.json'), true, 512, JSON_THROW_ON_ERROR) : ['records' => [], 'unmapped_tables' => 0];
        $all = collect($index['records']);
        $years = $all->map(fn ($record) => (int) ($record['year'] ?? 0))->unique()->sortDesc()->values();
        $states = $all->pluck('state')->filter()->unique()->sort()->values();
        $input = $request->validate([
            'year' => ['nullable', 'integer', Rule::in($years->all())],
            'state' => ['nullable', Rule::in($states->all())], 'kind' => ['nullable', Rule::in(['ac', 'pc'])],
            'record' => ['nullable', 'regex:/^[a-f0-9]{24}$/'],
        ]);
        $year = (int) ($input['year'] ?? $years->first());
        $choices = $all->filter(fn ($record) => (int) ($record['year'] ?? 0) === $year
            && (! isset($input['state']) || $record['state'] === $input['state'])
            && (! isset($input['kind']) || $record['kind'] === $input['kind']))->values();
        $selected = isset($input['record']) ? $choices->firstWhere('id', $input['record']) : $choices->first();
        abort_if(isset($input['record']) && ! $selected, 404);
        $record = null;
        if ($selected) {
            abort_unless(preg_match('/^[a-f0-9]{24}-[a-f0-9]{16}\.json$/', $selected['file']), 503);
            $path = $disk->path($root.$selected['file']);
            abort_unless(is_file($path) && filesize($path) <= 4000000 && hash_equals($selected['sha256'], hash_file('sha256', $path)), 503, 'Result source integrity check failed.');
            $record = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return view('by-election-results', compact('all', 'years', 'states', 'year', 'input', 'choices', 'record'));
    }
}
