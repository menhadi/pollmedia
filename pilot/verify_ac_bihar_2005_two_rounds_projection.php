<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$edition = 'faf93ea0918e67d6bc68067a';
$path = __DIR__.'/../exports/pollmedia-ac-bihar-2005-two-round-summaries-20261003-v2.zip';
if (hash_file('sha256', $path) !== 'a6a9e81a8a09468161b374ec88526aa252744fd284528b1e9516948034298f52') {
    throw new RuntimeException('Bihar two-round bundle checksum differs');
}
$outer = new ZipArchive;
if ($outer->open($path) !== true) {
    throw new RuntimeException('Bihar two-round bundle cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$temporary = tempnam(sys_get_temp_dir(), 'ac-bihar-2005-');
file_put_contents($temporary, $outer->getFromName("correction-$edition.zip"));
$inner = new ZipArchive;
try {
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Bihar two-round correction cannot be opened');
    }
    $body = $inner->getFromName("election-archive/$edition/extraction.json");
    if ($body === false || hash('sha256', $body) !== $audit['new_sha256']) {
        throw new RuntimeException('Bihar two-round extraction checksum differs');
    }
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    $analytics = app(HistoricalElectionAnalytics::class);
    $rounds = ['2005-feb' => [], '2005-oct' => []];
    foreach ($records as $record) {
        $round = $record['election_round'];
        $code = $record['official_ac_code'];
        $result = $analytics->singleSeatResult($record, $edition);
        $declared = $record['summary_result'];
        if (! isset($rounds[$round]) || isset($rounds[$round][$code])
            || $result['winner'] !== $declared['winner']
            || $result['party'] !== $declared['winner_party']
            || $result['margin'] !== $declared['margin']) {
            throw new RuntimeException("Bihar declared result does not project: $round $code");
        }
        $rounds[$round][$code] = true;
    }
    foreach (['2005-feb', '2005-oct'] as $round) {
        $sample = current(array_filter($records, fn (array $record): bool => $record['election_round'] === $round));
        foreach ([
            ['election_round', '2005-other'],
            ['code', 999999],
            ['summary_source_sha256', str_repeat('0', 64)],
            ['summary_page', 0],
            ['original_extraction_warning', null],
            ['previous_review_note', null],
        ] as [$field, $value]) {
            $altered = $sample;
            $altered[$field] = $value;
            if ($analytics->singleSeatResult($altered, $edition) !== null) {
                throw new RuntimeException("Uncorroborated Bihar result projected: $round $field");
            }
        }
    }
    $summary = $analytics->summarize($records);
    if (count($rounds['2005-feb']) !== 243 || count($rounds['2005-oct']) !== 243
        || $summary['tables'] !== 486 || $summary['turnout_count'] !== 486
        || $summary['margin_count'] !== 486 || count($summary['winners']) !== 486) {
        throw new RuntimeException('Bihar February/October rounds were lost or merged');
    }
    echo "486 Bihar 2005 AC results and turnout rows project across two distinct rounds\n";
} finally {
    $inner->close();
    $outer->close();
    unlink($temporary);
}
