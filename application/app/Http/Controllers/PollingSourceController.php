<?php

namespace App\Http\Controllers;

use App\Services\ArchiveFiles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PollingSourceController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse|StreamedResponse
    {
        if (Schema::hasTable('polling_source_documents') && DB::table('polling_source_documents')->exists()) {
            return $this->databaseIndex($request);
        }

        $disk = app(ArchiveFiles::class);
        $root = 'polling-station-sources/';
        $index = $disk->exists($root.'index.json') ? json_decode($disk->get($root.'index.json'), true, 512, JSON_THROW_ON_ERROR) : ['states' => [], 'sources' => []];
        $states = collect($index['states'])->sortBy('state')->values();
        $all = collect($index['sources']);
        $documentCount = $all->count();
        $pollingRowCount = $all->sum('polling_rows');
        $input = $request->validate(['state' => ['nullable', Rule::in($states->pluck('state')->all())], 'source' => ['nullable', 'regex:/^[a-f0-9]{24}$/'], 'page' => ['nullable', 'integer', 'min:1'], 'download' => ['nullable', 'boolean']]);
        $choices = $all->filter(fn ($source) => ! isset($input['state']) || $source['state'] === $input['state'])->values();
        $source = isset($input['source']) ? $choices->firstWhere('id', $input['source']) : ($choices->first(fn ($entry) => ($entry['page_count'] ?? count($entry['pages'])) > 0) ?? $choices->first());
        abort_if(isset($input['source']) && ! $source, 404);
        if ($input['download'] ?? false) {
            abort_unless($source && isset($input['source']) && preg_match('/^[a-f0-9]{24}$/', $source['folder']) && preg_match('/^[a-f0-9]{64}\.(pdf|xlsx|xls|zip|csv)$/', $source['file']), 404);
            $originalPath = $disk->path($root.$source['folder'].'/'.$source['file']);
            abort_unless(is_file($originalPath) && hash_equals($source['sha256'], hash_file('sha256', $originalPath)), 503, 'Preserved original integrity check failed.');

            return response()->download($originalPath, $source['file'], ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
        }
        $page = (int) ($input['page'] ?? 1);
        $data = null;
        $ocr = null;
        $sourcePages = null;
        if ($source && ! empty($source['page_manifest'])) {
            $reference = $source['page_manifest'];
            abort_unless(preg_match('/^[a-f0-9]{24}$/', $source['folder']) && preg_match('/^[a-f0-9]{64}-pages-[a-f0-9]{16}\.json$/', $reference['file']), 503);
            $manifestPath = $disk->path($root.$source['folder'].'/'.$reference['file']);
            abort_unless(is_file($manifestPath) && filesize($manifestPath) <= 16000000 && hash_equals($reference['sha256'], hash_file('sha256', $manifestPath)), 503, 'Page manifest integrity check failed.');
            $source['pages'] = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)['pages'];
        }
        if ($source && $source['pages']) {
            $metadata = collect($source['pages'])->firstWhere('page', $page);
            abort_unless($metadata && preg_match('/^[a-f0-9]{24}$/', $source['folder']) && preg_match('/^[a-f0-9]{64}$/', $source['sha256']) && preg_match('/^\d+(?:-[a-f0-9]{16})?\.json$/', $metadata['file']), 404);
            $path = $disk->path($root.$source['folder'].'/'.$source['sha256'].'-tables/'.$metadata['file']);
            abort_unless(is_file($path) && filesize($path) <= 16000000 && hash_equals($metadata['sha256'], hash_file('sha256', $path)), 503, 'Extracted page integrity check failed.');
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (isset($metadata['ocr'])) {
                $ocrMetadata = $metadata['ocr'];
                abort_unless(preg_match('/^\d+-[a-f0-9]{16}\.json$/', $ocrMetadata['file']), 404);
                $ocrPath = $disk->path($root.$source['folder'].'/'.$source['sha256'].'-ocr/'.$ocrMetadata['file']);
                abort_unless(is_file($ocrPath) && filesize($ocrPath) <= 16000000 && hash_equals($ocrMetadata['sha256'], hash_file('sha256', $ocrPath)), 503, 'OCR page integrity check failed.');
                $ocr = json_decode(file_get_contents($ocrPath), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        if ($source) {
            $source['has_preserved_original'] = isset($source['file']) && $disk->exists($root.$source['folder'].'/'.$source['file']);
        }

        return view('polling-source-tables', compact('index', 'states', 'choices', 'source', 'input', 'page', 'data', 'ocr', 'documentCount', 'pollingRowCount', 'sourcePages'));
    }

    private function databaseIndex(Request $request): View|StreamedResponse
    {
        $disk = app(ArchiveFiles::class);
        $states = DB::table('polling_source_states')->orderBy('name')->get()->map(fn ($row) => json_decode($row->metadata, true));
        $input = $request->validate(['state' => ['nullable', Rule::in($states->pluck('state')->all())],
            'source' => ['nullable', 'regex:/^[a-f0-9]{24}$/'], 'page' => ['nullable', 'integer', 'min:1'],
            'source_page' => ['nullable', 'integer', 'min:1'], 'download' => ['nullable', 'boolean']]);
        $selected = isset($input['source']) ? DB::table('polling_source_documents')->where('id', $input['source'])->first() : null;
        abort_if(isset($input['source']) && (! $selected || (isset($input['state']) && $selected->state !== $input['state'])), 404);
        $selectedState = $input['state'] ?? $selected?->state ?? DB::table('polling_source_documents')->orderBy('state')->value('state');
        if ($selectedState !== null) {
            $input['state'] = $selectedState;
        }
        $sourcePages = $selectedState === null ? null : DB::table('polling_source_documents')->where('state', $selectedState)
            ->orderBy('id')->paginate(200, ['metadata'], 'source_page', (int) ($input['source_page'] ?? 1));
        $choices = $sourcePages === null ? collect() : $sourcePages->getCollection()->map(fn ($row) => json_decode($row->metadata, true));
        $source = $selected ? json_decode($selected->metadata, true) : $choices->first();
        if ($source && ! $choices->contains('id', $source['id'])) {
            $choices->prepend($source);
        }
        $summary = Cache::remember('polling-source-summary', now()->addHour(), function (): array {
            $rows = 0;
            foreach (DB::table('polling_source_documents')->select('metadata')->cursor() as $document) {
                $rows += (int) (json_decode($document->metadata, true)['polling_rows'] ?? 0);
            }

            return ['documents' => DB::table('polling_source_documents')->count(), 'rows' => $rows];
        });
        $documentCount = $summary['documents'];
        $pollingRowCount = $summary['rows'];
        if ($input['download'] ?? false) {
            abort_unless($source && isset($input['source']), 404);
            $path = 'polling-station-sources/'.$source['folder'].'/'.$source['file'];
            $file = DB::table('pdf_storage_files')->where('path_hash', hash('sha256', $path))->first();
            if ($file) {
                abort_unless(hash_equals($source['sha256'], $file->sha256), 503, 'Preserved original integrity check failed.');
            } else {
                abort_unless($disk->verify($path, $source['sha256']), 503, 'Preserved original integrity check failed.');
            }

            return $disk->download($path, $source['file'], ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
        }
        $page = (int) ($input['page'] ?? 1);
        $data = null;
        $ocr = null;
        if ($source) {
            $source['has_preserved_original'] = $disk->exists('polling-station-sources/'.$source['folder'].'/'.$source['file']);
            $source['pages'] = DB::table('polling_source_pages')->where('source_id', $source['id'])
                ->orderBy('page')->get(['metadata'])->map(fn ($row) => json_decode($row->metadata, true))->all();
            if ($source['pages']) {
                $record = DB::table('polling_source_pages')->where('source_id', $source['id'])->where('page', $page)->first();
                abort_unless($record, 404);
                $data = json_decode($record->payload, true);
                $ocr = $record->ocr_payload === null ? null : json_decode($record->ocr_payload, true);
            }
        }
        $index = ['scope_note' => 'Imported official source records retain warnings and links to their original publication. State directory labels identify collection paths, not verified document jurisdiction.'];

        return view('polling-source-tables', compact('index', 'states', 'choices', 'source', 'input', 'page', 'data', 'ocr', 'documentCount', 'pollingRowCount', 'sourcePages'));
    }
}
