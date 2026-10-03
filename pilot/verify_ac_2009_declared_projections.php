<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$editions = [
    'Haryana' => ['4ac73455f798dcf3a8d2946f', 'haryana', 90],
    'Jharkhand' => ['0882c0bd6b8d8738e38b6f06', 'jharkhand', 81],
    'Maharashtra' => ['cc0185e917711e78c149abf4', 'maharashtra', 288],
    'Arunachal Pradesh' => ['1901084c7189cfe9433f1842', 'arunachal-pradesh', 60],
    'Sikkim' => ['172aba6298a6a9113d61177f', 'sikkim', 32],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($editions as $state => [$id, $slug, $expected]) {
    $outer = new ZipArchive;
    $suffix = $state === 'Sikkim' ? '-v2' : '';
    if ($outer->open(__DIR__."/../exports/pollmedia-ac-2009-$slug-declared-results-20261003$suffix.zip") !== true) {
        throw new RuntimeException("$state correction bundle cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2009-');
    file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state correction cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state correction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        $uncontestedCodes = $state === 'Arunachal Pradesh' ? [1, 2, 3] : [];
        if (count($records) !== $expected || count($audit['results']) !== $expected - count($uncontestedCodes)) {
            throw new RuntimeException("$state result coverage differs");
        }
        foreach ($records as $record) {
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record, $id);
            if (in_array($record['code'], $uncontestedCodes, true)) {
                if ($summary['turnout_count'] !== 0 || $summary['margin_count'] !== 0
                    || $result['uncontested'] !== true || $result['margin'] !== null
                    || $result['winner'] !== $record['candidates'][0]['candidate_name']
                    || $result['party'] !== $record['candidates'][0]['party_at_election']) {
                    throw new RuntimeException("$state uncontested seat {$record['code']} differs");
                }
                continue;
            }
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['votes_polled']
                || $result['winner'] !== $record['summary_result']['winner']
                || $result['party'] !== $record['summary_result']['winner_party']
                || $result['margin'] !== $record['summary_result']['margin']) {
                throw new RuntimeException("$state app projection differs at seat {$record['code']}: ".json_encode([
                    'turnout_count' => $summary['turnout_count'], 'margin_count' => $summary['margin_count'],
                    'polled' => $summary['polled'], 'result' => $result,
                    'expected_result' => $record['summary_result'],
                ]));
            }
        }
        echo "$state: $expected AC declarations project with review notes\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
