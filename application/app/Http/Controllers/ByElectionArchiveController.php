<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ByElectionArchiveController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $disk = Storage::disk('local');
        $catalogue = $disk->exists('election-by-elections/catalogue.json')
            ? json_decode($disk->get('election-by-elections/catalogue.json'), true, 512, JSON_THROW_ON_ERROR) : ['entries' => []];
        $entries = collect($catalogue['entries']);
        $coverage = $disk->exists('election-by-elections/summary.json')
            ? collect(json_decode($disk->get('election-by-elections/summary.json'), true, 512, JSON_THROW_ON_ERROR)['entries'])->countBy('status') : collect();
        $years = $entries->pluck('year')->unique()->sortDesc()->values();
        $input = $request->validate([
            'year' => ['nullable', 'integer', Rule::in($years->all())],
            'edition' => ['nullable', Rule::in($entries->pluck('id')->all())],
            'source' => ['nullable', 'regex:/^[a-f0-9]{64}-tables\.json$/'],
            'file' => ['nullable', 'regex:/^[a-z0-9-]+\.(pdf|xlsx|xls|zip|csv|html|htm)$/'],
            'table' => ['nullable', 'integer', 'min:0'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $choices = $entries->filter(fn ($entry) => ! isset($input['year']) || $entry['year'] === (int) $input['year'])->values();
        $edition = isset($input['edition']) ? $choices->firstWhere('id', $input['edition']) : $choices->first();
        abort_if(isset($input['edition']) && ! $edition, 422, 'Edition does not match the selected year.');
        $manifest = ['files' => [], 'extractions' => [], 'status' => 'pending'];
        $folder = $edition ? 'election-by-elections/'.$edition['id'].'/' : null;
        if ($folder && $disk->exists($folder.'manifest.json')) {
            $manifest = json_decode($disk->get($folder.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        if (isset($input['file'])) {
            $file = collect($manifest['files'])->firstWhere('file', $input['file']);
            abort_unless($file, 404);
            $path = $disk->path($folder.$file['file']);
            abort_unless(is_file($path) && hash_equals($file['sha256'], hash_file('sha256', $path)), 409, 'Source checksum differs.');

            return response()->download($path, basename($file['file']), ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
        }
        $sources = collect($manifest['extractions'] ?? []);
        $source = isset($input['source']) ? $sources->firstWhere('file', $input['source']) : $sources->first();
        abort_if(isset($input['source']) && ! $source, 404);
        $data = $table = null;
        $rows = [];
        $page = (int) ($input['page'] ?? 1);
        $pages = 1;
        if ($source) {
            $path = $disk->path($folder.$source['file']);
            abort_unless(is_file($path) && filesize($path) <= 16000000 && hash_equals($source['sha256'], hash_file('sha256', $path)), 503, 'Prepared table integrity check failed.');
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $table = $data['tables'][(int) ($input['table'] ?? 0)] ?? null;
            abort_unless($table, 404);
            $pages = max(1, (int) ceil(count($table['rows']) / 100));
            abort_if($page > $pages, 404);
            $rows = array_slice($table['rows'], ($page - 1) * 100, 100);
        }
        $navigation = array_filter(['year' => $input['year'] ?? null, 'edition' => $edition['id'] ?? null, 'source' => $source['file'] ?? null, 'table' => $input['table'] ?? 0], fn ($value) => $value !== null);

        return view('by-election-archive', compact('entries', 'coverage', 'choices', 'years', 'input', 'edition', 'manifest', 'sources', 'source', 'data', 'table', 'rows', 'page', 'pages', 'navigation'));
    }
}
