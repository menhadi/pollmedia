<?php

/** Audit what the current application would display from one sealed correction bundle. */

require __DIR__.'/../application/vendor/autoload.php';

$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($argc !== 3 || ! is_file($argv[1]) || ! preg_match('/^[a-f0-9]{24}$/', $argv[2])) {
    fwrite(STDERR, "Usage: php pilot/audit_election_bundle_projection.php BUNDLE.zip EDITION_ID\n");
    exit(2);
}
[$program, $bundle, $edition] = $argv;
$outer = new ZipArchive();
if ($outer->open($bundle) !== true) {
    throw new RuntimeException('Could not open outer bundle');
}
$temporary = tempnam(sys_get_temp_dir(), 'pm-election-');
try {
    $payload = $outer->getFromName('correction-'.$edition.'.zip');
    if ($payload === false || file_put_contents($temporary, $payload) !== strlen($payload)) {
        throw new RuntimeException('Correction is absent from the bundle');
    }
    $inner = new ZipArchive();
    if ($inner->open($temporary) !== true) {
        throw new RuntimeException('Could not open inner correction');
    }
    $body = $inner->getFromName('election-archive/'.$edition.'/extraction.json');
    $inner->close();
    if ($body === false) {
        throw new RuntimeException('Edition extraction is absent');
    }
} finally {
    $outer->close();
    unlink($temporary);
}
$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$analytics = app(App\Services\HistoricalElectionAnalytics::class);
$counts = ['records' => 0, 'single_seat' => 0, 'visible_result' => 0, 'hidden_result_with_candidate_votes' => 0,
    'positive_turnout' => 0, 'blank_turnout' => 0];
$hidden = [];
foreach ($data['records'] as $record) {
    $counts['records']++;
    if (($record['number_of_seats'] ?? 1) !== 1) {
        continue;
    }
    $counts['single_seat']++;
    if (is_int($record['votes_polled'] ?? null) && $record['votes_polled'] > 0) {
        $counts['positive_turnout']++;
    } else {
        $counts['blank_turnout']++;
    }
    $result = $analytics->singleSeatResult($record, $edition);
    if ($result !== null) {
        $counts['visible_result']++;
    } elseif (count(array_filter($record['candidates'] ?? [], fn ($row) => ! ($row['is_nota'] ?? false)
        && ($row['votes'] ?? 0) > 0)) >= 2) {
        $counts['hidden_result_with_candidate_votes']++;
        $hidden[] = ['code' => $record['code'], 'name' => $record['name'],
            'source_warning_code' => $record['source_warning_code'] ?? null];
    }
}
echo json_encode(['edition' => $edition, 'year' => $data['year'], 'kind' => $data['kind'],
    'counts' => $counts, 'hidden' => $hidden], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
