<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$editions = [
    'Goa' => ['9848e8b2829fecdec25effa5', 'goa', 40],
    'Manipur' => ['bd260e1e2bcf7658789e3a85', 'manipur', 60],
    'Punjab' => ['c2f44e0b0b38df67af4d4f6d', 'punjab', 117],
    'Uttarakhand' => ['ad5a2b4658b6047e9d0cd86b', 'uttarakhand', 70],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($editions as $state => [$id, $slug, $expected]) {
    $outer = new ZipArchive;
    if ($outer->open(__DIR__."/../exports/pollmedia-ac-2012-$slug-declared-results-20261003.zip") !== true) {
        throw new RuntimeException("$state 2012 correction bundle cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2012-');
    file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state 2012 correction cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state 2012 extraction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        if (count($records) !== $expected || count($audit['results']) !== $expected) {
            throw new RuntimeException("$state 2012 result coverage differs");
        }
        foreach ($records as $record) {
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record, $id);
            if ($state === 'Uttarakhand' && in_array($record['code'], [4, 6, 67], true)) {
                $discrepancy = $record['source_discrepancy'] ?? null;
                if (($discrepancy['field'] ?? null) !== 'valid_candidate_votes'
                    || $discrepancy['detail_candidate_sum'] !== array_sum(array_column($record['candidates'], 'votes'))
                    || $discrepancy['official_summary_valid'] !== $record['summary_totals']['valid_candidate_votes']
                    || $record['source_warning_code'] !== 'official_summary_turnout_only'
                    || ! str_contains($record['error'], 'Review the linked source for the difference.')) {
                    throw new RuntimeException("Uttarakhand 2012 discrepancy note differs at seat {$record['code']}");
                }
            }
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['votes_polled']
                || $result['winner'] !== $record['summary_result']['winner']
                || $result['party'] !== $record['summary_result']['winner_party']
                || $result['margin'] !== $record['summary_result']['margin']) {
                throw new RuntimeException("$state 2012 app projection differs at seat {$record['code']}");
            }
        }
        echo "$state 2012: $expected AC declarations project with review notes\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
