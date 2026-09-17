<?php

namespace App\Http\Controllers;

use App\Services\ElectionBatch;
use App\Services\ElectionPublication;
use App\Services\StateElectionPublication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ElectionBatchController extends Controller
{
    public function index(): View
    {
        $batches = DB::table('election_import_batches')->orderByDesc('id')->paginate(20);

        return view('election-batches', compact('batches'));
    }

    public function store(Request $request, ElectionBatch $service): RedirectResponse
    {
        $request->validate(['state' => 'required|in:uttar-pradesh', 'year' => 'required|in:2012,2017,2022']);
        $id = $service->create($request->user()->id, (int) $request->input('year'));

        return redirect()->route('election-batches.show', $id);
    }

    public function show(Request $request, string $batch): View
    {
        $record = DB::table('election_import_batches')->find($batch);
        abort_unless($record, 404);
        $input = $request->validate(['status' => 'nullable|in:ready,invalid', 'code' => 'nullable|integer|min:1|max:403']);
        $query = DB::table('election_import_batch_rows')->where('batch_id', $batch);
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }
        $rows = $query->orderBy('code')->paginate(50)->withQueryString();
        $options = DB::table('election_import_batch_rows')->where('batch_id', $batch)->orderBy('code')->get(['code', 'name']);
        $selected = isset($input['code']) ? DB::table('election_import_batch_rows')->where('batch_id', $batch)->where('code', $input['code'])->first() : null;
        $payload = $selected?->payload ? json_decode($selected->payload, true, 512, JSON_THROW_ON_ERROR) : null;
        $mapped = $selected && $selected->status === 'ready' ? $this->existingContest($record, $selected->code) : null;

        return view('election-batch', compact('record', 'rows', 'options', 'selected', 'payload', 'mapped', 'input'));
    }

    private function existingContest(object $batch, int $code): ?object
    {
        foreach (app(ElectionPublication::class)->contests()->where('type', 'ac')->where('year', 2022) as $contest) {
            $payload = json_decode(DB::table('source_releases')->where('id', $contest->source_release_id)->value('payload'), true);
            if (($payload['code'] ?? null) === $code && ($payload['sha256'] ?? null) === $batch->detail_sha256 && ($payload['totals_sha256'] ?? null) === $batch->summary_sha256) {
                return $contest;
            }
        }

        return null;
    }

    public function review(Request $request, string $batch, int $code, ElectionBatch $service, ElectionPublication $publication): RedirectResponse
    {
        $record = DB::table('election_import_batches')->find($batch);
        $row = DB::table('election_import_batch_rows')->where('batch_id', $batch)->where('code', $code)->first();
        abort_unless($record && $row && $row->status === 'ready', 422, 'This row is not ready for review.');
        $contest = $this->existingContest($record, $code);
        abort_unless($contest, 422, 'A verified constituency mapping is required before publication review.');
        $service->verifyFiles($record);
        $id = $publication->stage($contest->id, Storage::disk('local')->path($record->detail_path), Storage::disk('local')->path($record->summary_path), $request->user()->id);

        return redirect()->route('election-imports.show', $id);
    }

    public function publish(Request $request, string $batch, StateElectionPublication $service): RedirectResponse
    {
        $request->validate(['reviewed' => 'accepted']);
        $service->publish($batch, $request->user()->id);

        return redirect()->route('election-batches.show', $batch);
    }

    public function retry(string $batch, ElectionBatch $service): RedirectResponse
    {
        $service->retry($batch);

        return redirect()->route('election-batches.show', $batch);
    }
}
