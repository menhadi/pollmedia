<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '576acbcd20ffc1f7500abcf7';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2010-bihar-declared-results-20261003.zip') !== true) {
    throw new RuntimeException('Bihar 2010 correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-2010-');
file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Bihar 2010 correction cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$id/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Bihar 2010 extraction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    if (count($records) !== 243 || count($audit['results']) !== 243) {
        throw new RuntimeException('Bihar 2010 result coverage differs');
    }
    $analytics = app(HistoricalElectionAnalytics::class);
    foreach ($records as $record) {
        $summary = $analytics->summarize([$record]);
        $result = $analytics->singleSeatResult($record, $id);
        if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
            || $summary['polled'] !== $record['votes_polled']
            || $result['winner'] !== $record['summary_result']['winner']
            || $result['party'] !== $record['summary_result']['winner_party']
            || $result['margin'] !== $record['summary_result']['margin']) {
            throw new RuntimeException("Bihar 2010 app projection differs at seat {$record['code']}");
        }
    }
    echo "Bihar 2010: 243 AC declarations project with review notes\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
