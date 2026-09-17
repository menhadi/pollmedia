<?php

namespace App\Http\Controllers;

use App\Services\ElectionArchive;
use App\Services\ElectionPublication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ElectionPublicationController extends Controller
{
    public function index(Request $request, ElectionPublication $service, ElectionArchive $archive): View
    {
        $input = $request->validate(['archive_type' => 'nullable|in:pc,ac', 'archive_year' => 'nullable|integer|min:1900|max:2100']);
        $archiveType = $input['archive_type'] ?? 'pc';
        $archiveYear = isset($input['archive_year']) ? (int) $input['archive_year'] : null;
        $catalogue = $archive->catalogue();
        $archiveYears = collect(array_merge($catalogue['pc'], $catalogue['ac']))->map(fn ($row) => (int) substr($row[0], 0, 4))->unique()->sortDesc()->values();
        $archiveEntries = $archive->entries($archiveType, $archiveYear);
        $contests = $service->contests();
        $drafts = DB::table('election_publications as d')->join('election_contests as e', 'e.id', '=', 'd.base_contest_id')->join('places as p', 'p.id', '=', 'e.place_id')->select('d.*', 'p.name', 'e.year')->orderByDesc('d.id')->paginate(20);

        return view('election-imports', compact('contests', 'drafts', 'archiveType', 'archiveYear', 'catalogue', 'archiveYears', 'archiveEntries'));
    }

    public function archiveFile(string $archive, string $file, ElectionArchive $service): BinaryFileResponse
    {
        $entries = array_merge($service->catalogue()['ac'], $service->catalogue()['pc']);
        $entry = collect($entries)->first(fn (array $row): bool => substr(hash('sha256', $row[1]), 0, 24) === $archive);
        abort_unless($entry, 404);
        $collection = $service->collection($entry[1]);
        $record = collect($collection['files'])->firstWhere('download_id', $file);
        abort_unless($record && basename($record['file']) === $record['file'], 404);
        $path = Storage::disk('local')->path('election-archive/'.$archive.'/'.$record['file']);
        abort_unless(is_file($path) && hash_equals($record['sha256'], hash_file('sha256', $path)), 409, 'Archived file integrity check failed.');

        return response()->download($path);
    }

    public function store(Request $request, ElectionPublication $service): RedirectResponse
    {
        $input = $request->validate(['contest_id' => 'required|integer', 'mode' => 'required|in:upload,archive']);
        $contest = $service->contests()->firstWhere('id', (int) $input['contest_id']);
        abort_unless($contest, 422, 'Election edition unavailable.');
        $extension = $contest->type === 'ac' ? 'xlsx' : 'pdf';
        $needsTotals = $contest->type === 'ac' || $contest->year === 2019;
        if ($input['mode'] === 'upload') {
            $request->validate(['detail' => 'required|file|max:48828|extensions:'.$extension, 'totals' => ($needsTotals ? 'required' : 'nullable').'|file|max:48828|extensions:'.$extension]);
            $detail = $request->file('detail')->getRealPath();
            $totals = $needsTotals ? $request->file('totals')->getRealPath() : null;
        } else {
            $prefix = $contest->type === 'ac' ? '2022-up' : (string) $contest->year;
            $detail = base_path('../pilot/raw/elections/'.$prefix.'-detailed.'.$extension);
            $totals = $needsTotals ? base_path('../pilot/raw/elections/'.$prefix.'-summary.'.$extension) : null;
            abort_unless(is_file($detail) && (! $totals || is_file($totals)), 422, 'Saved official files are unavailable. Upload the reports instead.');
        }
        $id = $service->stage($contest->id, $detail, $totals, $request->user()->id);

        return redirect()->route('election-imports.show', $id);
    }

    public function show(string $draft, ElectionPublication $service): View
    {
        $preview = $service->preview($draft);

        return view('election-publication', $preview);
    }

    public function publish(Request $request, string $draft, ElectionPublication $service): RedirectResponse
    {
        $request->validate(['reviewed' => 'accepted']);
        $service->publish($draft, $request->user()->id);

        return redirect()->route('election-imports.show', $draft)->with('status', 'Review saved. Changed results were published; matching results were recorded as verified without creating a duplicate public edition.');
    }

    public function restore(Request $request, string $draft, ElectionPublication $service): RedirectResponse
    {
        $service->restore($draft, $request->user()->id);

        return redirect()->route('election-imports.show', $draft)->with('status', 'Previous election results restored. Publication and rollback remain in history.');
    }

    public function download(string $draft, string $file): StreamedResponse
    {
        abort_unless(in_array($file, ['detail', 'totals'], true), 404);
        $record = DB::table('election_publications')->find($draft);
        $path = $record ? ($file === 'detail' ? $record->detail_path : $record->totals_path) : null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'eci-'.$file.'.'.pathinfo($path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff']);
    }
}
