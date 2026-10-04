<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$editions = [
    ['Uttarakhand', 'ad5a2b4658b6047e9d0cd86b', 70, 'uttarakhand'],
    ['Himachal Pradesh', '13651fccf222dbaabb514501', 68, 'himachal'],
    ['Gujarat', '503135d3e838d38c93d3bce7', 182, 'gujarat'],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($editions as [$state, $edition, $seats, $slug]) {
    $path = __DIR__."/../exports/pollmedia-ac-2012-$slug-live-rebased-results-20261004.zip";
    $outer = new ZipArchive;
    if ($outer->open($path) !== true) {
        throw new RuntimeException("$state bundle cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2012-live-');
    file_put_contents($temporary, $outer->getFromName("correction-$edition.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state correction cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$edition/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state extraction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        if (count($records) !== $seats) {
            throw new RuntimeException("$state seat coverage differs");
        }
        foreach ($records as $record) {
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record, $edition);
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['summary_totals']['votes_polled']
                || $result['winner'] !== $record['summary_result']['winner']
                || $result['party'] !== $record['summary_result']['winner_party']
                || $result['margin'] !== $record['summary_result']['margin']) {
                throw new RuntimeException("$state projection differs at seat {$record['code']}");
            }
        }
        echo "$state 2012: $seats source-backed results and turnout totals project\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
