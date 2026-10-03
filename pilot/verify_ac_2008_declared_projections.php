<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$editions = [
    'Mizoram' => ['51a30ea845222a74460e8585', 'mizoram', 40],
    'Tripura' => ['c2b9ef2bc73bbcc70a271a58', 'tripura', 60],
    'Meghalaya' => ['d82c217822e367756f2aad6e', 'meghalaya', 60],
    'Nagaland' => ['044bf7c98b9f57f1edb7ab5b', 'nagaland', 60],
    'Chhattisgarh' => ['0879916bcfd2b319f6728f33', 'chhattisgarh', 90],
];
$analytics = app(HistoricalElectionAnalytics::class);
foreach ($editions as $state => [$id, $slug, $expected]) {
    $outer = new ZipArchive;
    if ($outer->open(__DIR__."/../exports/pollmedia-ac-2008-$slug-declared-results-20261003.zip") !== true) {
        throw new RuntimeException("$state correction bundle cannot be opened");
    }
    $audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
    $temporary = tempnam(sys_get_temp_dir(), 'ac-2008-');
    file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
    $inner = new ZipArchive;
    try {
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException("$state correction cannot be opened");
        }
        $body = $inner->getFromName("election-archive/$id/extraction.json");
        if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
            throw new RuntimeException("$state correction checksum differs");
        }
        $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
        if (count($records) !== $expected || count($audit['results']) !== $expected) {
            throw new RuntimeException("$state result coverage differs");
        }
        foreach ($records as $record) {
            $summary = $analytics->summarize([$record]);
            $result = $analytics->singleSeatResult($record);
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $summary['polled'] !== $record['votes_polled']
                || $result['winner'] !== $record['summary_result']['winner']
                || $result['party'] !== $record['summary_result']['winner_party']
                || $result['margin'] !== $record['summary_result']['margin']) {
                throw new RuntimeException("$state app projection differs at seat {$record['code']}");
            }
        }
        echo "$state: $expected AC declarations project with review notes\n";
    } finally {
        $inner->close();
        $outer->close();
        unlink($temporary);
    }
}
