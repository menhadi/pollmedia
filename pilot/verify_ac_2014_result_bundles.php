<?php

/** Check that the production analytics recognizes every newly attached result. */

require __DIR__.'/../application/vendor/autoload.php';

$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$analytics = app(App\Services\HistoricalElectionAnalytics::class);

$bundles = [
    ['pollmedia-ac-arunachal-2014-summary-results-20261003.zip', '08d56c7504299ea9043a1782', 49],
    ['pollmedia-ac-maharashtra-2014-declared-results-20261003.zip', 'c590e168b33b5fb8f213d092', 10],
];
foreach ($bundles as [$name, $edition, $expected]) {
    $outer = new ZipArchive();
    if ($outer->open(__DIR__.'/../exports/'.$name) !== true) {
        throw new RuntimeException('Outer bundle missing: '.$name);
    }
    $temporary = tempnam(sys_get_temp_dir(), 'pm-election-');
    try {
        file_put_contents($temporary, $outer->getFromName('correction-'.$edition.'.zip'));
        $inner = new ZipArchive();
        if ($inner->open($temporary) !== true) {
            throw new RuntimeException('Inner correction missing: '.$name);
        }
        $data = json_decode($inner->getFromName('election-archive/'.$edition.'/extraction.json'), true, 512, JSON_THROW_ON_ERROR);
        $inner->close();
    } finally {
        $outer->close();
        unlink($temporary);
    }
    $seen = 0;
    foreach ($data['records'] as $record) {
        if (! isset($record['summary_result']) && ! isset($record['official_detail_result'])) {
            continue;
        }
        $result = $analytics->singleSeatResult($record, $edition);
        if ($result === null || $result['derived'] !== false || $result['margin'] === null) {
            throw new RuntimeException('Result not visible: '.$edition.' / '.$record['code']);
        }
        $seen++;
    }
    if ($seen !== $expected) {
        throw new RuntimeException('Projected seat count differs: '.$edition.' / '.$seen);
    }
    echo $name.' projected '.$seen.'/'.$expected.PHP_EOL;
}
