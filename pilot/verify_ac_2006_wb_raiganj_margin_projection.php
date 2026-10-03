<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '47d0505498dc2d0ffce6c308';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2006-wb-raiganj-margin-review-20261003.zip') !== true) {
    throw new RuntimeException('Correction bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-wb-margin-');
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
    $record = collect(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'])->firstWhere('code', 31);
    $analytics = app(HistoricalElectionAnalytics::class);
    $summary = $analytics->summarize([$record]);
    $result = $analytics->singleSeatResult($record);
    if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
        || $summary['margin'] !== 15760 || $summary['polled'] !== 166755
        || $result['winner'] !== 'CHITTA RANJAN RAY' || $result['party'] !== 'INC'
        || $result['margin'] !== 15760 || $record['summary_reported_margin'] !== 16103
        || ! str_contains($record['error'], '16,103') || ! str_contains($record['error'], '15,760')) {
        throw new RuntimeException('Raiganj projection or discrepancy note differs');
    }
    echo "Raiganj 2006 winner and calculated margin project with printed conflict retained\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
