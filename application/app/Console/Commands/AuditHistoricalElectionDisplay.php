<?php

namespace App\Console\Commands;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditHistoricalElectionDisplay extends Command
{
    protected $signature = 'archive:audit-election-display {--csv= : New CSV path for every missing or suppressed PC/AC value}';

    protected $description = 'Read-only year-wise audit of imported PC/AC source values and what public analytics display';

    public function handle(HistoricalElectionAnalytics $analytics, HistoricalElectionReview $reviews): int
    {
        $path = $this->option('csv');
        $csv = null;
        $completed = false;
        $years = [];
        $editions = $tables = 0;

        try {
            if ($path !== null) {
                $csv = @fopen($path, 'x');
                if ($csv === false) {
                    throw new RuntimeException('CSV path must be new and its parent directory must exist.');
                }
                fputcsv($csv, ['kind', 'year', 'state', 'constituency', 'edition_id', 'code', 'issue', 'source_electors', 'source_votes_polled', 'displayed_votes_polled', 'source_candidate_count', 'displayed_winner', 'status', 'source_url', 'extraction_sha256', 'source_note']);
            }

            DB::table('archive_json_files')->where('category', 'election-archive')
                ->where('path', 'like', 'election-archive/%/extraction.json')
                ->select(['path_hash', 'path', 'sha256', 'bytes', 'body'])
                ->chunkById(1, function ($files) use ($analytics, $reviews, $csv, &$years, &$editions, &$tables): void {
                    foreach ($files as $file) {
                        if (! preg_match('~^election-archive/([a-f0-9]{24})/extraction\.json$~', $file->path, $match)
                            || $file->path_hash !== hash('sha256', $file->path)
                            || (int) $file->bytes !== strlen($file->body)
                            || ! hash_equals($file->sha256, hash('sha256', $file->body))) {
                            throw new RuntimeException('Archived extraction identity or checksum differs: '.$file->path);
                        }

                        $edition = $match[1];
                        $data = json_decode($file->body, true, 512, JSON_THROW_ON_ERROR);
                        $kind = $data['kind'] ?? null;
                        $year = $data['year'] ?? null;
                        if (! in_array($kind, ['pc', 'ac'], true) || ! is_int($year)
                            || ! is_array($data['records'] ?? null)
                            || ! is_string($data['source_url'] ?? null)
                            || ! preg_match('/^[a-f0-9]{64}$/', $data['source_sha256'] ?? '')) {
                            throw new RuntimeException('Archived extraction metadata is incomplete: '.$file->path);
                        }

                        $indexed = DB::table('historical_constituency_index')->where('edition_id', $edition)->count();
                        $current = DB::table('historical_constituency_index')->where('edition_id', $edition)->where('extraction_sha256', $file->sha256)->count();
                        if ($indexed !== count($data['records']) || $current !== $indexed) {
                            throw new RuntimeException('Constituency index differs from archived extraction: '.$file->path);
                        }

                        $editionReviews = DB::table('historical_election_reviews')->where('archive', $edition)->orderBy('id')->get()->keyBy('fingerprint');
                        $editions++;
                        foreach ($data['records'] as $source) {
                            $fingerprint = $reviews->fingerprint($source, $data['source_sha256']);
                            $review = $editionReviews->get($fingerprint);
                            $record = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $source;
                            $record['has_warning'] = ! $review && ($source['status'] ?? '') !== 'validated';
                            $summary = $analytics->summarize([$record]);
                            $result = $analytics->singleSeatResult($record, $edition);
                            $issues = [];
                            $electors = $record['electors'] ?? null;
                            $polled = $record['votes_polled'] ?? null;
                            $hasPositiveSourceTurnout = is_int($electors) && $electors > 0 && is_int($polled) && $polled > 0 && $polled <= $electors;
                            $key = $kind.':'.$year;
                            $years[$key] ??= ['kind' => $kind, 'year' => $year, 'tables' => 0, 'source_turnout' => 0, 'shown_turnout' => 0, 'hidden_turnout' => 0, 'source_blank' => 0, 'source_invalid' => 0, 'shown_winner' => 0, 'hidden_winner_with_votes' => 0, 'uncontested' => 0];
                            $years[$key]['tables']++;
                            $tables++;

                            if ($result['uncontested'] ?? false) {
                                $years[$key]['uncontested']++;
                            } elseif ($hasPositiveSourceTurnout) {
                                $years[$key]['source_turnout']++;
                                if ($summary['polled'] !== null) {
                                    $years[$key]['shown_turnout']++;
                                } else {
                                    $years[$key]['hidden_turnout']++;
                                    $issues[] = 'source_turnout_hidden';
                                }
                            } elseif (is_int($polled) && $polled > 0) {
                                $years[$key]['source_invalid']++;
                                $issues[] = 'source_turnout_invalid';
                            } else {
                                $years[$key]['source_blank']++;
                                $issues[] = 'source_turnout_missing_or_zero';
                            }

                            if ($result !== null) {
                                $years[$key]['shown_winner']++;
                            } elseif (count($record['candidates'] ?? []) >= 2
                                && collect($record['candidates'])->every(fn (array $candidate): bool => is_int($candidate['votes'] ?? null) && $candidate['votes'] >= 0)) {
                                $years[$key]['hidden_winner_with_votes']++;
                                $issues[] = 'winner_hidden_with_candidate_votes';
                            }

                            if ($csv !== null) {
                                foreach ($issues as $issue) {
                                    fputcsv($csv, [$kind, $year, $record['state_name'] ?? $record['state_code'] ?? '', $record['constituency_name'] ?? $record['name'] ?? '', $edition, $record['code'] ?? '', $issue, $electors, $polled, $summary['polled'], count($record['candidates'] ?? []), $result['winner'] ?? '', $record['status'] ?? '', $data['source_url'], $file->sha256, $record['error'] ?? '']);
                                }
                            }
                        }
                    }
                }, 'path_hash');

            ksort($years);
            $this->line('Scope: imported PC/AC editions with matching constituency index; this checks display coverage, not every source figure against its PDF.');
            $this->line('kind year tables source_turnout shown_turnout hidden_turnout source_blank source_invalid shown_winner hidden_winner_with_votes uncontested');
            foreach ($years as $row) {
                $this->line(implode(' ', $row));
            }
            $this->info("Audited {$tables} constituency tables in {$editions} imported editions.");
            if ($csv !== null) {
                $this->info('Every blank or suppressed value is listed in '.$path);
            }
            $completed = true;

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if ($csv !== null) {
                fclose($csv);
                if (! $completed) {
                    @unlink($path);
                }
            }
        }
    }
}
