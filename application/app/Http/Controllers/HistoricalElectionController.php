<?php

namespace App\Http\Controllers;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyHistory;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoricalElectionController extends Controller
{
    public function sourceFiles(Request $request, string $archive, ElectionArchive $archives): View|BinaryFileResponse
    {
        $catalogue = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
        $edition = collect($catalogue['entries'])->first(fn ($entry) => substr(hash('sha256', $entry['url']), 0, 24) === $archive);
        abort_unless($edition, 404);
        $collection = $archives->collection($edition['url']);
        if ($request->has('file')) {
            $input = $request->validate(['file' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+\.(pdf|xlsx|xls|zip|csv)$/']]);
            $file = collect($collection['files'])->firstWhere('file', $input['file']);
            abort_unless($file, 404);
            $path = app(ArchiveFiles::class)->path('election-archive/'.$archive.'/'.$file['file']);
            abort_unless(is_file($path) && hash_equals($file['sha256'], hash_file('sha256', $path)), 409, 'Archived source checksum differs. Use the official reference while this copy is checked.');

            return response()->download($path, basename($file['name']), ['X-Content-Type-Options' => 'nosniff']);
        }

        return view('assembly-source-files', compact('edition', 'collection', 'archive'));
    }

    public function sources(Request $request, ElectionArchive $archives): View
    {
        $catalogue = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
        $entries = collect($catalogue['entries']);
        $states = $entries->pluck('state')->unique()->sort()->values();
        $years = $entries->pluck('year')->unique()->sortDesc()->values();
        $input = $request->validate(['state' => ['nullable', Rule::in($states->all())], 'year' => ['nullable', 'integer', Rule::in($years->all())]]);
        $state = $input['state'] ?? null;
        $year = isset($input['year']) ? (int) $input['year'] : null;
        $entries = $entries->filter(fn ($entry) => ($state === null || $entry['state'] === $state) && ($year === null || $entry['year'] === $year))
            ->map(function ($entry) use ($archives): array {
                $collection = $archives->collection($entry['url']);

                return $entry + ['collected' => count($collection['files']), 'status' => $collection['status'], 'archive' => $collection['id'], 'extraction' => ($collection['has_extraction'] ?? false) ? $collection['id'] : null];
            });

        return view('assembly-sources', compact('catalogue', 'entries', 'states', 'years', 'state', 'year'));
    }

    public function compare(string $slug, ConstituencyHistory $history): View
    {
        $place = DB::table('places')->where('slug', 'pc-'.$slug)->where('type', 'pc')->first();
        abort_unless($place, 404);
        $comparison = $history->forPlace($place);

        return view('constituency-history', compact('place', 'comparison'));
    }

    public function compareAssembly(string $slug, ConstituencyHistory $history): View
    {
        $place = DB::table('places')->where('slug', 'ac-'.$slug)->where('type', 'ac')->first();
        abort_unless($place, 404);
        $comparison = $history->forAssembly($place);

        return view('constituency-history', compact('place', 'comparison'));
    }

    public function index(Request $request, HistoricalElectionArchive $history, ElectionArchive $archives, HistoricalElectionReview $reviews): View|StreamedResponse
    {
        $input = $request->validate(['edition' => 'nullable|regex:/^[a-f0-9]{24}$/', 'state' => 'nullable|string|max:100', 'code' => 'nullable|integer|min:1|max:999999', 'format' => 'nullable|in:csv']);
        $download = ($input['format'] ?? null) === 'csv';
        abort_if($download && (! isset($input['edition'], $input['state'])), 404);
        $kind = $request->routeIs('elections.assembly') ? 'ac' : 'pc';
        $archiveRoute = $kind === 'ac' ? 'elections.assembly' : 'elections.history';
        $archiveTitle = $kind === 'ac' ? 'India Assembly' : 'Lok Sabha';
        $editions = $history->editions($kind);
        $edition = $input['edition'] ?? ($editions[0]['id'] ?? null);
        $data = null;
        $selected = null;
        $relatedPlace = null;
        $states = collect();
        $coverage = collect();
        $stateResults = collect();
        $constituencies = collect();
        $state = $input['state'] ?? null;
        if ($edition) {
            abort_unless(collect($editions)->contains('id', $edition), 404);
            [$data] = $history->load($edition, $archives);
            abort_unless($data['kind'] === $kind, 404);
            $records = collect($data['records']);
            $stateLabel = fn (array $record): string => $record['state_name'] ?? $record['state_code'] ?? ($kind === 'ac' ? 'Uttar Pradesh' : '');
            $states = $records->map($stateLabel)->filter()->unique()->sort()->values();
            $coverage = $records->groupBy($stateLabel)->sortKeys()->map(function ($group, string $label): array {
                return [
                    'state' => $label,
                    'tables' => $group->count(),
                    'rows' => $group->sum(fn (array $record): int => count($record['candidates'] ?? [])),
                ];
            })->values();
            abort_if($state && ! $states->contains($state), 404);
            $constituencies = $state ? $records->filter(fn (array $record): bool => $stateLabel($record) === $state)->sortBy(fn (array $record): string => $record['constituency_name'] ?? $record['name'])->values() : collect();
            if (isset($input['code'])) {
                $selected = $constituencies->firstWhere('code', (int) $input['code']);
                abort_unless($selected, 404);
                $original = $selected;
                $selected = $reviews->apply($edition, $selected, $data['source_sha256']);
                if ($download) {
                    return $this->download($data, [$selected], $state, $kind, $edition, (string) $selected['code']);
                }
                $relatedPlace = app(ConstituencyHistory::class)->relatedPlace($edition, $original);
            } elseif ($state) {
                $stateResults = $constituencies->map(function (array $original) use ($reviews, $edition, $data): array {
                    $record = $reviews->apply($edition, $original, $data['source_sha256']);
                    $record['winner_party'] = null;
                    if (! $record['has_warning'] && ($record['number_of_seats'] ?? 1) === 1 && isset($record['winner'], $record['margin'])) {
                        $winner = collect($record['candidates'])->first(fn (array $candidate): bool => ! ($candidate['is_nota'] ?? false) && strtoupper($candidate['party_at_election']) !== 'NOTA' && $candidate['candidate_name'] === $record['winner']);
                        $record['winner_party'] = $winner['party_at_election'] ?? null;
                    }

                    return $record;
                });
            }
        }

        if ($download) {
            return $this->download($data, $stateResults->all(), $state, $kind, $edition, 'state-'.Str::slug($state));
        }

        $established = $stateResults->filter(fn (array $record): bool => $record['winner_party'] !== null);
        $partySummary = [
            'counted' => $established->count(),
            'under_review' => $stateResults->where('has_warning', true)->count(),
            'other' => $stateResults->where('has_warning', false)->whereNull('winner_party')->count(),
            'parties' => $established->countBy('winner_party')->sortDesc(),
        ];

        return view('historical-elections', compact('editions', 'edition', 'data', 'states', 'state', 'constituencies', 'selected', 'relatedPlace', 'coverage', 'stateResults', 'partySummary', 'kind', 'archiveRoute', 'archiveTitle'));
    }

    private function download(array $data, array $records, string $state, string $kind, string $edition, string $scope): StreamedResponse
    {
        return response()->streamDownload(function () use ($data, $records, $state, $kind, $edition): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $write = function (array $cells) use ($stream): void {
                $safe = array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value, $cells);
                fputcsv($stream, $safe, ',', '"', '', "\r\n");
            };
            $write(['year', 'election_type', 'edition_id', 'state_as_recorded', 'archive_record_code', 'official_constituency_code', 'constituency', 'row_type', 'candidate', 'party_at_election', 'general_evm_votes', 'postal_votes', 'total_votes', 'electors', 'votes_polled', 'valid_candidate_votes', 'status', 'data_note', 'official_source', 'additional_official_sources', 'source_locator', 'detail_pdf_page', 'summary_pdf_page', 'election_symbol', 'candidate_pdf_page', 'election_round', 'source_document']);
            foreach ($records as $record) {
                foreach ($record['candidates'] ?: [null] as $candidate) {
                    $write([$data['year'], $kind, $edition, $state, $record['code'], $record['official_pc_code'] ?? $record['official_ac_code'] ?? ($kind === 'ac' ? $record['code'] : null), $record['name'], $candidate ? (($candidate['is_nota'] ?? false) ? 'nota' : 'candidate') : 'no_candidate_rows', $candidate['candidate_name'] ?? null, $candidate['party_at_election'] ?? null, $candidate['general_votes'] ?? null, $candidate['postal_votes'] ?? null, $candidate['votes'] ?? null, $record['electors'] ?? null, $record['votes_polled'] ?? null, $record['valid_candidate_votes'] ?? null, $record['status'], $record['has_warning'] ? '† '.($record['error'] ?? 'This record requires review.') : '', $data['source_url'], collect($data['additional_sources'] ?? [])->pluck('source_url')->filter()->implode(' | '), $record['source_locator'] ?? '', $record['detail_page'] ?? null, $record['summary_page'] ?? null, $candidate['election_symbol'] ?? null, $candidate['source_page'] ?? null, $record['election_round'] ?? null, $record['source_document'] ?? null]);
                }
            }
            fclose($stream);
        }, 'pollmedia-'.$kind.'-'.$data['year'].'-'.$scope.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
