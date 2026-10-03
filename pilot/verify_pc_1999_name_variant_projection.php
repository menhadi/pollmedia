<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$bundle = dirname(__DIR__).'/exports/pollmedia-pc-1999-name-variant-results-20261003.zip';
$outer = new ZipArchive;
if ($outer->open($bundle) !== true) {
    throw new RuntimeException('1999 correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$edition = $audit['edition'];
$temporary = tempnam(sys_get_temp_dir(), 'pc-1999-');
file_put_contents($temporary, $outer->getFromName("correction-$edition.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('1999 correction cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$edition/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('1999 correction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    $analytics = app(HistoricalElectionAnalytics::class);
    $verified = 0;
    foreach ($records as $record) {
        if (($record['source_warning_code'] ?? null) !== 'official_pc_summary_reconciled_detail_warning') {
            continue;
        }
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] !== 1 || $summary['party_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['margin'] !== $record['summary_result']['margin']) {
            throw new RuntimeException("1999 result projection differs for seat {$record['code']}");
        }
        $verified++;
    }
    if ($verified !== 118) {
        throw new RuntimeException("Expected 118 reviewed results; got $verified");
    }
    echo "$verified reviewed 1999 PC results project with turnout, winner and margin\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
