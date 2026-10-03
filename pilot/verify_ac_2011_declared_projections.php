<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$editions = [
    'Assam' => ['6f98f0d1117aa60cd856fc72', 'assam', 126],
    'Kerala' => ['759495318f66052900b770f9', 'kerala', 140],
    'Puducherry' => ['d964b3bce62e657ee36db2cb', 'puducherry', 30],
    'Tamil Nadu' => ['23cc38bf6d2d08576d05c3ff', 'tamil-nadu', 234],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($editions as $state => [$id, $slug, $expected]) {
    $outer = new ZipArchive;
    if ($outer->open(__DIR__."/../exports/pollmedia-ac-2011-$slug-declared-results-20261003.zip") !== true) {
        throw new RuntimeException("$state 2011 correction bundle cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2011-');
    file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state 2011 correction cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state 2011 extraction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        if (count($records) !== $expected || count($audit['results']) !== $expected) {
            throw new RuntimeException("$state 2011 result coverage differs");
        }
        foreach ($records as $record) {
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record, $id);
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['votes_polled']
                || $result['winner'] !== $record['summary_result']['winner']
                || $result['party'] !== $record['summary_result']['winner_party']
                || $result['margin'] !== $record['summary_result']['margin']) {
                throw new RuntimeException("$state 2011 app projection differs at seat {$record['code']}");
            }
        }
        echo "$state 2011: $expected AC declarations project with review notes\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
