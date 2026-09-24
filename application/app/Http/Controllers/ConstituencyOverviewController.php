<?php

namespace App\Http\Controllers;

use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConstituencyOverviewController extends Controller
{
    public function index(Request $request, HistoricalElectionArchive $history, ElectionArchive $archives, HistoricalElectionReview $reviews): View
    {
        $input = $request->validate(['kind' => 'required|in:pc,ac', 'state' => 'required|string|max:100', 'name' => 'required|string|max:160', 'edition' => 'nullable|regex:/^[a-f0-9]{24}$/']);
        $kind = $input['kind'];
        $state = $input['state'];
        $name = $input['name'];
        $entries = DB::table('historical_constituency_index')->where('kind', $kind)->where('state_label', $state)->whereRaw('LOWER(constituency_name) = ?', [mb_strtolower($name)])->orderByDesc('year')->orderBy('edition_id')->get();
        abort_if($entries->isEmpty(), 404);
        $rows = $entries->map(function ($entry) use ($history, $archives, $reviews) {
            $record = null;
            $source = null;
            try {
                [$data] = $history->load($entry->edition_id, $archives);
                $original = collect($data['records'])->firstWhere('code', $entry->record_code);
                if ($original) {
                    $record = $reviews->apply($entry->edition_id, $original, $data['source_sha256']);
                    $source = $data['source_url'];
                }
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [404, 409, 503])) {
                    throw $error;
                }
            }

            return ['entry' => $entry, 'record' => $record, 'source' => $source];
        });
        $chosen = isset($input['edition']) ? $rows->first(fn ($row) => $row['entry']->edition_id === $input['edition']) : null;
        abort_if(isset($input['edition']) && ! $chosen, 404);
        $latest = $rows->first();

        return view('constituency-overview', compact('kind', 'state', 'name', 'rows', 'chosen', 'latest'));
    }
}
