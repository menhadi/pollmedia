<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '775e12dc77eb9f634ba9a490';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2014-jharkhand-summary-only-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-jharkhand-');
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
        if (($record['source_warning_code'] ?? null) !== 'summary_only_turnout') {
            continue;
        }
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['party_count'] !== 0 || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['party'] !== $record['summary_result']['winner_party']
            || $result['margin'] !== $record['summary_result']['margin']) {
            throw new RuntimeException("App projection differs for Jharkhand 2014 seat {$record['code']}");
        }
        $withoutSource = $record;
        unset($withoutSource['summary_source_sha256']);
        $unverified = $analytics->summarize([$withoutSource]);
        if ($analytics->singleSeatResult($withoutSource) !== null || $unverified['margin_count'] !== 0) {
            throw new RuntimeException("Result projects without verified summary provenance at {$record['code']}");
        }
        $verified++;
    }
    if ($verified !== 45) {
        throw new RuntimeException("Expected 45 reviewed seats; got $verified");
    }
    echo "$verified reviewed Jharkhand 2014 AC results project with turnout, winner and margin\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
