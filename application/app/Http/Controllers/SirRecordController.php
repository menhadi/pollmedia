<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SirRecordController extends Controller
{
    public function options(): JsonResponse
    {
        $editions = Schema::hasTable('sir_records') ? DB::table('sir_records')->select('edition_key', 'state_code', 'ac_code', 'ac_name', 'year', 'edition', 'document_date')->distinct()->orderBy('ac_name')->get() : collect();

        return response()->json(['editions' => $editions]);
    }

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate(['edition_key' => 'required|string|size:64', 'name' => 'nullable|string|max:100', 'relative_name' => 'nullable|string|max:100', 'part' => 'nullable|integer|min:1', 'page' => 'nullable|integer|between:1,10000']);
        $name = trim($input['name'] ?? '');
        $relative = trim($input['relative_name'] ?? '');
        abort_if(mb_strlen($name) < 2 && mb_strlen($relative) < 2, 422, 'Enter at least two characters of a name or relative name.');
        if (! Schema::hasTable('sir_records')) {
            return response()->json(['data' => [], 'next_page_url' => null]);
        }
        $query = DB::table('sir_records')->where('edition_key', $input['edition_key']);
        foreach (['name' => $name, 'relative_name' => $relative] as $field => $value) {
            if ($value !== '') {
                $query->whereRaw('LOWER('.$field.") LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%']);
            }
        }
        $query->when(! empty($input['part']), fn ($query) => $query->where('part', $input['part']));
        $rows = $query->select('name', 'relative_name', 'relationship', 'year', 'edition', 'document_date', 'ac_name', 'ac_code', 'part', 'station', 'serial', 'pdf_page', 'source_url')->orderBy('part')->orderBy('serial')->simplePaginate(10);

        return response()->json($rows)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
