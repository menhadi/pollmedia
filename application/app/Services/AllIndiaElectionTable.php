<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AllIndiaElectionTable
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private HistoricalElectionArchive $archive,
        private ElectoralMapResults $results,
        private ByElectionRecords $byElections,
        private ConstituencyByElections $byElectionSeats,
    ) {}

    public function page(array $filters): array
    {
        $current = (int) ($filters['page'] ?? 1);
        $type = $filters['type'] ?? '';
        $year = $filters['year'] ?? null;
        $state = $filters['state'] ?? null;
        $name = mb_strtolower(trim($filters['q'] ?? ''));
        $mapEditions = $this->archive->editions('pc');
        $assemblyEditions = $this->archive->editions('ac');
        $base = DB::table('historical_constituency_index')->where(function ($editions) use ($mapEditions, $assemblyEditions) {
            foreach (['pc' => $mapEditions, 'ac' => $assemblyEditions] as $kind => $entries) {
                $ids = array_column($entries, 'id');
                $editions->orWhere(fn ($group) => $group->where('kind', $kind)->whereIn('edition_id', $ids));
            }
        });
        $by = $this->byElections->index();
        $areas = (clone $base)->select('state_label', 'year', 'kind')->selectRaw('count(*) as n')->groupBy('state_label', 'year', 'kind')->get();
        $stateLabels = $areas->pluck('state_label')->merge($by->pluck('state'))->filter()->unique()
            ->mapWithKeys(fn ($value) => [$value => ElectionPlaceIdentity::state($value)]);
        $coverage = $areas->map(fn ($r) => ['year' => (int) $r->year, 'type' => $r->kind, 'state' => $stateLabels[$r->state_label] ?? null, 'n' => $r->n])
            ->concat($by->map(fn ($r) => ['year' => (int) $r['year'], 'type' => 'by', 'state' => $stateLabels[$r['state'] ?? ''] ?? null, 'n' => 1]));
        $nationalCounts = $coverage->groupBy('type')->map->sum('n')->all();
        $annual = $coverage->groupBy('year')->map(fn ($items) => $items->groupBy('type')->map->sum('n'))->sortKeysDesc();
        $years = $annual->keys();
        $inYear = $coverage->when($year !== null, fn ($items) => $items->where('year', (int) $year));
        $types = $inYear->when($state, fn ($items) => $items->where('state', $state))->pluck('type')->unique()->values();
        $states = $inYear->when($type !== '', fn ($items) => $items->where('type', $type))
            ->pluck('state')->filter()->unique()->sort()->values();
        $general = $base->when($year !== null, fn ($q) => $q->where('year', $year))
            ->when($state, fn ($q) => $q->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', [mb_strtolower($state)]));
        $by = $by->filter(fn ($r) => ($year === null || (int) $r['year'] === (int) $year)
            && (! $state || ($stateLabels[$r['state'] ?? ''] ?? null) === $state));
        $seat = $filters['seat'] ?? null;
        $seats = collect();
        if ($year !== null || $state || $seat) {
            if ($type !== 'by') {
                $seats = (clone $general)->when($type !== '', fn ($q) => $q->where('kind', $type))->distinct()->pluck('constituency_name');
            }
            if ($type === '' || $type === 'by') {
                $seats = $seats->merge($by->pluck('constituency'));
            }
            $seats = $seats->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        }
        $general->when($seat !== null, fn ($q) => $q->where('constituency_name', $seat))
            ->when($name !== '', fn ($q) => $q->whereRaw("lower(constituency_name) like ? escape '!'", ['%'.strtr($name, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']));
        $by = $by->filter(fn ($r) => ($seat === null || ($r['constituency'] ?? '') === $seat)
            && ($name === '' || str_contains(mb_strtolower($r['constituency'] ?? ''), $name)))
            ->sortBy([['year', 'desc'], ['state', 'asc'], ['constituency', 'asc'], ['id', 'asc']])->values();

        $indexedCounts = (clone $general)->select('kind')->selectRaw('count(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $counts = ['pc' => (int) ($indexedCounts['pc'] ?? 0), 'ac' => (int) ($indexedCounts['ac'] ?? 0), 'by' => $by->count()];
        if ($type !== '') {
            $counts = [$type => $counts[$type]];
        }
        $total = array_sum($counts);
        $start = $this->prefix($counts, ($current - 1) * self::PAGE_SIZE);
        $end = $this->prefix($counts, $current * self::PAGE_SIZE);
        $rows = [];
        foreach ($counts as $kind => $count) {
            $limit = $end[$kind] - $start[$kind];
            if (! $limit) {
                continue;
            }
            if ($kind === 'by') {
                $items = $by->slice($start[$kind], $limit)->map(fn ($r) => $this->byElectionRow($r))->values()->all();
            } else {
                $entries = (clone $general)->where('kind', $kind)->orderByDesc('year')
                    ->orderBy('state_label')->orderBy('constituency_name')->orderBy('edition_id')->orderBy('record_code')
                    ->offset($start[$kind])->limit($limit)->get();
                $details = collect($this->results->indexedRecords($kind, $entries))->keyBy('id');
                $items = $entries->map(function ($entry) use ($details) {
                    $row = $details[$entry->edition_id.':'.$entry->record_code];

                    return $row + ['type' => $entry->kind, 'candidates' => $entry->candidate_count, 'edition' => $entry->edition_label, 'status' => $entry->status];
                })->all();
            }
            foreach ($items as $index => $item) {
                $rows[] = ['position' => $start[$kind] + $index, 'order' => array_search($kind, ['pc', 'ac', 'by'], true)] + $item;
            }
        }
        $rows = collect($rows)->sortBy([['position', 'asc'], ['order', 'asc']])->values();
        $hasMore = $current * self::PAGE_SIZE < $total;

        return compact('mapEditions', 'annual', 'seats', 'types', 'states', 'years', 'nationalCounts', 'counts', 'rows', 'filters', 'current', 'total', 'hasMore');
    }

    /** Number of records consumed from each stream after interleaving its first $length records. */
    private function prefix(array $counts, int $length): array
    {
        $offsets = array_fill_keys(array_keys($counts), 0);
        $remaining = min($length, array_sum($counts));
        while ($counts) {
            $share = intdiv($remaining, count($counts));
            $exhausted = array_filter($counts, fn ($count) => $count <= $share);
            if (! $exhausted) {
                $extra = $remaining % count($counts);
                foreach (array_keys($counts) as $i => $key) {
                    $offsets[$key] = $share + (int) ($i < $extra);
                }
                break;
            }
            foreach ($exhausted as $key => $count) {
                $offsets[$key] = $count;
                $remaining -= $count;
                unset($counts[$key]);
            }
        }

        return $offsets;
    }

    private function byElectionRow(array $entry): array
    {
        $record = $this->byElections->record($entry);
        $declared = collect($record['candidates'] ?? [])->where('role', 'reported_winner');
        $winner = $declared->count() === 1 ? $declared->first() : null;

        return [
            'id' => $entry['id'], 'type' => 'by', 'kind' => $entry['kind'], 'year' => $entry['year'],
            'state' => $entry['state'], 'name' => $entry['constituency'], 'edition' => $entry['period'] ?? null,
            'winner' => $winner['name'] ?? null, 'party' => $winner['party'] ?? null,
            'candidates' => $entry['candidate_count'] ?? null, 'status' => $entry['status'],
            'url' => $this->byElectionSeats->url($entry) ?? route('elections.by-election-results', ['year' => (int) $entry['year'], 'record' => $entry['id']]),
        ];
    }
}
