<?php

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../application/vendor/autoload.php';
$app = require __DIR__.'/../application/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$release = __DIR__.'/../exports/pollmedia-pc-ac-detailed-source-turnout-corrections-20261001-v2.zip';
if (hash_file('sha256', $release) !== 'c83cf7ba6f7b7fe67a04fa111b28b3427370d8f97eb92446ffffe9e7c581dbe7') {
    throw new RuntimeException('Saved election correction release differs');
}
$editions = [
    'c7a9e523186004c713736633' => [141, 173, 187, 200, 256, 257, 258, 259, 260, 264, 394],
    'ed5e5cef04a9edb821cf27af' => [11],
];
$outer = new ZipArchive;
if ($outer->open($release) !== true) {
    throw new RuntimeException('Saved election release cannot be opened');
}
$audit = json_decode($outer->getFromName('AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
$analytics = app(HistoricalElectionAnalytics::class);
$checked = 0;
try {
    foreach ($editions as $edition => $codes) {
        $entry = collect($audit['editions'])->firstWhere('edition', $edition);
        $temporary = tempnam(sys_get_temp_dir(), 'ac-2017-detail-');
        file_put_contents($temporary, $outer->getFromName("correction-$edition.zip"));
        $inner = new ZipArchive;
        try {
            if ($inner->open($temporary) !== true) {
                throw new RuntimeException("Correction cannot be opened for $edition");
            }
            $body = $inner->getFromName("election-archive/$edition/extraction.json");
            if ($body === false || hash('sha256', $body) !== $entry['new_sha256']) {
                throw new RuntimeException("Correction checksum differs for $edition");
            }
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['records'] as $record) {
                if (! in_array($record['code'], $codes, true)) {
                    continue;
                }
                $ranked = collect($record['candidates'])->reject(fn ($c) => $c['is_nota'])
                    ->sortByDesc('votes')->values();
                $result = $analytics->singleSeatResult($record);
                $summary = $analytics->summarize([$record]);
                if ($result === null || $result['winner'] !== $ranked[0]['candidate_name']
                    || $result['party'] !== $ranked[0]['party_at_election']
                    || $result['margin'] !== $ranked[0]['votes'] - $ranked[1]['votes']
                    || $summary['margin_count'] !== 1 || $summary['turnout_count'] !== 1) {
                    throw new RuntimeException("Reviewed result does not project for $edition code {$record['code']}");
                }
                $withoutSource = $record;
                unset($withoutSource['turnout_source_sha256']);
                if ($analytics->singleSeatResult($withoutSource) !== null) {
                    throw new RuntimeException("Result projects without source for $edition code {$record['code']}");
                }
                $checked++;
            }
        } finally {
            $inner->close();
            unlink($temporary);
        }
    }
} finally {
    $outer->close();
}
if ($checked !== 12) {
    throw new RuntimeException("Expected 12 reviewed results, found $checked");
}
echo "$checked source-backed 2017 AC results project\n";
