<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '3fdbf401aeb74266e09309ab';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2007-uttarakhand-declared-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-uttarakhand-');
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
    $codes = array_column($audit['results'], 'code');
    $analytics = app(HistoricalElectionAnalytics::class);
    $verified = 0;
    foreach ($records as $record) {
        if (! in_array($record['code'], $codes, true)) {
            continue;
        }
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['margin'] !== $record['summary_result']['margin']) {
            throw new RuntimeException("App projection differs for Uttarakhand 2007 seat {$record['code']}");
        }
        $verified++;
    }
    $bajpur = collect($records)->firstWhere('code', 59);
    $bajpurResult = $analytics->singleSeatResult($bajpur);
    if ($verified !== 10 || count($records) !== 70 || $bajpur['candidates'] !== []
        || $bajpurResult['winner'] !== 'ARVIND PANDEY' || $bajpurResult['party'] !== 'BJP'
        || $bajpurResult['margin'] !== 3845) {
        throw new RuntimeException('Summary-only Bajpur result does not project');
    }
    echo "$verified Uttarakhand 2007 AC declarations project, including summary-only Bajpur\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
