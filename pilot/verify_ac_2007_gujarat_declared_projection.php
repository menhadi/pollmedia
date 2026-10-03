<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '25d92852e9975cf32d1b94bd';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2007-gujarat-declared-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-gujarat-');
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
            throw new RuntimeException("App projection differs for Gujarat 2007 seat {$record['code']}");
        }
        if ($record['code'] === 1) {
            $withoutSource = $record;
            unset($withoutSource['summary_source_sha256']);
            $unverified = $analytics->summarize([$withoutSource]);
            if ($analytics->singleSeatResult($withoutSource) !== null || $unverified['margin_count'] !== 0) {
                throw new RuntimeException('Gujarat winner projects without verified summary provenance');
            }
        }
        $verified++;
    }
    if ($verified !== 182) {
        throw new RuntimeException("Expected 182 reviewed seats; got $verified");
    }
    echo "$verified reviewed Gujarat 2007 AC results project with turnout, winner and margin\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
