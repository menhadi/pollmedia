<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VillageController extends Controller
{
    private function dataset(string $year = '2011'): array
    {
        $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'census-pilibhit-villages-'.$year)->where('r.status', 'accepted')->orderByDesc('r.id')
            ->select('r.payload', 'r.retrieved_at', 'r.sha256')->first();
        abort_unless($release && $release->payload, 503, 'Village dataset unavailable.');

        return [json_decode($release->payload, true, 512, JSON_THROW_ON_ERROR), $release];
    }

    private function mapping(): array
    {
        return app(PlaceController::class)->payload('census-pilibhit-block-mapping');
    }

    public function index(Request $request): View
    {
        $request->validate(['year' => 'nullable|in:2011,2001']);
        $year = (string) $request->input('year', '2011');
        [$census, $release] = $this->dataset($year);
        $input = $request->validate(['year' => 'nullable|in:2011,2001', 'district' => 'nullable|in:'.($year === '2001' ? '21' : '151'), 'subdistrict' => 'nullable|in:'.implode(',', array_column($census['subdistricts'], 'code')), 'village' => 'nullable|string|regex:/^[0-9]{6,8}$/', 'page' => 'nullable|integer|min:1']);
        $year = $input['year'] ?? '2011';
        $subdistrict = $input['subdistrict'] ?? '';
        $mapping = $this->mapping();
        $mapped = collect($mapping['rows'])->keyBy('code');
        $blocks = $year === '2011' ? collect($census['villages'])->filter(fn ($v) => $subdistrict === '' || $v['subdistrict_code'] === $subdistrict)->map(fn ($v) => $mapped->get($v['code'])['block'] ?? null)->filter()->unique()->sort()->values() : collect();
        $request->validate(['block' => ['nullable', Rule::in($blocks->all())]]);
        $block = (string) $request->input('block', '');
        $selectedVillage = $input['village'] ?? '';
        $lgd = app(PlaceController::class)->payload('lgd-pilibhit');
        $baseline = collect($census['villages'])->filter(fn ($v) => ($subdistrict === '' || $v['subdistrict_code'] === $subdistrict) && ($block === '' || ($mapped->get($v['code'])['block'] ?? null) === $block));
        $baselineCodes = $baseline->pluck('code')->flip();
        $current = collect($lgd['villages'])->filter(fn ($v) => $baselineCodes->has($v['census_'.$year.'_code']));
        $electoral = app(PlaceController::class)->payload('lgd-pilibhit-electoral');
        $electoralRows = collect($electoral['villages'])->whereIn('lgd_code', $current->pluck('code'));
        $assemblyOptions = $electoralRows->pluck('ac')->unique()->sort()->values();
        $request->validate(['pc' => ['nullable', 'string', Rule::in($electoralRows->pluck('pc')->unique()->all())], 'ac' => ['nullable', 'string', Rule::in($assemblyOptions->all())]]);
        $pc = (string) $request->input('pc', '');
        $ac = (string) $request->input('ac', '');
        if ($pc !== '' || $ac !== '') {
            $electoralCodes = $electoralRows->filter(fn ($r) => ($pc === '' || $r['pc'] === $pc) && ($ac === '' || $r['ac'] === $ac))->pluck('lgd_code');
            $current = $current->whereIn('code', $electoralCodes);
        }
        $currentSubdistricts = $current->map(fn ($v) => ['code' => $v['subdistrict_code'], 'name' => $v['subdistrict_name']])->unique('code')->sortBy('name')->values();
        $request->validate(['current_subdistrict' => ['nullable', 'string', Rule::in($currentSubdistricts->pluck('code')->all())]]);
        $currentSubdistrict = (string) $request->input('current_subdistrict', '');
        $current = $current->filter(fn ($v) => $currentSubdistrict === '' || $v['subdistrict_code'] === $currentSubdistrict);
        $currentBlocks = $current->pluck('blocks')->flatten(1)->unique('code')->sortBy('name')->values();
        $request->validate(['current_block' => ['nullable', 'string', Rule::in($currentBlocks->pluck('code')->all())]]);
        $currentBlock = (string) $request->input('current_block', '');
        $current = $current->filter(fn ($v) => $currentBlock === '' || collect($v['blocks'])->contains('code', $currentBlock));
        $panchayats = $current->pluck('panchayats')->flatten(1)->unique('code')->sortBy('name')->values();
        $request->validate(['panchayat' => ['nullable', 'string', Rule::in($panchayats->pluck('code')->all())]]);
        $panchayat = (string) $request->input('panchayat', '');
        $current = $current->filter(fn ($v) => $panchayat === '' || collect($v['panchayats'])->contains('code', $panchayat));
        $currentCodes = $current->pluck('census_'.$year.'_code')->flip();
        $hasSelection = $subdistrict !== '' || $block !== '' || $currentSubdistrict !== '' || $currentBlock !== '' || $panchayat !== '' || $pc !== '' || $ac !== '';
        $options = $baseline->filter(fn ($v) => $hasSelection && (($currentSubdistrict === '' && $currentBlock === '' && $panchayat === '' && $pc === '' && $ac === '') || $currentCodes->has($v['code'])))->sortBy('name')->values();
        if ($selectedVillage !== '') {
            abort_unless($options->contains('code', $selectedVillage), 422, 'Choose a village within the selected Census subdistrict.');
        }
        $filtered = $options->filter(fn ($v) => $selectedVillage === '' || $v['code'] === $selectedVillage);
        $villages = new LengthAwarePaginator($filtered->forPage((int) $request->input('page', 1), 24)->values(), $filtered->count(), 24, (int) $request->input('page', 1), ['path' => route('villages.index'), 'query' => $request->except(['page', 'q'])]);

        return view('villages', compact('census', 'release', 'villages', 'options', 'year', 'subdistrict', 'selectedVillage', 'mapping', 'blocks', 'block', 'lgd', 'currentSubdistricts', 'currentSubdistrict', 'currentBlocks', 'currentBlock', 'panchayats', 'panchayat', 'hasSelection', 'electoral', 'assemblyOptions', 'pc', 'ac'));
    }

    public function show(Request $request, string $code, string $slug): View|RedirectResponse
    {
        $request->validate(['year' => 'nullable|in:2011,2001']);
        $year = (string) $request->input('year', '2011');
        [$census, $release] = $this->dataset($year);
        $village = collect($census['villages'])->first(fn ($v) => (string) $v['code'] === $code);
        abort_unless($village, 404);
        if ($slug !== Str::slug($village['name'])) {
            return redirect()->route('villages.show', array_filter(['code' => $code, 'slug' => Str::slug($village['name']), 'year' => $year === '2001' ? $year : null]), 301);
        }

        $mapping = $this->mapping();
        $matches = collect($mapping['rows'])->where($year === '2011' ? 'code' : 'previous_code', $code);
        $connection = $matches->count() === 1 ? $matches->first() : null;
        $counterpart = null;
        if ($connection) {
            $otherYear = $year === '2011' ? '2001' : '2011';
            [$otherData] = $this->dataset($otherYear);
            $otherCode = $connection[$year === '2011' ? 'previous_code' : 'code'];
            $reverseMatches = collect($mapping['rows'])->where($year === '2011' ? 'previous_code' : 'code', $otherCode);
            if ($reverseMatches->count() === 1) {
                $counterpart = collect($otherData['villages'])->firstWhere('code', $otherCode);
                if ($counterpart) {
                    $counterpart['year'] = $otherYear;
                }
            }
        }

        $lgd = app(PlaceController::class)->payload('lgd-pilibhit');
        $currentRecords = collect($lgd['villages'])->where('census_'.$year.'_code', $code)->values();
        $electoral = app(PlaceController::class)->payload('lgd-pilibhit-electoral');
        $electoralMatches = collect($electoral['villages'])->whereIn('lgd_code', $currentRecords->pluck('code'))->values();

        return view('village', compact('census', 'release', 'village', 'year', 'mapping', 'connection', 'counterpart', 'lgd', 'currentRecords', 'electoral', 'electoralMatches'));
    }
}
