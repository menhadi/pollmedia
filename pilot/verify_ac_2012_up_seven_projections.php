<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = 'f4df4876a829786cd04cb280';
$path = __DIR__.'/../exports/pollmedia-ac-2012-up-seven-declared-results-20261003.zip';
$outer = new ZipArchive;
if ($outer->open($path) !== true) {
    throw new RuntimeException('Uttar Pradesh 2012 bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-up-2012-');
file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Uttar Pradesh 2012 correction cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$id/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Uttar Pradesh 2012 correction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    $codes = array_column($audit['results'], 'code');
    sort($codes);
    if (count($records) !== 403 || $codes !== [6, 59, 108, 110, 236, 283, 318]) {
        throw new RuntimeException('Uttar Pradesh 2012 coverage differs');
    }
    $analytics = app(HistoricalElectionAnalytics::class);
    foreach ($records as $record) {
        if (! in_array($record['code'], $codes, true)) {
            continue;
        }
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record, $id);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['party'] !== $record['summary_result']['winner_party']
            || $result['margin'] !== $record['summary_result']['margin']
            || $record['source_warning_code'] !== 'official_summary_turnout_only') {
            throw new RuntimeException("Uttar Pradesh 2012 projection differs at seat {$record['code']}");
        }
    }
    echo "Uttar Pradesh 2012: seven reviewed AC declarations project with official turnout and source notes\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
