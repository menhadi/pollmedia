<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '775e12dc77eb9f634ba9a490';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2014-jharkhand-duplicate-preview-results-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-jharkhand-complete-');
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
    $analytics = app(HistoricalElectionAnalytics::class);
    $turnout = $results = $reviewed = 0;
    foreach ($records as $record) {
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record);
        if ($summary['turnout_count'] === 1) {
            $turnout++;
        }
        if ($result !== null) {
            $results++;
        }
        if (in_array($record['code'], [22, 75], true)) {
            if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
                || $result['winner'] !== $record['official_detail_result']['winner']
                || $result['margin'] !== $record['official_detail_result']['margin']) {
                throw new RuntimeException("Duplicate-preview result differs at seat {$record['code']}: turnout={$summary['turnout_count']} margin={$summary['margin_count']} result=".json_encode($result));
            }
            $withoutSource = $record;
            unset($withoutSource['turnout_source_sha256']);
            if ($analytics->singleSeatResult($withoutSource) !== null) {
                throw new RuntimeException("Result projects without source at seat {$record['code']}");
            }
            $reviewed++;
        }
    }
    if (count($records) !== 81 || $turnout !== 81 || $results !== 81 || $reviewed !== 2) {
        throw new RuntimeException("Jharkhand coverage differs: records=".count($records)." turnout=$turnout results=$results reviewed=$reviewed");
    }
    echo "Jharkhand 2014: 81/81 turnout and 81/81 source-backed results project\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
