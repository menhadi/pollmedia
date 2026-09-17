<?php

namespace App\Http\Controllers;

use App\Services\CensusCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CensusCatalogueController extends Controller
{
    public function index(Request $request): View
    {
        return $this->browse($request, false);
    }

    public function review(Request $request): View
    {
        return $this->browse($request, true);
    }

    private function browse(Request $request, bool $admin): View
    {
        $input = $request->validate(['edition' => 'nullable|integer', 'state' => 'nullable|regex:/^\d{2}$/', 'district' => 'nullable|regex:/^\d{2,3}$/', 'level' => 'nullable|string|max:30', 'residence' => 'nullable|in:Total,Rural,Urban', 'field' => 'nullable|string|max:100']);
        $editions = DB::table('census_editions')->when(! $admin, fn ($q) => $q->whereIn('status', ['published', 'superseded']))->orderByDesc('year')->orderByDesc('id')->get();
        $edition = isset($input['edition']) ? $editions->firstWhere('id', (int) $input['edition']) : $editions->first();
        abort_if(isset($input['edition']) && ! $edition, 404);
        $fields = $edition ? json_decode($edition->fields, true) : [];
        $field = $input['field'] ?? 'TOT_P';
        abort_if($edition && ! in_array($field, $fields, true), 422, 'This field is not available in the selected edition.');
        $base = DB::table('census_catalogue_rows')->where('edition_id', $edition?->id ?? 0);
        $states = (clone $base)->where('level', 'STATE')->select('state_code', 'name')->distinct()->orderBy('name')->get();
        $base->when($input['state'] ?? null, fn ($q, $value) => $q->where('state_code', $value));
        $districts = (clone $base)->where('level', 'DISTRICT')->select('district_code', 'name')->distinct()->orderBy('name')->get();
        $base->when($input['district'] ?? null, fn ($q, $value) => $q->where('district_code', $value));
        $levels = (clone $base)->distinct()->orderBy('level')->pluck('level');
        $residences = (clone $base)->distinct()->orderBy('residence')->pluck('residence');
        $rows = $base->when($input['level'] ?? null, fn ($q, $value) => $q->where('level', $value))
            ->when($input['residence'] ?? null, fn ($q, $value) => $q->where('residence', $value))
            ->orderBy('state_code')->orderBy('district_code')->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();
        $runs = $admin ? DB::table('import_runs as r')->join('import_connectors as c', 'c.id', '=', 'r.import_connector_id')
            ->whereIn('r.status', ['needs_review', 'accepted'])->whereIn('r.source_url', collect(config('census-sources'))->where('archive_only', '!=', true)->pluck('url'))
            ->select('r.id', 'r.status', 'c.name')->orderByDesc('r.id')->get() : collect();
        $current = $edition ? (DB::table('census_editions')->where('source_key', $edition->source_key)->where('status', 'published')->value('id') ?? 0) : 0;

        return view('census-catalogue', compact('admin', 'editions', 'edition', 'fields', 'field', 'input', 'states', 'districts', 'levels', 'residences', 'rows', 'runs', 'current'));
    }

    public function prepare(int $run, CensusCatalogue $catalogue): RedirectResponse
    {
        $edition = $catalogue->prepare($run);

        return redirect()->route('census-catalogue.review', ['edition' => $edition])->with('status', 'Census edition prepared for review.');
    }

    public function withdraw(Request $request, int $edition, CensusCatalogue $catalogue): RedirectResponse
    {
        $catalogue->withdraw($edition, $request->user()->id);

        return redirect()->route('census-catalogue.review', ['edition' => $edition])->with('status', 'Edition withdrawn from public view. Review history is preserved.');
    }

    public function publish(Request $request, int $edition, CensusCatalogue $catalogue): RedirectResponse
    {
        $data = $request->validate(['current' => 'required|integer|min:0', 'reviewed' => 'accepted']);
        $catalogue->publish($edition, $request->user()->id, (int) $data['current']);

        return redirect()->route('census-catalogue.review', ['edition' => $edition])->with('status', 'Census edition published. The previous snapshot remains in history.');
    }
}
