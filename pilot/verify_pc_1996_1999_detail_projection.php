<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$root = dirname(__DIR__);
$bundle = $root.'/exports/pollmedia-pc-1996-1999-detailed-results-20261003.zip';
$outer = new ZipArchive;
if ($outer->open($bundle) !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($audit['editions'] as $edition) {
    $id = $edition['edition'];
    $innerBytes = $outer->getFromName("correction-$id.zip");
    if ($innerBytes === false) {
        throw new RuntimeException("Missing correction for $id");
    }
    $temporary = tempnam(sys_get_temp_dir(), 'pc-detail-');
    file_put_contents($temporary, $innerBytes);
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("Correction cannot be opened for $id");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $edition['new_sha256']) {
            throw new RuntimeException("Correction checksum differs for $id");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        $verified = 0;
        foreach ($records as $record) {
            if (($record['source_warning_code'] ?? null) !== 'official_pc_detailed_result_verified') {
                continue;
            }
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record);
            if ($summary['turnout_count'] !== 1 || $summary['party_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['votes_polled']
                || $result['winner'] !== $record['detail_verified_result']['winner']
                || $result['margin'] !== $record['detail_verified_result']['margin']) {
                throw new RuntimeException("App projection differs for $id seat {$record['code']}");
            }
            $verified++;
        }
        if ($verified !== 15) {
            throw new RuntimeException("Expected 15 verified seats for $id; got $verified");
        }
        echo $edition['year']." $verified reviewed PC results project with turnout, winner and margin\n";
    } finally {
        $inner->close();
        unlink($temporary);
    }
}
$outer->close();
