<?php

namespace App\Services;

class SourceDiscrepancy
{
    /**
     * Resolve the detailed and summary votes-polled totals for a votes_polled discrepancy.
     *
     * Extraction bundles record the same comparison in two shapes: the newer
     * {detail_value, summary_value} keys and the older {detail_votes_polled,
     * summary_votes_polled} keys. Both are accepted so reports render the note
     * instead of failing on a missing key.
     *
     * @param  array<string, mixed>  $record
     * @return array{detail: int, summary: int}|null
     */
    public static function votesPolled(array $record): ?array
    {
        $difference = $record['source_discrepancy'] ?? null;
        if (! is_array($difference) || ($difference['field'] ?? '') !== 'votes_polled') {
            return null;
        }

        $detail = $difference['detail_value'] ?? $difference['detail_votes_polled'] ?? $record['detail_votes_polled'] ?? null;
        $summary = $difference['summary_value'] ?? $difference['summary_votes_polled'] ?? $record['votes_polled'] ?? null;
        if (! is_numeric($detail) || ! is_numeric($summary)) {
            return null;
        }

        return ['detail' => (int) $detail, 'summary' => (int) $summary];
    }
}
