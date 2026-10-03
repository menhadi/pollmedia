<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = 'd6d8e8eaa48a3eb8251003d5';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2013-karnataka-aland-declared-result-20261003.zip') !== true) {
    throw new RuntimeException('Aland correction cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-aland-');
file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Aland correction archive cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$id/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Aland extraction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    if (count($records) !== 224 || count($audit['results']) !== 1 || $audit['results'][0]['code'] !== 46) {
        throw new RuntimeException('Aland correction coverage differs');
    }
    $record = collect($records)->firstWhere('code', 46);
    $analytics = app(HistoricalElectionAnalytics::class);
    $summary = $analytics->summarize([$record]);
    $result = $analytics->singleSeatResult($record, $id);
    if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
        || $summary['electors'] !== $record['summary_totals']['electors']
        || $summary['polled'] !== $record['votes_polled']
        || $result['winner'] !== $record['summary_result']['winner']
        || $result['party'] !== $record['summary_result']['winner_party']
        || $result['margin'] !== 17114
        || ! str_contains($record['error'], 'Detailed electors: 192,986')) {
        throw new RuntimeException('Aland app projection differs');
    }
    echo "Karnataka 2013 Aland: reviewed declaration projects with official turnout and source notes\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
