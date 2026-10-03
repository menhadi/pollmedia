<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '775e12dc77eb9f634ba9a490';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2014-jharkhand-single-page-detail-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-jharkhand-detail-');
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
        if (! in_array($record['code'], [43, 55], true)) {
            continue;
        }
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['official_detail_result']['winner']
            || $result['party'] !== $record['official_detail_result']['winner_party']
            || $result['margin'] !== $record['official_detail_result']['margin']) {
            throw new RuntimeException("App projection differs for Jharkhand 2014 seat {$record['code']}");
        }
        $withoutSource = $record;
        unset($withoutSource['turnout_source_sha256']);
        if ($analytics->singleSeatResult($withoutSource) !== null) {
            throw new RuntimeException("Result projects without verified source at {$record['code']}");
        }
        $verified++;
    }
    if ($verified !== 2) {
        throw new RuntimeException("Expected two reviewed seats; got $verified");
    }
    echo "$verified reviewed Jharkhand 2014 AC detailed results project\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
