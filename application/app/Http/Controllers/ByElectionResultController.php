<?php

namespace App\Http\Controllers;

use App\Services\ByElectionRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ByElectionResultController extends Controller
{
    public function index(Request $request): View
    {
        $source = app(ByElectionRecords::class);
        $all = $source->index();
        $years = $all->map(fn ($record) => (int) ($record['year'] ?? 0))->unique()->sortDesc()->values();
        $states = $all->pluck('state')->filter()->unique()->sort()->values();
        $input = $request->validate([
            'year' => ['nullable', 'integer', Rule::in($years->all())],
            'state' => ['nullable', Rule::in($states->all())], 'kind' => ['nullable', Rule::in(['ac', 'pc'])],
            'record' => ['nullable', 'regex:/^[a-f0-9]{24}$/'],
            'format' => ['nullable', Rule::in(['report'])],
        ]);
        abort_if(isset($input['format']) && (! isset($input['record']) || ! array_key_exists('year', $input)), 404);
        $year = (int) ($input['year'] ?? $years->first());
        $choices = $all->filter(fn ($record) => (int) ($record['year'] ?? 0) === $year
            && (! isset($input['state']) || $record['state'] === $input['state'])
            && (! isset($input['kind']) || $record['kind'] === $input['kind']))->values();
        $selected = isset($input['record']) ? $choices->firstWhere('id', $input['record']) : $choices->first();
        abort_if(isset($input['record']) && ! $selected, 404);
        $record = $selected ? $source->record($selected) : null;

        if (isset($input['format'])) {
            abort_unless($record && $record['id'] === $selected['id'], 404);

            return view('by-election-result-report', compact('record', 'selected'));
        }

        return view('by-election-results', compact('all', 'years', 'states', 'year', 'input', 'choices', 'record'));
    }
}
