<?php

namespace App\Services;

final class MyPlaceOverview
{
    public function previews(array $context): array
    {
        $gp = null;
        $payments = [];
        $state = (string) $context['state']['code'];
        $district = (string) $context['district']['code'];
        if ($context['village']) {
            $gp = app(GramPanchayatDirectory::class)->forVillage((int) $state, (int) $context['village']['code']);
            if ($gp) {
                $payments = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, ['namespace' => 'LGD Gram Panchayat', 'code' => (string) $gp['gp']]);
            }
        }

        $records = collect(app(OfficialMarketPrices::class)->overviewRecords($context, true));
        if ($state === '9' && $district === '173') {
            $snapshot = json_decode(file_get_contents(database_path('fixtures/pilibhit-anchor-observations.json')), true, flags: JSON_THROW_ON_ERROR);
            $records = $records->concat($snapshot['market_snapshot']['records']);
        }

        $prices = $records->filter(fn ($row) => filled($row['commodity'] ?? null) && filled($row['unit'] ?? null) && is_numeric($row['modal'] ?? null) && filled($row['market'] ?? null) && filled($row['date'] ?? null))
            ->sortBy([['date', 'desc'], ['commodity', 'asc'], ['market', 'asc'], ['variety', 'asc'], ['grade', 'asc']])
            ->unique(fn ($row) => strtolower(trim($row['commodity'])))->take(3)->values();

        $issue = app(MyPlaceHub::class)->data($context, 'local-issues')['issues']->first();
        $soil = $context['village'] ? app(VillageSummaryPage::class)->nitrogen($state, (string) $context['village']['code']) : null;

        return compact('gp', 'payments', 'prices', 'issue', 'soil');
    }
}
