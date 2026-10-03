<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sources = [
    ['Madhya Pradesh', 'madhya-pradesh', '1ca25a475b1ef838be42b0b8', [34, 36, 40, 44, 47, 61, 62, 67, 139, 216]],
    ['Rajasthan', 'rajasthan', 'f8f3b9152832a818512cd0fb', [23, 25, 98, 124, 144]],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($sources as [$state, $slug, $id, $codes]) {
    $outer = new ZipArchive;
    $path = __DIR__."/../exports/pollmedia-ac-2013-$slug-small-discrepancy-results-20261003.zip";
    if ($outer->open($path) !== true) {
        throw new RuntimeException("$state 2013 correction cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2013-');
    file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state 2013 archive cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state 2013 extraction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        $auditCodes = array_column($audit['results'], 'code');
        sort($auditCodes);
        sort($codes);
        if ($auditCodes !== $codes) {
            throw new RuntimeException("$state 2013 seat coverage differs");
        }
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
                || $record['source_warning_code'] !== 'official_summary_turnout_only'
                || ! str_contains($record['error'], 'still need review')) {
                throw new RuntimeException("$state 2013 projection differs at seat {$record['code']}");
            }
        }
        echo "$state 2013: ".count($codes)." reviewed AC declarations project with source notes\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
