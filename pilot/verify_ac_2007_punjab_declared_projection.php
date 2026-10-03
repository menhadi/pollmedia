<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '8393265a724e9a7a3fa5abf4';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2007-punjab-declared-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-punjab-');
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
    $codes = $audit['codes'];
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
            throw new RuntimeException("App projection differs for Punjab 2007 seat {$record['code']}");
        }
        $verified++;
    }
    if ($verified !== 11) {
        throw new RuntimeException("Expected 11 reviewed seats; got $verified");
    }
    $beas = collect($records)->firstWhere('code', 12);
    $beasSummary = $analytics->summarize([$beas]);
    $beasResult = $analytics->singleSeatResult($beas);
    if ($beas['candidates'] !== [] || $beasSummary['turnout_count'] !== 1
        || $beasSummary['margin_count'] !== 1 || $beasSummary['polled'] !== 109229
        || $beasResult['winner'] !== 'MANJINDER SINGH KANG' || $beasResult['party'] !== 'SAD'
        || $beasResult['margin'] !== 4179) {
        throw new RuntimeException('Summary-only Beas result does not project');
    }
    echo "$verified reviewed Punjab 2007 AC results and summary-only Beas project\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
