<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = 'c2b9ef2bc73bbcc70a271a58';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2008-tripura-declared-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-tripura-');
file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Correction cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$id/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Correction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    $analytics = app(HistoricalElectionAnalytics::class);
    $verified = 0;
    foreach ($records as $record) {
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['party'] !== $record['summary_result']['winner_party']
            || $result['margin'] !== $record['summary_result']['margin']) {
            throw new RuntimeException("App projection differs for Tripura 2008 seat {$record['code']}");
        }
        $verified++;
    }
    if ($verified !== 60 || count($audit['results']) !== 60) {
        throw new RuntimeException("Expected 60 reviewed seats; got $verified");
    }
    echo "$verified Tripura 2008 AC declarations project with review notes\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
