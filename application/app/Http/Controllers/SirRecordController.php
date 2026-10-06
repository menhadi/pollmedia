<?php

namespace App\Http\Controllers;

use App\Services\SirNameSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $query = DB::table('sir_records')->where('document_type', 'electoral_roll');
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
            return response()->json(['periods' => [], 'states' => [], 'pcs' => [], 'acs' => [], 'stations' => [], 'editions' => [], 'statistics' => []]);
        }
        $metadata = ['pdf_sha256', 'source_landing_url', 'source_url', 'edition_key', 'state_code', 'state_name', 'pc_code', 'pc_name', 'pc_source_url', 'ac_code', 'ac_name', 'year', 'edition', 'document_date'];
        $all = $this->scope([])->select('year', 'document_date')->distinct()->get();
        $periods = $all->map(fn ($row) => ['value' => ($row->year ? 'revision:'.$row->year : 'document:'.substr($row->document_date, 0, 4)), 'label' => $row->year ? (string) $row->year.' (revision year)' : substr($row->document_date, 0, 4).' (document year; revision unverified)'])->unique('value')->sortByDesc('value')->values();
        $stateRows = $this->scope(array_intersect_key($input, array_flip(['period'])))->select('state_code', 'state_name')->distinct()->get();
        $states = $stateRows->map(fn ($row) => ['value' => $row->state_code, 'label' => $row->state_name ?? ($row->state_code === '09' ? 'Uttar Pradesh' : 'State '.$row->state_code)])->unique('value')->sortBy('label')->values();
        $pcRows = $this->scope(array_intersect_key($input, array_flip(['period', 'state_code'])))->whereNotNull('pc_code')->whereNotNull('pc_source_url')->select('state_code', 'pc_code', 'pc_name')->distinct()->get();
        $pcs = $pcRows->map(fn ($row) => ['value' => $row->state_code.'|'.$row->pc_code, 'label' => $row->pc_code.' - '.$row->pc_name])->sortBy('label')->values();
        $acRows = $this->scope(array_intersect_key($input, array_flip(['period', 'state_code', 'pc_code'])))->select('state_code', 'ac_code', 'ac_name')->distinct()->get();
        $acs = $acRows->map(fn ($row) => ['value' => $row->state_code.'|'.$row->ac_code, 'label' => $row->ac_code.' - '.$row->ac_name])->sortBy('label')->values();
        $editions = $this->scope(array_diff_key($input, array_flip(['station_key', 'edition_key'])))->select($metadata)->distinct()->orderBy('document_date', 'desc')->get();
        $coverage = DB::table('site_settings')->whereIn('key', $editions->pluck('edition_key')->map(fn (string $key): string => 'sir-roll-meta:'.$key))->pluck('value', 'key');
        $statistics = [];
        foreach ($editions as $edition) {
            $details = json_decode($coverage->get('sir-roll-meta:'.$edition->edition_key, '{}'), true, 512, JSON_THROW_ON_ERROR);
            $edition->indexed_records = $details['indexed_records'] ?? null;
            $edition->printed_electors = $details['printed_electors'] ?? null;
            $edition->held_records_count = $details['held_records_count'] ?? null;
            $edition->uncertain_records = $details['uncertain_records'] ?? 0;
            $edition->roll_language = $details['roll_language'] ?? null;
            if (! empty($input['edition_key']) && $input['edition_key'] !== $edition->edition_key) {
                continue;
            }
            foreach ($details['official_statistics'] ?? [] as $partStatistics) {
                if (! empty($input['station_key']) && $input['station_key'] !== $edition->edition_key.':'.$partStatistics['part']) {
                    continue;
                }
                $statistics[] = array_merge($partStatistics, [
                    'year' => $edition->year, 'document_date' => $edition->document_date,
                    'edition' => $edition->edition, 'edition_key' => $edition->edition_key,
                    'state_code' => $edition->state_code, 'state_name' => $edition->state_name,
                    'pc_code' => $edition->pc_code, 'ac_code' => $edition->ac_code, 'ac_name' => $edition->ac_name,
                    'roll_language' => $details['roll_language'] ?? null,
                    'qualifying_date' => $details['qualifying_date'] ?? null,
                    'pdf_sha256' => $edition->pdf_sha256,
                    'pdf_url' => $edition->pdf_sha256 ? route('sir.document', ['hash' => $edition->pdf_sha256]).'#page='.$partStatistics['pdf_page'] : $edition->source_url.'#page='.$partStatistics['pdf_page'],
                    'official_publication_url' => $edition->source_landing_url ?? $edition->source_url,
                ]);
            }
        }
        $stations = collect();
        if (! empty($input['ac_code'])) {
            $stations = $this->scope(array_diff_key($input, array_flip(['station_key'])))->select('edition_key', 'part', 'station', 'document_date')->distinct()->orderBy('part')->get()->map(fn ($row) => ['value' => $row->edition_key.':'.$row->part, 'label' => 'Part '.$row->part.' - '.$row->station.' ('.$row->document_date.')']);
        }

        return response()->json(compact('periods', 'states', 'pcs', 'acs', 'stations', 'editions', 'statistics'));
    }

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules() + ['name' => 'nullable|string|max:100', 'relative_name' => 'nullable|string|max:100', 'part' => 'nullable|integer|min:1', 'page' => 'nullable|integer|between:1,10000', 'per_page' => 'nullable|integer|in:25,50,100,500']);
        $name = trim($input['name'] ?? '');
        $relative = trim($input['relative_name'] ?? '');
        abort_unless(collect($input)->only(['period', 'state_code', 'pc_code', 'ac_code', 'edition_key', 'station_key'])->filter()->isNotEmpty() || $name !== '' || $relative !== '', 422, 'Choose a year or geographic scope, or enter a name.');
        if (! Schema::hasTable('sir_records')) {
            return response()->json(['data' => [], 'total' => 0, 'next_page_url' => null])->header('Cache-Control', 'private, no-store');
        }
        $query = $this->scope($input);
        foreach (['name' => $name, 'relative_name' => $relative] as $field => $value) {
            if ($value !== '') {
                $query->where(function (Builder $match) use ($field, $value): void {
                    $match->whereRaw('LOWER('.$field.") LIKE ? ESCAPE '!'", [SirNameSearch::pattern($value)]);
                    if (preg_match('/^[a-zA-Z\s.\x{2019}\x{0027}-]+$/u', $value) && preg_match('/[a-zA-Z]/', $value)) {
                        $latin = SirNameSearch::latin($value);
                        $key = SirNameSearch::key($latin);
                        $match->orWhereRaw($field."_latin LIKE ? ESCAPE '!'", [SirNameSearch::pattern($latin)]);
                        if (strlen($key) >= 1 && strlen(preg_replace('/[^a-z]/', '', $latin)) >= 2) {
                            $match->orWhereRaw($field."_latin_key LIKE ? ESCAPE '!'", [SirNameSearch::pattern($key)]);
                        }
                    }
                });
            }
        }
        if (! empty($input['part'])) {
            $query->where('part', $input['part']);
        }
        $summary = $this->summary(clone $query);
        $rows = $query->select('name', 'relative_name', 'relationship', 'year', 'edition', 'edition_key', 'document_date', 'state_code', 'state_name', 'pc_code', 'pc_name', 'ac_name', 'ac_code', 'part', 'station', 'serial', 'pdf_page', 'source_url', 'source_landing_url', 'pdf_sha256', 'extraction_status', 'section_number', 'section_name', 'ward_number', 'house_number', 'age', 'age_text', 'gender', 'elector_id', 'serial_verified', 'field_notes', 'extraction_note')->orderBy('document_date', 'desc')->orderBy('state_code')->orderBy('ac_code')->orderBy('edition_key')->orderBy('part')->orderBy('serial')->orderBy('id')->paginate((int) ($input['per_page'] ?? 50));
        $rows->through(function (object $row): object {
            $row->pdf_url = $row->pdf_sha256 ? route('sir.document', ['hash' => $row->pdf_sha256]).'#page='.$row->pdf_page : $row->source_url.'#page='.$row->pdf_page;

            return $row;
        });

        return response()->json($rows->toArray() + ['summary' => $summary])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function summary(Builder $query): array
    {
        $genders = ['male' => ['male', 'm', 'पुरुष', 'ஆண்'], 'female' => ['female', 'f', 'महिला', 'स्त्री', 'பெண்'], 'third_gender' => ['third gender', 'other', 'तृतीय लिंग', 'திருநங்கை']];
        $query->selectRaw('COUNT(*) AS total');
        foreach ($genders as $key => $values) {
            $query->selectRaw('SUM(CASE WHEN LOWER(TRIM(gender)) IN ('.implode(',', array_fill(0, count($values), '?')).') THEN 1 ELSE 0 END) AS '.$key, $values);
        }
        $groups = [['18–30', 18, 30], ['31–40', 31, 40], ['41–50', 41, 50], ['51–60', 51, 60], ['61–70', 61, 70], ['71–80', 71, 80], ['Above 80', 81, 120], ['Below 18 / verify PDF', 0, 17]];
        foreach ($groups as $index => [$label, $minimum, $maximum]) {
            $query->selectRaw('SUM(CASE WHEN age BETWEEN ? AND ? THEN 1 ELSE 0 END) AS age_'.$index, [$minimum, $maximum]);
        }
        $query->selectRaw('SUM(CASE WHEN age IS NULL THEN 1 ELSE 0 END) AS age_unknown');
        $query->selectRaw("SUM(CASE WHEN extraction_status = 'ocr_uncertain' THEN 1 ELSE 0 END) AS uncertain");
        $counts = (array) $query->first();
        $summary = array_map(fn ($value): int => (int) $value, array_intersect_key($counts, array_flip(['total', 'male', 'female', 'third_gender', 'uncertain'])));
        $summary['unknown_gender'] = $summary['total'] - $summary['male'] - $summary['female'] - $summary['third_gender'];
        $summary['age_groups'] = [];
        foreach ($groups as $index => [$label]) {
            $summary['age_groups'][] = ['label' => $label, 'count' => (int) $counts['age_'.$index]];
        }
        $summary['age_groups'][] = ['label' => 'Age not available', 'count' => (int) $counts['age_unknown']];

        return $summary;
    }

    public function document(string $hash): BinaryFileResponse
    {
        abort_unless(DB::table('sir_records')->where('document_type', 'electoral_roll')->where('pdf_sha256', $hash)->exists(), 404);
        $path = storage_path('app/private/sir-pdfs/'.$hash.'.pdf');
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
