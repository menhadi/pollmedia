<?php

namespace App\Http\Controllers;

use App\Services\ArchiveFiles;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoricalCensusTableController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $input = $request->validate([
            'year' => 'nullable|integer|min:1800|max:2100', 'area' => 'nullable|string|max:150',
            'group' => 'nullable|string|max:50', 'source' => 'nullable|regex:/^\d{4}-\d+-[a-f0-9]{16}$/',
            'sheet' => 'nullable|integer|min:0|max:100', 'district' => 'nullable|regex:/^[a-f0-9]{16}$/',
            'page' => 'nullable|integer|min:1|max:100000', 'format' => 'nullable|in:csv',
        ]);
        $disk = app(ArchiveFiles::class);
        $index = $disk->exists('census-source-tables/index.json')
            ? json_decode($disk->get('census-source-tables/index.json'), true, 512, JSON_THROW_ON_ERROR)
            : ['sources' => [], 'pending' => [], 'scope_note' => 'Historical source tables have not been prepared on this installation.'];
        $all = collect($index['sources']);
        $years = $all->pluck('year')->unique()->sortDesc()->values();
        $areas = $all->pluck('area_as_recorded')->unique()->sort()->values();
        $groups = $all->pluck('population_group')->unique()->sort()->values();
        $sources = $all->filter(fn (array $source): bool => (! ($input['year'] ?? null) || $source['year'] === (int) $input['year'])
            && (! ($input['area'] ?? null) || $source['area_as_recorded'] === $input['area'])
            && (! ($input['group'] ?? null) || $source['population_group'] === $input['group']));
        $source = isset($input['source']) ? $sources->firstWhere('id', $input['source']) : $sources->first();
        abort_if(isset($input['source']) && ! $source, 404);
        $metadata = $sheet = null;
        $rows = $notes = [];
        $sheetNumber = (int) ($input['sheet'] ?? 0);
        $page = (int) ($input['page'] ?? 1);
        $pageCount = $rowCount = 0;
        if ($source) {
            $prefix = 'census-source-tables/'.$source['id'].'/';
            $metadata = $this->verifiedJson($prefix.'manifest.json', $source['manifest_sha256']);
            $sheet = $metadata['sheets'][$sheetNumber] ?? null;
            abort_unless($sheet, 404);
            $partition = isset($input['district']) ? collect($sheet['districts'])->firstWhere('id', $input['district']) : $sheet;
            abort_unless($partition, 422, 'This district is not available in the selected worksheet.');
            $rowCount = $partition['row_count'];
            $pageCount = count($partition['pages']);
            abort_if($page > max(1, $pageCount), 404);
            if ($pageCount) {
                $file = $partition['pages'][$page - 1];
                abort_unless(is_int($file['offset']) && $file['offset'] >= 0 && is_int($file['length']) && $file['length'] > 0 && $file['length'] <= 8 * 1024 * 1024, 503);
                $stream = $disk->readStream($prefix.'pages.jsonl');
                abort_unless(is_resource($stream), 503);
                try {
                    abort_unless(fseek($stream, $file['offset']) === 0, 503);
                    $body = stream_get_contents($stream, $file['length']);
                    abort_unless(is_string($body) && strlen($body) === $file['length'] && hash_equals($file['sha256'], hash('sha256', $body)), 503, 'This source page needs an integrity check.');
                    $rows = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } finally {
                    fclose($stream);
                }
            }
            foreach ($rows as &$row) {
                $row['flags'] ??= [];
                if ($row['formula_columns'] ?? []) {
                    $row['flags'][] = 'Source formula shown as text; it has not been evaluated.';
                }
                if ($row['error_columns'] ?? []) {
                    $row['flags'][] = 'The original workbook contains an Excel error in this row.';
                }
                foreach ($row['flags'] as $note) {
                    $notes[$note][] = $row['source_row'];
                }
            }
            unset($row);
        }
        if (($input['format'] ?? null) === 'csv') {
            abort_unless($metadata && $sheet, 404);

            return response()->streamDownload(function () use ($metadata, $sheet, $rows): void {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                $write = function (array $values) use ($stream): void {
                    $safe = array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value, $values);
                    fputcsv($stream, $safe, ',', '"', '', "\r\n");
                };
                $write(['source_row', ...$sheet['headers'], 'data_notes', 'census_year', 'source_population_group', 'source_area', 'official_source', 'worksheet']);
                foreach ($rows as $row) {
                    $cells = array_pad($row['cells'], count($sheet['headers']), null);
                    $write([$row['source_row'], ...$cells, implode(' | ', $row['flags']), $metadata['year'], $metadata['population_group'], $metadata['area_as_recorded'], $metadata['source_url'], $sheet['name']]);
                }
                fclose($stream);
            }, 'census-source-page-'.$page.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $navigation = array_filter([...$input, 'source' => $source['id'] ?? null, 'sheet' => $sheetNumber], fn ($value) => $value !== null && $value !== '');
        unset($navigation['format'], $navigation['page']);

        return view('historical-census-tables', compact('index', 'all', 'years', 'areas', 'groups', 'sources', 'source', 'metadata', 'sheet', 'sheetNumber', 'rows', 'notes', 'input', 'page', 'pageCount', 'rowCount', 'navigation'));
    }

    private function verifiedJson(string $path, string $sha256): array
    {
        $body = app(ArchiveFiles::class)->get($path);
        abort_unless($body !== null && hash_equals($sha256, hash('sha256', $body)), 503, 'This prepared source table needs to be checked before it can be displayed.');

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
