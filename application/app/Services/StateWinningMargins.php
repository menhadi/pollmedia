<?php

namespace App\Services;

use Illuminate\Support\Collection;

class StateWinningMargins
{
    /** Summarize published constituency results within a single report edition. */
    public static function summarize(Collection $seats): array
    {
        $contests = $seats->filter(function (array $seat): bool {
            $result = $seat['result'] ?? [];

            return empty($result['uncontested']) && is_numeric($result['margin'] ?? null) && $result['margin'] >= 0;
        });
        $votes = $contests->pluck('result.margin')->map(fn ($margin) => (int) $margin);
        $lowest = $votes->min();
        $highest = $votes->max();
        $frequencies = $votes->countBy();
        $peak = $frequencies->max();
        $modes = $peak > 1 ? $frequencies->filter(fn ($count) => $count === $peak)->keys()->sort()->values()->all() : [];

        return [
            'count' => $votes->count(),
            'highest' => $highest,
            'lowest' => $lowest,
            'largest_wins' => $contests->where('result.margin', $highest),
            'closest_wins' => $contests->where('result.margin', $lowest),
            'mean' => $votes->avg(),
            'median' => $votes->median(),
            'modes' => $modes,
        ];
    }
}
