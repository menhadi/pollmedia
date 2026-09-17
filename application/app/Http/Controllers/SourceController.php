<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(Request $request): View
    {
        $publishers = DB::table('data_sources')->distinct()->orderBy('publisher')->pluck('publisher');
        $input = $request->validate(['publisher' => ['nullable', 'string', Rule::in($publishers->all())]]);
        $publisher = $input['publisher'] ?? '';
        $sources = DB::table('data_sources')->when($publisher !== '', fn ($q) => $q->where('publisher', $publisher))->orderBy('publisher')->orderBy('key')->get();
        $releases = DB::table('source_releases')->whereIn('data_source_id', $sources->pluck('id'))
            ->select('id', 'data_source_id', 'retrieved_at', 'published_on', 'status', 'sha256', 'url')->orderByDesc('id')->get()->groupBy('data_source_id');
        foreach ($sources as $source) {
            $editions = $releases->get($source->id, collect());
            $source->release = $editions->firstWhere('status', 'accepted');
            $source->other_editions = $editions->where('status', '!=', 'accepted')->count();
            $source->label = Str::headline($source->key);
            $source->import_age = $source->release ? (int) Carbon::parse($source->release->retrieved_at)->startOfDay()->diffInDays(today()) : null;
            $source->monitor = DB::table('source_checks')->where('data_source_id', $source->id)->orderByDesc('id')->first();
            $source->review_pending = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', '!=', 'failed')->orderByDesc('id')->value('status') === 'changed';
        }

        return view('sources', compact('sources', 'publishers', 'publisher'));
    }
}
