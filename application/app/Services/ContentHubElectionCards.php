<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class ContentHubElectionCards
{
    public function payload(string $state, string $kind, array $history, string $channel = 'facebook'): array
    {
        if (! in_array($kind, ['pc', 'ac'], true) || $history === []) {
            throw new RuntimeException('No eligible election history was supplied.');
        }
        $url = rtrim(config('app.url'), '/').'/india/state/'.Str::slug($state).'?election='.$kind;
        $label = $kind === 'pc' ? 'Lok Sabha' : 'Assembly';
        $rows = [];
        $conflicts = [];
        foreach (collect($history)->groupBy('year')->sortKeys() as $year => $editions) {
            $row = $editions->first();
            foreach (['turnout' => 'turnout_count', 'electors' => 'turnout_count', 'polled' => 'turnout_count', 'parties' => 'party_count', 'margin' => 'margin_count', 'winners' => 'margin_count'] as $metric => $count) {
                $signatures = $editions->map(fn ($edition) => json_encode([$edition[$metric] ?? null, $edition[$count], $edition['tables']], JSON_THROW_ON_ERROR))->unique();
                if ($signatures->count() > 1) {
                    $row[$metric] = in_array($metric, ['parties', 'winners'], true) ? [] : null;
                    $conflicts[] = $year.' '.$metric;
                }
            }
            $rows[$year] = $row;
        }
        $cards = [];
        $note = 'Available-table aggregates, not certified statewide totals. Coverage and boundaries vary. Missing values are not zero; no values are estimated.';
        $addLine = function (string $heading, string $unit, callable $value) use (&$cards, $rows, $url, $note): void {
            $values = array_map(fn ($row) => $row === null ? null : $value($row), array_values($rows));
            if (count(array_filter($values, fn ($v) => $v !== null)) < 2) {
                return;
            }
            $cards[] = ['source_url' => $url, 'visual' => ['type' => 'chart', 'chart_style' => 'line', 'heading' => mb_substr($heading, 0, 100), 'unit' => $unit, 'labels' => array_map('strval', array_keys($rows)), 'values' => $values, 'note' => $note]];
        };
        $addLine($state.': '.$label.' turnout', '%', fn ($row) => isset($row['turnout']) ? round($row['turnout'], 2) : null);
        $registered = $cast = [];
        foreach ($rows as $row) {
            $validPair = isset($row['electors'], $row['polled']) && $row['electors'] > 0 && $row['polled'] <= $row['electors'];
            $registered[] = $validPair ? $row['electors'] : null;
            $cast[] = $validPair ? $row['polled'] : null;
        }
        if (count(array_filter($registered, fn ($v) => $v !== null)) >= 2) {
            $cards[] = ['source_url' => $url, 'visual' => ['type' => 'chart', 'chart_style' => 'line', 'heading' => $state.': registered voters vs votes cast', 'unit' => 'people / votes', 'labels' => array_map('strval', array_keys($rows)), 'series' => [['name' => 'Registered voters', 'values' => $registered], ['name' => 'Votes cast', 'values' => $cast]], 'note' => 'Counts from the same validated tables in each year; coverage and boundaries vary. Votes cast is a count, not turnout %. Missing values are not zero.']];
        }
        $eligible = array_filter($rows);
        $latest = $eligible ? end($eligible) : null;
        $partySeries = [];
        foreach (array_slice(array_column($latest['parties'] ?? [], 'party'), 0, 2) as $party) {
            $values = array_map(function ($row) use ($party) {
                $entry = collect($row['parties'])->firstWhere('party', $party);

                return $entry ? round($entry['share'], 2) : null;
            }, array_values($rows));
            if (count(array_filter($values, fn ($v) => $v !== null)) >= 2) {
                $partySeries[] = ['name' => $party, 'values' => $values];
            }
        }
        if (count($partySeries) === 2) {
            $cards[] = ['source_url' => $url, 'visual' => ['type' => 'chart', 'chart_style' => 'line', 'heading' => $state.': '.$label.' party vote share', 'unit' => '%', 'labels' => array_map('strval', array_keys($rows)), 'series' => $partySeries, 'note' => 'Shares use the same available candidate-vote denominator per year. Coverage varies; party aliases are not merged. Missing or disputed values are not zero.']];
        } elseif ($partySeries) {
            $cards[] = ['source_url' => $url, 'visual' => ['type' => 'chart', 'chart_style' => 'line', 'heading' => $state.': '.$partySeries[0]['name'].' vote share', 'unit' => '%', 'labels' => array_map('strval', array_keys($rows)), 'values' => $partySeries[0]['values'], 'note' => $note]];
        }
        $addLine($state.': '.$label.' mean winning margin', 'votes', fn ($row) => isset($row['margin']) ? round($row['margin'], 2) : null);
        if ($latest) {
            $winnerRows = [];
            foreach ($latest['winners'] ?? [] as $winner) {
                $line = $winner['constituency'].': '.$winner['candidate'].' ('.$winner['party'].') — margin '.number_format($winner['margin']).' votes';
                if (mb_strlen($line) <= 160) {
                    $winnerRows[] = $line;
                }
            }
            foreach (array_chunk($winnerRows, 6) as $index => $chunk) {
                if (count($cards) >= 10) {
                    break;
                }
                $cards[] = ['source_url' => $url, 'visual' => ['type' => 'facts', 'heading' => mb_substr($state.' '.$latest['year'].' winners · part '.($index + 1), 0, 100), 'rows' => $chunk, 'note' => 'Selected validated constituency results from '.$latest['year'].'. Each margin is winner votes minus runner-up votes.']];
            }
        }
        if (count($cards) < 2) {
            throw new RuntimeException('Fewer than two substantive source cards are available. No notes-only filler was created.');
        }
        $coverage = [];
        foreach ($rows as $year => $row) {
            $coverage[] = $row ? $year.': turnout '.$row['turnout_count'].'/'.$row['tables'].' tables; party votes '.$row['party_count'].'/'.$row['tables'].'; margins '.$row['margin_count'].'.' : $year.': conflicting editions excluded.';
        }
        $body = $state.' '.$label.' election history. '.$note.' Party charts compare up to two leading parties in the latest available edition; names are kept as reported, not merged across aliases. Registered voters and votes cast are counts from the same tables, not percentages. Mean margins cover eligible constituencies only. Winner lists are selected validated results, not necessarily a complete statewide list. Identical editions are deduplicated. '.implode(' ', $coverage);
        if ($conflicts) {
            $body .= ' Conflicting editions excluded for: '.implode(', ', $conflicts).'.';
        }
        $data = ['category' => 'general', 'channel' => $channel, 'title' => mb_substr($state.' '.$label.' election history', 0, 200), 'body' => $body, 'source_url' => $url, 'source_cards' => $cards];
        $data['external_id'] = 'state-'.Str::slug($state).'-'.$kind.'-'.substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 24);

        return $data;
    }
}
