<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = '7a8ef9c9247cb626f6a03998';
$outer = new ZipArchive;
if ($outer->open(__DIR__.'/../exports/pollmedia-ac-2014-andhra-satyavedu-detail-result-20261003.zip') !== true) {
    throw new RuntimeException('Satyavedu correction cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-satyavedu-');
file_put_contents($temporary, $outer->getFromName("correction-$id.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Satyavedu correction archive cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$id/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Satyavedu extraction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    if (count($records) !== 294 || count($audit['results']) !== 1 || $audit['results'][0]['code'] !== 288) {
        throw new RuntimeException('Satyavedu correction coverage differs');
    }
    $record = collect($records)->firstWhere('code', 288);
    $analytics = app(HistoricalElectionAnalytics::class);
    $summary = $analytics->summarize([$record]);
    $result = $analytics->singleSeatResult($record, $id);
    if ($summary['turnout_count'] !== 1 || $summary['margin_count'] !== 1
        || $summary['polled'] !== 161036 || $result['winner'] !== 'TALARI ADITYA'
        || $result['party'] !== 'TDP' || $result['margin'] !== 4227
        || $record['error'] !== 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.') {
        throw new RuntimeException('Satyavedu app projection differs');
    }
    echo "Andhra Pradesh 2014 Satyavedu: source-backed result projects with reviewed turnout\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
