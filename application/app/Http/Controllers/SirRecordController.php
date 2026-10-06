<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SirRecordController extends Controller
{
    private function rules(): array
    {
        return ['period' => ['nullable', 'regex:/^(revision|document):[0-9]{4}$/'],
            'state_code' => 'nullable|string|max:10', 'pc_code' => 'nullable|string|max:10',
            'ac_code' => 'nullable|string|max:10', 'edition_key' => 'nullable|string|size:64',
            'station_key' => 'nullable|regex:/^[a-f0-9]{64}:[1-9][0-9]*$/'];
    }

    private function scope(array $input): Builder
    {
        $query = DB::table('sir_records');
        if (! empty($input['period'])) {
            [$kind,$year] = explode(':', $input['period']);
            if ($kind === 'revision') {
                $query->where('year', (int) $year);
            } else {
                $query->whereNull('year')->whereYear('document_date', (int) $year);
            }
        }
        foreach (['state_code', 'ac_code', 'edition_key'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        if (! empty($input['pc_code'])) {
            $query->where('pc_code', $input['pc_code'])->whereNotNull('pc_source_url');
        }
        if (! empty($input['station_key'])) {
            [$edition,$part] = explode(':', $input['station_key']);
            $query->where('edition_key', $edition)->where('part', (int) $part);
        }

        return $query;
    }

    public function options(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());
        if (! Schema::hasTable('sir_records')) {
            return response()->json(['periods' => [], 'states' => [], 'pcs' => [], 'acs' => [], 'stations' => [], 'editions' => []]);
        }
        $metadata = ['edition_key', 'state_code', 'state_name', 'pc_code', 'pc_name', 'pc_source_url', 'ac_code', 'ac_name', 'year', 'edition', 'document_date'];
        $all = DB::table('sir_records')->select('year', 'document_date')->distinct()->get();
        $periods = $all->map(fn ($row) => ['value' => ($row->year ? 'revision:'.$row->year : 'document:'.substr($row->document_date, 0, 4)), 'label' => $row->year ? (string) $row->year.' (revision year)' : substr($row->document_date, 0, 4).' (document year; revision unverified)'])->unique('value')->sortByDesc('value')->values();
        $stateRows = $this->scope(array_intersect_key($input, array_flip(['period'])))->select('state_code', 'state_name')->distinct()->get();
        $states = $stateRows->map(fn ($row) => ['value' => $row->state_code, 'label' => $row->state_name ?? ($row->state_code === '09' ? 'Uttar Pradesh' : 'State '.$row->state_code)])->unique('value')->sortBy('label')->values();
        $pcRows = $this->scope(array_intersect_key($input, array_flip(['period', 'state_code'])))->whereNotNull('pc_code')->whereNotNull('pc_source_url')->select('state_code', 'pc_code', 'pc_name')->distinct()->get();
        $pcs = $pcRows->map(fn ($row) => ['value' => $row->state_code.'|'.$row->pc_code, 'label' => $row->pc_code.' - '.$row->pc_name])->sortBy('label')->values();
        $acRows = $this->scope(array_intersect_key($input, array_flip(['period', 'state_code', 'pc_code'])))->select('state_code', 'ac_code', 'ac_name')->distinct()->get();
        $acs = $acRows->map(fn ($row) => ['value' => $row->state_code.'|'.$row->ac_code, 'label' => $row->ac_code.' - '.$row->ac_name])->sortBy('label')->values();
        $editions = $this->scope(array_diff_key($input, array_flip(['station_key', 'edition_key'])))->select($metadata)->distinct()->orderBy('document_date', 'desc')->get();
        $stations = collect();
        if (! empty($input['ac_code'])) {
            $stations = $this->scope(array_diff_key($input, array_flip(['station_key'])))->select('edition_key', 'part', 'station', 'document_date')->distinct()->orderBy('part')->get()->map(fn ($row) => ['value' => $row->edition_key.':'.$row->part, 'label' => 'Part '.$row->part.' - '.$row->station.' ('.$row->document_date.')']);
        }

        return response()->json(compact('periods', 'states', 'pcs', 'acs', 'stations', 'editions'));
    }

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules() + ['name' => 'nullable|string|max:100', 'relative_name' => 'nullable|string|max:100', 'part' => 'nullable|integer|min:1', 'page' => 'nullable|integer|between:1,10000']);
        $name = trim($input['name'] ?? '');
        $relative = trim($input['relative_name'] ?? '');
        abort_unless(collect($input)->only(['period', 'state_code', 'pc_code', 'ac_code', 'edition_key', 'station_key'])->filter()->isNotEmpty() || $name !== '' || $relative !== '', 422, 'Choose a year or geographic scope, or enter a name.');
        if (! Schema::hasTable('sir_records')) {
            return response()->json(['data' => [], 'total' => 0, 'next_page_url' => null])->header('Cache-Control', 'private, no-store');
        }
        $query = $this->scope($input);
        foreach (['name' => $name, 'relative_name' => $relative] as $field => $value) {
            if ($value !== '') {
                $query->whereRaw('LOWER('.$field.") LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%']);
            }
        }
        if (! empty($input['part'])) {
            $query->where('part', $input['part']);
        }
        $rows = $query->select('name', 'relative_name', 'relationship', 'year', 'edition', 'edition_key', 'document_date', 'state_code', 'state_name', 'pc_code', 'pc_name', 'ac_name', 'ac_code', 'part', 'station', 'serial', 'pdf_page', 'source_url')->orderBy('document_date', 'desc')->orderBy('state_code')->orderBy('ac_code')->orderBy('edition_key')->orderBy('part')->orderBy('serial')->orderBy('id')->paginate(25);

        return response()->json($rows)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
