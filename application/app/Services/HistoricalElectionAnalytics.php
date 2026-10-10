<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HistoricalElectionAnalytics
{
    /** Keep both source editions, but display an identical result from the known 2019 report revision once. */
    public function distinctConstituencyHistoryRows(Collection $rows): Collection
    {
        $revisionPair = ['2e749f2174f08a9ea1fc803d', '70e603b1037bf7ca8e1350b0'];
        $seen = [];

        return $rows->filter(function (array $row) use ($revisionPair, &$seen): bool {
            $entry = $row['entry'];
            if ((int) $entry->year !== 2019 || ! in_array($entry->edition_id, $revisionPair, true)
                || empty($row['record']['candidates'])) {
                return true;
            }

            $record = $row['record'];
            unset($record['detail_page'], $record['review_fingerprint']);
            $record['candidates'] = collect($record['candidates'])->map(function (array $candidate): array {
                unset($candidate['source_row']);
                ksort($candidate);

                return $candidate;
            })->sortBy(fn (array $candidate): string => json_encode($candidate, JSON_THROW_ON_ERROR))->values()->all();
            ksort($record);
            $key = hash('sha256', json_encode([$entry->record_code, $record, $row['result']], JSON_THROW_ON_ERROR));
            if (isset($seen[$key]) && $seen[$key] !== $entry->edition_id) {
                return false;
            }
            $seen[$key] = $entry->edition_id;

            return true;
        })->values();
    }

    private const LEGACY_DETAIL_PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.';

    private const LEGACY_DETAIL_RECONCILED = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.';

    private const WORKBOOK_DETAIL_PENDING = 'Candidate cells transcribed from the official workbook; independent summary reconciliation is pending.';

    private const WORKBOOK_TOTAL_AMBIGUOUS = 'The source total column is preserved by its original label; voter and valid-vote meanings require summary verification.';

    private const WORKBOOK_SUMMARY_RECONCILED = 'Official constituency summary confirms voters and candidate votes; publication review pending.';

    private const WORKBOOK_SUMMARY_RECONCILED_NOTA = 'Official constituency summary confirms voters and candidate votes; its valid-vote total includes NOTA. Publication review pending.';

    private const WORKBOOK_SUMMARY_RECONCILED_MISMATCH = 'Official constituency summary confirms voters and candidate votes; the workbook total differs and is preserved for review.';

    private const WORKBOOK_SUMMARY_CANDIDATE_DIFFERENCE = 'Official constituency summary confirms voters; its valid-vote total differs slightly from the preserved candidate rows. Publication review pending.';

    private const WORKBOOK_SUMMARY_RECOVERED = 'Official constituency summary confirms electors, voters and candidate votes; the source elector components differ slightly. Publication review pending.';

    private const WORKBOOK_REPEATED_NAMES_RESULT = 'Some separate candidate rows share a name. Candidate votes, including NOTA, reconcile with the official summary, which confirms turnout, winner and margin; candidate identities remain under review.';

    private const PC_DETAIL_VERIFIED = 'Official detailed result prints turnout and candidate votes; no independent constituency summary was available. Review the official PDF.';

    private const PC_DETAIL_PENDING = 'Matching state/constituency summary is unavailable; Independent summary totals could not be reconciled';

    private const POSTAL_SUMMARY_NOTE = 'The official summary lists general valid votes separately from postal votes. Their sum reconciles with the detailed candidate rows; total voters, winner and margin are shown with the original figures retained for review.';

    private const PC_1989_DISCREPANCY_NOTE = 'Official 1989 detailed candidate report and constituency summary disagree on some totals. Detailed turnout is shown; the declared winner and margin match both reports. Review the official source.';

    public function forState(string $state, string $kind): array
    {
        $entries = [];
        if ($kind === 'ac') {
            foreach (app(ElectionArchive::class)->nationalAssemblyEntries() as $entry) {
                if ($entry['state'] === $state) {
                    $entries[$entry['url']] = $entry['label'].' '.$state;
                }
            }
        }
        if ($kind === 'pc' || $state === 'Uttar Pradesh') {
            foreach (app(ElectionArchive::class)->catalogue()[$kind] as [$label, $url]) {
                $entries[$url] = $label;
            }
        }
        $result = [];
        $disk = app(ArchiveFiles::class);
        foreach ($entries as $url => $label) {
            $id = substr(hash('sha256', $url), 0, 24);
            $path = 'election-archive/'.$id.'/extraction.json';
            if (! $disk->exists($path)) {
                continue;
            }
            $body = $disk->get($path);
            $reviewVersion = DB::table('historical_election_reviews')->where('archive', $id)->max('id') ?? 0;
            $key = 'election-analysis-v14:'.hash('sha256', $body.$state.$kind.$reviewVersion);
            $summary = Cache::remember($key, 900, function () use ($body, $url, $label, $state, $kind, $id): ?array {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (($data['source_url'] ?? '') !== $url || ($data['kind'] ?? '') !== $kind || ($data['year'] ?? 0) !== (int) substr($label, 0, 4)) {
                    return null;
                }
                $reviews = DB::table('historical_election_reviews')->where('archive', $id)->orderBy('id')->get()->keyBy('fingerprint');
                $records = [];
                $sourceState = null;
                foreach ($data['records'] as $record) {
                    $recordState = $record['state_name'] ?? $record['state_code'] ?? ($kind === 'ac' ? $state : '');
                    if ((preg_match('/^[su][0-9]{2}$/i', $recordState) && $data['year'] < 1977)
                        || mb_strtolower(ElectionPlaceIdentity::state($recordState)) !== mb_strtolower(ElectionPlaceIdentity::state($state))) {
                        continue;
                    }
                    $sourceState ??= $recordState;
                    $fingerprint = app(HistoricalElectionReview::class)->fingerprint($record, $data['source_sha256']);
                    $review = $reviews->get($fingerprint);
                    $record = $review ? json_decode($review->record, true, 512, JSON_THROW_ON_ERROR) : $record;
                    $record['has_warning'] = ! $review && ($record['status'] ?? '') !== 'validated';
                    $records[] = $record;
                }
                if ($records === []) {
                    return null;
                }

                $constituencyResults = collect($records)->map(function (array $record) use ($id): array {
                    $result = $this->singleSeatResult($record, $id);

                    return ['code' => $record['code'], 'name' => $record['constituency_name'] ?? $record['name'],
                        'result' => $result, 'source_candidate' => $result ? null : $this->sourceOnlyCandidate($record),
                        'has_warning' => $record['has_warning'], 'note' => $record['error'] ?? null];
                })->sortBy('name')->values()->all();

                return $this->summarize($records) + ['constituency_results' => $constituencyResults, 'review_count' => collect($records)->where('has_warning', true)->count(), 'id' => $id, 'year' => $data['year'], 'label' => $label, 'source_url' => $url, 'state' => $sourceState];
            });
            if ($summary) {
                $result[] = $summary;
            }
        }
        usort($result, fn (array $a, array $b): int => [$b['year'], $b['id']] <=> [$a['year'], $a['id']]);

        return $result;
    }

    public function summarize(array $records): array
    {
        $electors = $polled = $turnoutCount = $turnoutReviewCount = $turnoutDetailCount = $turnoutDiscrepancyCount = $partyCount = $partyReviewCount = $marginReviewCount = $candidateRows = $voteTotal = 0;
        $margins = $parties = $marginPercentages = $winners = [];
        $seatIdentity = fn (array $record): string => ($record['election_round'] ?? '').':'.($record['official_pc_code'] ?? $record['official_ac_code'] ?? $record['code']);
        $identities = array_count_values(array_map($seatIdentity, $records));
        foreach ($records as $record) {
            $candidateRows += count($record['candidates'] ?? []);
            $identity = $seatIdentity($record);
            if (($record['number_of_seats'] ?? 1) !== 1 || $identities[$identity] !== 1) {
                continue;
            }
            $hasWarning = $record['has_warning'] ?? (($record['status'] ?? '') !== 'validated');
            $provisionalCandidates = $hasWarning && $this->hasProvisionalCandidateVotes($record);
            $provisionalTurnout = $provisionalCandidates && ($record['error'] ?? '') === self::LEGACY_DETAIL_PENDING;
            $detailTurnoutWithTextWarning = $this->hasDetailTurnoutWithTextWarning($record);
            $electorDifference = $this->hasDocumentedElectorDifference($record);
            $polledDifference = $this->hasDocumentedPolledDifference($record);
            $sourceDifference = $this->hasDocumentedSourceDifference($record);
            $workbookSummaryTurnout = $this->hasOfficialWorkbookSummaryTurnout($record);
            $documentedTurnout = $this->hasDocumentedOfficialTurnout($record);
            $pc1989DiscrepancyResult = $this->officialPc1989DiscrepancyResult($record);
            $duplicateCandidateTurnout = $this->hasSummaryTurnoutWithDuplicateCandidates($record);
            $verifiedDuplicatePreviewResult = isset($record['official_detail_result']['duplicate_preview_page'])
                ? $this->officialResidualDetailResult($record) : null;
            $turnoutElectors = $electorDifference ? $record['summary_totals']['electors'] : ($record['electors'] ?? null);
            $turnoutPolled = $polledDifference ? $record['summary_totals']['votes_polled'] : ($record['votes_polled'] ?? null);
            if ($this->uncontestedResult($record, null) === null
                && $this->count($turnoutElectors) && $turnoutElectors > 0 && $this->count($turnoutPolled) && $turnoutPolled <= $turnoutElectors
                && (! $hasWarning || $this->hasCorroboratedTurnout($record) || $provisionalTurnout || $detailTurnoutWithTextWarning || $sourceDifference || $workbookSummaryTurnout || $documentedTurnout || $duplicateCandidateTurnout || $pc1989DiscrepancyResult !== null)) {
                $electors += $turnoutElectors;
                $polled += $turnoutPolled;
                $turnoutCount++;
                if ($hasWarning) {
                    $turnoutReviewCount++;
                    if ($provisionalTurnout || $detailTurnoutWithTextWarning) {
                        $turnoutDetailCount++;
                    }
                    if ($electorDifference || $polledDifference || $sourceDifference || $pc1989DiscrepancyResult !== null) {
                        $turnoutDiscrepancyCount++;
                    }
                }
            }
            if ($hasWarning && (! $provisionalCandidates || $verifiedDuplicatePreviewResult !== null)) {
                $workbookResult = $this->officialRepeatedNameWorkbookResult($record)
                    ?? $this->officialWorkbookSummaryResult($record)
                    ?? $this->officialPdfSummaryResult($record)
                    ?? $this->officialDeclaredTieResult($record)
                    ?? $verifiedDuplicatePreviewResult
                    ?? $this->officialResidualDetailResult($record)
                    ?? $this->officialInvalidTurnoutResult($record)
                    ?? $this->officialAc1971WestBengalVoterConflict($record)
                    ?? $pc1989DiscrepancyResult;
                if ($workbookResult !== null) {
                    $margins[] = $workbookResult['margin'];
                    $marginReviewCount++;
                    $validVotes = $record['summary_totals']['valid_candidate_votes'] ?? null;
                    if ($this->count($validVotes) && $validVotes > 0) {
                        $marginPercentages[] = 100 * $workbookResult['margin'] / $validVotes;
                    }
                    $winners[] = ['constituency' => trim($record['constituency_name'] ?? $record['name'] ?? ''), 'candidate' => $workbookResult['winner'], 'party' => $workbookResult['party'], 'margin' => $workbookResult['margin']];
                }

                continue;
            }
            $candidates = $record['candidates'] ?? [];
            if ($candidates === [] || collect($candidates)->contains(fn (array $c): bool => ! $this->count($c['votes'] ?? null) || trim($c['party_at_election'] ?? '') === '')) {
                continue;
            }
            $total = array_sum(array_column($candidates, 'votes'));
            if ($total <= 0 || ($this->count($record['votes_polled'] ?? null) && $total > $record['votes_polled'])) {
                continue;
            }
            $partyCount++;
            if ($hasWarning) {
                $partyReviewCount++;
            }
            $voteTotal += $total;
            foreach ($candidates as $candidate) {
                $party = ($candidate['is_nota'] ?? false) ? 'NOTA' : trim($candidate['party_at_election']);
                $parties[$party] = ($parties[$party] ?? 0) + $candidate['votes'];
            }
            $ranked = collect($candidates)->reject(fn (array $c): bool => ($c['is_nota'] ?? false) || strtoupper(trim($c['party_at_election'])) === 'NOTA')->sortByDesc('votes')->values();
            if ($ranked->count() >= 2 && $ranked[0]['votes'] > $ranked[1]['votes']) {
                $margin = $ranked[0]['votes'] - $ranked[1]['votes'];
                $margins[] = $margin;
                $marginPercentages[] = 100 * $margin / $total;
                if ($hasWarning) {
                    $marginReviewCount++;
                }
                $name = $this->hasVerifiedTripura2008CandidateVotes($record) ? $record['summary_result']['winner'] : trim($ranked[0]['candidate_name'] ?? '');
                $place = trim($record['constituency_name'] ?? $record['name'] ?? '');
                if ($name !== '' && $place !== '') {
                    $winners[] = ['constituency' => $place, 'candidate' => $name, 'party' => $ranked[0]['party_at_election'], 'margin' => $margin];
                }
            }
        }
        arsort($parties);
        $partyRows = [];
        foreach ($parties as $party => $votes) {
            $partyRows[] = ['party' => (string) $party, 'votes' => $votes, 'share' => 100 * $votes / $voteTotal];
        }

        return ['tables' => count($records), 'candidate_rows' => $candidateRows, 'turnout_count' => $turnoutCount, 'turnout_review_count' => $turnoutReviewCount, 'turnout_detail_count' => $turnoutDetailCount, 'turnout_discrepancy_count' => $turnoutDiscrepancyCount, 'electors' => $turnoutCount ? $electors : null,
            'polled' => $turnoutCount ? $polled : null, 'turnout' => $electors ? 100 * $polled / $electors : null,
            'party_count' => $partyCount, 'party_review_count' => $partyReviewCount, 'parties' => $partyRows, 'margin_count' => count($margins), 'margin_review_count' => $marginReviewCount,
            'margin' => $margins ? array_sum($margins) / count($margins) : null,
            'margin_percent' => $marginPercentages ? array_sum($marginPercentages) / count($marginPercentages) : null, 'winners' => $winners];
    }

    /** @return array{winner: string, party: string|null, margin: int|null, derived: bool, uncontested?: bool}|null */
    public function singleSeatResult(array $record, ?string $edition = null): ?array
    {
        if (($record['source_warning_code'] ?? null) === 'official_result_withheld_by_court'
            || collect($record['candidates'] ?? [])->contains(fn (array $candidate): bool => strtoupper(trim($candidate['candidate_name'] ?? '')) === 'RESULT WHITHHEAL BY HIGH COURT OF J AND K')) {
            return null;
        }

        if (($record['number_of_seats'] ?? 1) !== 1) {
            return null;
        }

        if (($record['admin_listing_correction'] ?? false) && ($record['status'] ?? '') === 'corrected') {
            $ranked = collect($record['candidates'] ?? [])->reject(fn (array $row): bool => ($row['is_nota'] ?? false) || strtoupper($row['party_at_election'] ?? '') === 'NOTA')->sortByDesc('votes')->values();
            if ($ranked->count() > 1 && $ranked->every(fn (array $row): bool => $this->count($row['votes'] ?? null)) && $ranked[0]['votes'] > $ranked[1]['votes']) {
                return ['winner' => $ranked[0]['candidate_name'], 'party' => $ranked[0]['party_at_election'] ?? null, 'margin' => $ranked[0]['votes'] - $ranked[1]['votes'], 'derived' => true];
            }

            return $this->uncontestedResult($record, $edition);
        }

        $listedWinner = $this->officialSuccessfulCandidate($record, $edition);
        if ($listedWinner !== null) {
            return $listedWinner;
        }

        $uncontested = $this->uncontestedResult($record, $edition);
        if ($uncontested !== null) {
            return $uncontested;
        }

        $officialResult = $this->officialRepeatedNameWorkbookResult($record)
            ?? $this->officialPdfSummaryResult($record)
            ?? $this->officialDeclaredTieResult($record)
            ?? $this->officialResidualDetailResult($record)
            ?? $this->officialInvalidTurnoutResult($record)
            ?? $this->officialAc1971WestBengalVoterConflict($record)
            ?? $this->officialPc1989DiscrepancyResult($record);
        if ($officialResult !== null) {
            return $officialResult + ['derived' => false];
        }

        if (is_string($record['winner'] ?? null) && trim($record['winner']) !== '' && $this->count($record['margin'] ?? null)) {
            $candidate = collect($record['candidates'] ?? [])->firstWhere('candidate_name', $record['winner']);

            return ['winner' => $record['winner'], 'party' => $candidate['party_at_election'] ?? null, 'margin' => $record['margin'], 'derived' => false];
        }

        if (! $this->hasProvisionalCandidateVotes($record, true)) {
            return null;
        }

        $ranked = collect($record['candidates'])->reject(fn (array $candidate): bool => ($candidate['is_nota'] ?? false) || strtoupper(trim($candidate['party_at_election'])) === 'NOTA')->sortByDesc('votes')->values();
        if ($ranked->count() < 2 || $ranked[0]['votes'] <= $ranked[1]['votes']) {
            return null;
        }

        return ['winner' => $ranked[0]['candidate_name'], 'party' => $ranked[0]['party_at_election'], 'margin' => $ranked[0]['votes'] - $ranked[1]['votes'], 'derived' => true];
    }

    /** @return list<array{name: string, party: string, votes: int}>|null Source-declared elected members, without a one-seat margin or turnout. */
    public function multiSeatDeclaredWinners(array $record): ?array
    {
        $seats = $record['number_of_seats'] ?? null;
        $winners = $record['official_multi_seat_winners'] ?? null;
        $candidates = $record['candidates'] ?? null;
        $summary = $record['summary_totals'] ?? null;
        if (! is_int($seats) || $seats < 2 || ! is_array($winners) || ! array_is_list($winners)
            || count($winners) !== $seats || ! is_array($candidates) || ! is_array($summary)
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['source_warning_code'] ?? null) !== 'official_multi_seat_summary'
            || ! is_string($record['original_extraction_warning'] ?? null)
            || preg_match('/\.pdf$/i', $record['summary_source_file'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/', $record['summary_source_sha256'] ?? '') !== 1
            || ! $this->count($record['summary_page'] ?? null) || $record['summary_page'] < 1
            || ! $this->count($summary['valid_candidate_votes'] ?? null)
            || array_sum(array_column($candidates, 'votes')) !== $summary['valid_candidate_votes']) {
            return null;
        }

        $seen = [];
        foreach ($winners as $winner) {
            if (! is_array($winner) || ! is_string($winner['name'] ?? null)
                || trim($winner['name']) === '' || ! is_string($winner['party'] ?? null)
                || trim($winner['party']) === '' || ! $this->count($winner['votes'] ?? null)) {
                return null;
            }
            $key = implode('|', [$winner['name'], $winner['party'], $winner['votes']]);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $matches = collect($candidates)->filter(fn ($candidate): bool => is_array($candidate)
                && ($candidate['candidate_name'] ?? null) === $winner['name']
                && ($candidate['party_at_election'] ?? null) === $winner['party']
                && ($candidate['votes'] ?? null) === $winner['votes']);
            if ($matches->count() !== 1) {
                return null;
            }
        }

        return $winners;
    }

    /** @return array{members: list<array{name: string, party: string, votes: int}>, seats: int, reason: string}|null */
    public function multiSeatReviewedDeclarations(array $record): ?array
    {
        $seats = $record['number_of_seats'] ?? null;
        $members = $record['official_multi_seat_review_members'] ?? null;
        $reason = $record['official_multi_seat_review_reason'] ?? null;
        $summary = $record['summary_totals'] ?? null;
        $candidates = $record['candidates'] ?? null;
        if (! is_int($seats) || $seats < 2 || ! is_array($members) || ! array_is_list($members)
            || $members === [] || count($members) > $seats || ! is_array($summary) || ! is_array($candidates)
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['source_warning_code'] ?? null) !== 'official_multi_seat_reviewed_declaration'
            || ! is_string($record['original_extraction_warning'] ?? null)
            || ! is_string($record['official_summary_constituency_name'] ?? null)
            || trim($record['official_summary_constituency_name']) === ''
            || preg_match('/\.pdf$/i', $record['summary_source_file'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/', $record['summary_source_sha256'] ?? '') !== 1
            || ! is_int($record['summary_page'] ?? null) || $record['summary_page'] < 1
            || ! is_int($summary['valid_candidate_votes'] ?? null)
            || $summary['valid_candidate_votes'] < 0) {
            return null;
        }

        if ($reason === 'incomplete_official_list') {
            if (count($members) >= $seats) {
                return null;
            }
        } elseif ($reason === 'candidate_rows_conflict') {
            if (count($members) !== $seats
                || array_sum(array_column($candidates, 'votes')) === $summary['valid_candidate_votes']) {
                return null;
            }
        } else {
            return null;
        }

        $seen = [];
        foreach ($members as $member) {
            if (! is_array($member) || ! is_string($member['name'] ?? null)
                || trim($member['name']) === '' || ! is_string($member['party'] ?? null)
                || trim($member['party']) === '' || ! is_int($member['votes'] ?? null)
                || $member['votes'] < 0) {
                return null;
            }
            $key = implode('|', [$member['name'], $member['party'], $member['votes']]);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $matches = collect($candidates)->filter(fn ($candidate): bool => is_array($candidate)
                && ($candidate['candidate_name'] ?? null) === $member['name']
                && ($candidate['party_at_election'] ?? null) === $member['party']
                && ($candidate['votes'] ?? null) === $member['votes']);
            if ($matches->count() !== ($reason === 'incomplete_official_list' ? 1 : 2)) {
                return null;
            }
        }

        return ['members' => $members, 'seats' => $seats, 'reason' => $reason];
    }

    /** @return array{name: string, party: string|null}|null A source row, never a declared winner. */
    public function sourceOnlyCandidate(array $record): ?array
    {
        $people = collect($record['candidates'] ?? [])->filter(fn ($candidate): bool => is_array($candidate) && ! ($candidate['is_nota'] ?? false)
            && ! in_array(strtoupper(trim($candidate['candidate_name'] ?? '')), ['NOTA', 'NONE OF THE ABOVE'], true)
            && strtoupper(trim($candidate['party_at_election'] ?? '')) !== 'NOTA')->values();
        if ($people->count() !== 1) {
            return null;
        }
        $name = trim($people[0]['candidate_name'] ?? '');
        if ($name === '') {
            return null;
        }

        return ['name' => $name, 'party' => trim($people[0]['party_at_election'] ?? '') ?: null];
    }

    private function officialSuccessfulCandidate(array $record, ?string $edition): ?array
    {
        if (($record['source_warning_code'] ?? null) !== 'official_successful_candidate_only' || $edition === null) {
            return null;
        }
        static $evidence = null;
        $evidence ??= json_decode(file_get_contents(database_path('fixtures/official-successful-candidates.json')), true, 512, JSON_THROW_ON_ERROR);
        $source = $evidence[$edition.':'.($record['code'] ?? '')] ?? null;
        $candidate = $record['candidates'][0] ?? [];
        if ($source === null || ($record['official_successful_candidate'] ?? null) !== $source
            || ($record['state_name'] ?? null) !== $source['state']
            || ($record['constituency_name'] ?? null) !== $source['name']
            || ($record['official_pc_code'] ?? null) !== $source['official_pc_code']
            || count($record['candidates'] ?? []) !== 1
            || ($candidate['candidate_name'] ?? null) !== $source['winner']
            || ($candidate['party_at_election'] ?? null) !== $source['party']
            || ($candidate['votes'] ?? null) !== 0
            || ($record['votes_polled'] ?? null) !== 0
            || ($record['valid_candidate_votes'] ?? null) !== 0) {
            return null;
        }

        return ['winner' => $source['winner'], 'party' => $source['party'], 'margin' => null, 'derived' => false, 'winner_only' => true];
    }

    private function uncontestedResult(array $record, ?string $edition): ?array
    {
        $candidates = $record['candidates'] ?? [];
        if (! is_array($candidates) || count($candidates) < 1 || count($candidates) > 2
            || ! in_array($record['votes_polled'] ?? null, [null, 0], true) || ! in_array($record['margin'] ?? null, [null, 0], true)) {
            return null;
        }
        $people = [];
        $notaCount = 0;
        foreach ($candidates as $row) {
            if (! is_array($row) || ! in_array($row['votes'] ?? null, [null, 0], true)
                || ! in_array($row['general_votes'] ?? null, [null, 0], true)
                || ! in_array($row['postal_votes'] ?? null, [null, 0], true)) {
                return null;
            }
            $rowName = strtoupper(trim($row['candidate_name'] ?? ''));
            $rowParty = strtoupper(trim($row['party_at_election'] ?? ''));
            if (($row['is_nota'] ?? false) || in_array($rowName, ['NOTA', 'NONE OF THE ABOVE'], true) || $rowParty === 'NOTA') {
                $notaCount++;
            } else {
                $people[] = $row;
            }
        }
        if (count($people) === 2 && $notaCount === 0 && $edition === 'a1b887ea9c50978fe4cf8e5c') {
            static $historicalEvidence = null;
            $historicalEvidence ??= json_decode(file_get_contents(database_path('fixtures/official-uncontested-results.json')), true, 512, JSON_THROW_ON_ERROR);
            $source = $historicalEvidence[$edition.':'.($record['code'] ?? '')] ?? null;
            $recordName = trim($record['constituency_name'] ?? basename(str_replace(' / ', '/', $record['name'] ?? '')));
            if ($source !== null && isset($source['other_candidate'], $source['other_party'], $source['electors'], $source['pdf_page'])
                && $source['name'] === $recordName && $source['electors'] === ($record['electors'] ?? null)
                && $source['pdf_page'] === ($record['summary_page'] ?? null)
                && ($record['source_warning_code'] ?? null) === 'official_uncontested_with_zero_vote_rows'
                && ($record['official_source_url'] ?? null) === $source['source_url']
                && ($record['summary_source_file'] ?? null) === $source['source_file']
                && ($record['summary_source_sha256'] ?? null) === $source['source_sha256']
                && ($record['valid_candidate_votes'] ?? null) === 0) {
                $printedWinner = collect($people)->first(fn (array $row): bool => ($row['candidate_name'] ?? null) === $source['candidate']
                    && ($row['party_at_election'] ?? null) === $source['party']);
                $printedOther = collect($people)->first(fn (array $row): bool => ($row['candidate_name'] ?? null) === $source['other_candidate']
                    && ($row['party_at_election'] ?? null) === $source['other_party']);
                if ($printedWinner !== null && $printedOther !== null && $printedWinner !== $printedOther) {
                    return ['winner' => $source['candidate'], 'party' => $source['party'],
                        'margin' => null, 'derived' => false, 'uncontested' => true];
                }
            }
        }
        if (count($people) !== 1 || $notaCount > 1) {
            return null;
        }
        $candidate = $people[0];
        $name = trim($candidate['candidate_name'] ?? '');
        $party = trim($candidate['party_at_election'] ?? '');
        if ($name === '' || $party === '' || ($candidate['is_nota'] ?? false) || strtoupper($name) === 'NOTA' || strtoupper($party) === 'NOTA') {
            return null;
        }

        $confirmed = false;
        if ($edition !== null && preg_match('/^[a-f0-9]{24}$/', $edition)) {
            static $evidence = null;
            $evidence ??= json_decode(file_get_contents(database_path('fixtures/official-uncontested-results.json')), true, 512, JSON_THROW_ON_ERROR);
            $source = $evidence[$edition.':'.($record['code'] ?? '')] ?? null;
            $recordName = trim($record['constituency_name'] ?? basename(str_replace(' / ', '/', $record['name'] ?? '')));
            $confirmed = $source !== null && $source['name'] === $recordName
                && $source['candidate'] === $name && $source['party'] === $party;
        }
        if (! $confirmed && ! empty($record['summary_source_rows'])) {
            $summaryText = json_encode($record['summary_source_rows'], JSON_THROW_ON_ERROR);
            $confirmed = stripos($summaryText, 'uncontested') !== false
                && stripos($summaryText, $name) !== false;
        }
        if (! $confirmed) {
            return null;
        }

        return ['winner' => $name, 'party' => $party, 'margin' => null, 'derived' => false, 'uncontested' => true];
    }

    private function count(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /**
     * Tripura 2008 Agartala: five rows on PDF p74 reconcile with summary p18.
     * Agartala p73 heading continues onto p74; Asharambari p77 continues onto p78
     * and reconciles with summary p37. Badharghat detail p75 matches summary p26;
     * the two Subrata Chakraborty rows have different parties and remain separate.
     * Bagma detail p78 matches all three candidates and summary p42.
     * Bamutia detail p73 matches all five candidates and summary p15.
     * Banamalipur detail p74 matches six candidates and summary p21, including CPI.
     * Barjala detail p73 matches six candidates and summary p16.
     * Belonia detail p80 matches six candidates and summary p48.
     * Bishalgarh detail p76 matches four candidates and summary p28.
     * Boxanagar detail p76 matches five candidates and summary p31.
     * Chandipur detail p83 matches six candidates and summary p64.
     * Charilam detail p76 matches four candidates and summary p30.
     * Dhanpur detail p77 matches four candidates and summary p34.
     * Dharmanagar detail p84 matches seven candidates and summary p68.
     * Fatikroy detail p83 matches eight candidates and summary p63.
     * Golaghati detail p76 matches five candidates and summary p29.
     * Serial/header text polluted names only.
     * https://old.eci.gov.in/files/file/3309-tripura-2008/
     * Preserve the extraction and its warnings; this grants only reviewed analytics.
     */
    private function hasVerifiedTripura2008CandidateVotes(array $record): bool
    {
        $source = match ($record['code'] ?? null) {
            6 => [
                'name' => 'Agartala', 'detail' => 73, 'summary' => 18,
                'electors' => 47407, 'polled' => 41788, 'valid' => 41361,
                'result' => ['winner' => 'SUDIP ROY BARMAN', 'winner_party' => 'INC', 'winner_votes' => 21019, 'runner' => 'BIKASH ROY', 'runner_party' => 'CPM', 'runner_votes' => 19194, 'margin' => 1825],
                'candidates' => [
                    ['CAND SL. as per form 7 SUDIP ROY BARMAN', 'INC', 20758, 261, 21019],
                    ['3 BIKASH ROY', 'CPM', 18858, 336, 19194],
                    ['1 MILAN CHAKRABORTY', 'BJP', 523, 5, 528],
                    ['2 SHIBANI BHOWMIK', 'IND', 387, 2, 389],
                    ['5 LALIT MOHAN GOSWAMI', 'AITC', 230, 1, 231],
                ],
            ],
            25 => [
                'name' => 'Asharambari  (ST)', 'detail' => 77, 'summary' => 37,
                'electors' => 26225, 'polled' => 24258, 'valid' => 24242,
                'result' => ['winner' => 'SACHINDRA DEBBARMA', 'winner_party' => 'CPM', 'winner_votes' => 13765, 'runner' => 'AMIYA KUMAR DEBBARMA', 'runner_party' => 'INPT', 'runner_votes' => 9234, 'margin' => 4531],
                'candidates' => [
                    ['SACHINDRA DEBBARMA', 'CPM', 13598, 167, 13765],
                    ['3 AMIYA KUMAR DEBBARMA', 'INPT', 9159, 75, 9234],
                    ['1 PRAFULLA DEBBARMA', 'IND', 439, 2, 441],
                    ['5 DHANBHAKTI JAMATIA', 'BJP', 417, 2, 419],
                    ['2 CAND SL. as per form 7 ASHIT DEBBARMA', 'IND', 381, 2, 383],
                ],
            ],
            14 => [
                'name' => 'Badharghat', 'detail' => 75, 'summary' => 26,
                'electors' => 66149, 'polled' => 61494, 'valid' => 61371,
                'result' => ['winner' => 'DILIP SARKAR', 'winner_party' => 'INC', 'winner_votes' => 29724, 'runner' => 'SUBRATA CHAKRABORTY', 'runner_party' => 'CPM', 'runner_votes' => 29349, 'margin' => 375],
                'candidates' => [
                    ['DILIP SARKAR', 'INC', 29365, 359, 29724],
                    ['3 SUBRATA CHAKRABORTY', 'CPM', 28877, 472, 29349],
                    ['2 RAMA PRASAD PAUL', 'BJP', 726, 5, 731],
                    ['1 SUBRATA CHAKRABORTY', 'IND', 673, 16, 689],
                    ['7 DILIP DUTTA', 'AIFB', 379, 1, 380],
                    ['5 DWIJENDRA SAHAJI', 'NCP', 307, 3, 310],
                    ['4 DEBASISH DATTA', 'AITC', 188, 0, 188],
                ],
            ],
            30 => [
                'name' => 'Bagma  (ST)', 'detail' => 78, 'summary' => 42,
                'electors' => 30930, 'polled' => 28829, 'valid' => 28779,
                'result' => ['winner' => 'NARESH CHANDRA JAMATIA', 'winner_party' => 'CPM', 'winner_votes' => 14979, 'runner' => 'RATI MOHAN JAMATIA', 'runner_party' => 'INC', 'runner_votes' => 13064, 'margin' => 1915],
                'candidates' => [
                    ['NARESH CHANDRA JAMATIA', 'CPM', 14809, 170, 14979],
                    ['1 RATI MOHAN JAMATIA', 'INC', 12962, 102, 13064],
                    ['3 RAJ KUMAR JAMATIA', 'BJP', 733, 3, 736],
                ],
            ],
            3 => [
                'name' => 'Bamutia  (SC)', 'detail' => 73, 'summary' => 15,
                'electors' => 35499, 'polled' => 33338, 'valid' => 33272,
                'result' => ['winner' => 'HARICHARAN SARKAR', 'winner_party' => 'CPM', 'winner_votes' => 17324, 'runner' => 'PRAKASH CHANDRA DAS', 'runner_party' => 'INC', 'runner_votes' => 14944, 'margin' => 2380],
                'candidates' => [
                    ['HARICHARAN SARKAR', 'CPM', 17171, 153, 17324],
                    ['3 PRAKASH CHANDRA DAS', 'INC', 14816, 128, 14944],
                    ['1 SAMIR BISWAS', 'BJP', 362, 5, 367],
                    ['2 BRAJENDRA DAS', 'AMB', 366, 0, 366],
                    ['5 PAPRI PODDER(BISWAS)', 'AITC', 270, 1, 271],
                ],
            ],
            9 => [
                'name' => 'Banamalipur', 'detail' => 74, 'summary' => 21,
                'electors' => 25956, 'polled' => 22696, 'valid' => 22595,
                'result' => ['winner' => 'GOPAL CHANDRA ROY', 'winner_party' => 'INC', 'winner_votes' => 12354, 'runner' => 'PRASANTA KAPALI', 'runner_party' => 'CPI', 'runner_votes' => 9546, 'margin' => 2808],
                'candidates' => [
                    ['GOPAL CHANDRA ROY', 'INC', 12158, 196, 12354],
                    ['1 PRASANTA KAPALI', 'CPI', 9248, 298, 9546],
                    ['2 SUDHINDRA CHANDRA DASGUPTA', 'BJP', 358, 7, 365],
                    ['3 NISHITH DAS', 'IND', 205, 1, 206],
                    ['6 RAKHAL RAJ DATTA', 'AMB', 88, 0, 88],
                    ['5 BASANTI SINHA', 'AITC', 35, 1, 36],
                ],
            ],
            4 => [
                'name' => 'Barjala', 'detail' => 73, 'summary' => 16,
                'electors' => 54467, 'polled' => 50912, 'valid' => 50789,
                'result' => ['winner' => 'SANKAR PRASAD DATTA', 'winner_party' => 'CPM', 'winner_votes' => 24853, 'runner' => 'DIPAK KUMAR ROY', 'runner_party' => 'INC', 'runner_votes' => 24255, 'margin' => 598],
                'candidates' => [
                    ['SANKAR PRASAD DATTA', 'CPM', 24550, 303, 24853],
                    ['3 DIPAK KUMAR ROY', 'INC', 24003, 252, 24255],
                    ['1 PULAK KUMAR DEBNATH', 'BJP', 687, 10, 697],
                    ['2 PRADIP CHAKRABORTY', 'AITC', 370, 1, 371],
                    ['6 SANJIB DEY', 'NCP', 352, 4, 356],
                    ['4 JAYANTA KUMAR DATTA', 'AIFB', 254, 3, 257],
                ],
            ],
            36 => [
                'name' => 'Belonia', 'detail' => 80, 'summary' => 48,
                'electors' => 33282, 'polled' => 31701, 'valid' => 31645,
                'result' => ['winner' => 'BASU DEV MAJUMDER', 'winner_party' => 'CPM', 'winner_votes' => 15971, 'runner' => 'AMAL MALLIK', 'runner_party' => 'INC', 'runner_votes' => 14652, 'margin' => 1319],
                'candidates' => [
                    ['BASU DEV MAJUMDER', 'CPM', 15627, 344, 15971],
                    ['2 AMAL MALLIK', 'INC', 14338, 314, 14652],
                    ['1 BABUL CHANDRA PAL', 'CPI(ML)(L)', 331, 2, 333],
                    ['5 SUDEB SEN CHOUDHURY', 'AITC', 293, 1, 294],
                    ['4 KESHAB CHANDRA SARKAR', 'BJP', 288, 5, 293],
                    ['3 RATAN ROY', 'AMB', 100, 2, 102],
                ],
            ],
            16 => [
                'name' => 'Bishalgarh', 'detail' => 76, 'summary' => 28,
                'electors' => 32449, 'polled' => 30670, 'valid' => 30609,
                'result' => ['winner' => 'BHANULAL SAHA', 'winner_party' => 'CPM', 'winner_votes' => 15457, 'runner' => 'SAMIR RANJAN BARMAN', 'runner_party' => 'INC', 'runner_votes' => 14543, 'margin' => 914],
                'candidates' => [
                    ['BHANULAL SAHA', 'CPM', 15246, 211, 15457],
                    ['1 SAMIR RANJAN BARMAN', 'INC', 14308, 235, 14543],
                    ['2 SUBRATA SARKAR', 'BJP', 320, 0, 320],
                    ['3 SUBRATA BHOWMIK', 'IND', 288, 1, 289],
                ],
            ],
            19 => [
                'name' => 'Boxanagar', 'detail' => 76, 'summary' => 31,
                'electors' => 29627, 'polled' => 27986, 'valid' => 27832,
                'result' => ['winner' => 'SAHID CHOUDHURI', 'winner_party' => 'CPM', 'winner_votes' => 13791, 'runner' => 'BILLAL MIA', 'runner_party' => 'INC', 'runner_votes' => 13099, 'margin' => 692],
                'candidates' => [
                    ['SAHID CHOUDHURI', 'CPM', 13731, 60, 13791],
                    ['3 BILLAL MIA', 'INC', 13057, 42, 13099],
                    ['2 GOPAL CHANDRA DAS', 'BJP', 328, 2, 330],
                    ['1 CHALE AHAMMED', 'CPI(ML)(L)', 309, 0, 309],
                    ['4 BAHAR MIA KHANDAKAR', 'IND', 303, 0, 303],
                ],
            ],
            52 => [
                'name' => 'Chandipur', 'detail' => 83, 'summary' => 64,
                'electors' => 33736, 'polled' => 31167, 'valid' => 31121,
                'result' => ['winner' => 'TAPAN CHAKRABORTY', 'winner_party' => 'CPM', 'winner_votes' => 17565, 'runner' => 'RUDRENDU BHATTACHARJEE', 'runner_party' => 'INC', 'runner_votes' => 11531, 'margin' => 6034],
                'candidates' => [
                    ['TAPAN CHAKRABORTY', 'CPM', 17378, 187, 17565],
                    ['2 RUDRENDU BHATTACHARJEE', 'INC', 11446, 85, 11531],
                    ['3 KABERI SINHA', 'BJP', 828, 6, 834],
                    ['1 RUDRA KANTA SINHA', 'IND', 514, 0, 514],
                    ['6 CHIRANJIB BHATTACHARJEE', 'CPI(ML)(L)', 451, 0, 451],
                    ['5 SUBHENDU DAS', 'AITC', 225, 1, 226],
                ],
            ],
            18 => [
                'name' => 'Charilam  (ST)', 'detail' => 76, 'summary' => 30,
                'electors' => 31259, 'polled' => 28847, 'valid' => 28754,
                'result' => ['winner' => 'NARAYAN RUPINI', 'winner_party' => 'CPM', 'winner_votes' => 14216, 'runner' => 'NARENDRA CHANDRA DEBBARMA', 'runner_party' => 'INPT', 'runner_votes' => 13729, 'margin' => 487],
                'candidates' => [
                    ['NARAYAN RUPINI', 'CPM', 14098, 118, 14216],
                    ['2 NARENDRA CHANDRA DEBBARMA', 'INPT', 13601, 128, 13729],
                    ['1 HARENDRA DEBBARMA', 'IND', 463, 3, 466],
                    ['4 BIDHYASAGAR DEBBARMA', 'IND', 340, 3, 343],
                ],
            ],
            22 => [
                'name' => 'Dhanpur', 'detail' => 77, 'summary' => 34,
                'electors' => 35933, 'polled' => 34077, 'valid' => 34008,
                'result' => ['winner' => 'MANIK SARKAR', 'winner_party' => 'CPM', 'winner_votes' => 17992, 'runner' => 'SHAH ALAM', 'runner_party' => 'INC', 'runner_votes' => 15074, 'margin' => 2918],
                'candidates' => [
                    ['MANIK SARKAR', 'CPM', 17776, 216, 17992],
                    ['1 SHAH ALAM', 'INC', 14983, 91, 15074],
                    ['2 NAIDAR BASI TRIPURA', 'IND', 522, 3, 525],
                    ['4 ASHISH CHAKRABORTY', 'AITC', 417, 0, 417],
                ],
            ],
            56 => [
                'name' => 'Dharmanagar', 'detail' => 84, 'summary' => 68,
                'electors' => 34419, 'polled' => 30993, 'valid' => 30952,
                'result' => ['winner' => 'BISWA BANDHU SEN', 'winner_party' => 'INC', 'winner_votes' => 15987, 'runner' => 'AMITABHA DATTA', 'runner_party' => 'CPM', 'runner_votes' => 13577, 'margin' => 2410],
                'candidates' => [
                    ['BISWA BANDHU SEN', 'INC', 15694, 293, 15987],
                    ['2 AMITABHA DATTA', 'CPM', 13149, 428, 13577],
                    ['1 TAMAL KANTI DEB', 'BJP', 797, 8, 805],
                    ['3 SANJAY CHAUDHURY', 'IND', 212, 1, 213],
                    ['7 ANAMIKA ROY(SAHA)', 'AIFB', 143, 0, 143],
                    ['4 ANJAN SUKLA BAIDYA', 'IND', 117, 0, 117],
                    ['6 GOPAL KRISHNA DEB', 'AMB', 110, 0, 110],
                ],
            ],
            51 => [
                'name' => 'Fatikroy', 'detail' => 83, 'summary' => 63,
                'electors' => 30661, 'polled' => 28363, 'valid' => 28323,
                'result' => ['winner' => 'BIJOY ROY', 'winner_party' => 'CPM', 'winner_votes' => 14457, 'runner' => 'SUNIL CHANDRA DAS', 'runner_party' => 'INC', 'runner_votes' => 12144, 'margin' => 2313],
                'candidates' => [
                    ['BIJOY ROY', 'CPM', 14289, 168, 14457],
                    ['1 SUNIL CHANDRA DAS', 'INC', 12045, 99, 12144],
                    ['3 BIRESWAR SINGHA', 'BJP', 612, 5, 617],
                    ['2 RATHINDRA DEBNATH', 'IND', 571, 1, 572],
                    ['8 BASUDEB GHOSH', 'CPI(ML)(L)', 188, 0, 188],
                    ['5 JYOTIRMOY DEB', 'AITC', 144, 0, 144],
                    ['6 PRANAY BHUSAN BASAK', 'AMB', 106, 1, 107],
                    ['7 PRATIK SEN', 'AIFB', 94, 0, 94],
                ],
            ],
            17 => [
                'name' => 'Golaghati  (ST)', 'detail' => 76, 'summary' => 29,
                'electors' => 27815, 'polled' => 25948, 'valid' => 25886,
                'result' => ['winner' => 'KESAB DEBBARMA', 'winner_party' => 'CPM', 'winner_votes' => 13990, 'runner' => 'ASHOK DEBBARMA', 'runner_party' => 'INC', 'runner_votes' => 11003, 'margin' => 2987],
                'candidates' => [
                    ['KESAB DEBBARMA', 'CPM', 13833, 157, 13990],
                    ['2 ASHOK DEBBARMA', 'INC', 10885, 118, 11003],
                    ['1 SANTI KUMAR DEBBARMA', 'IND', 356, 0, 356],
                    ['5 SUCHITRA DEBBARMA', 'AITC', 313, 2, 315],
                    ['3 KARTIK KANYA DEBBARMA', 'IND', 222, 0, 222],
                ],
            ],
            default => null,
        };
        if ($source === null || ($record['name'] ?? null) !== $source['name']
            || ($record['state_name'] ?? null) !== 'Tripura'
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['source_warning_code'] ?? null) !== 'summary_turnout_with_detail_warnings'
            || ($record['original_extraction_warning'] ?? null) !== self::LEGACY_DETAIL_PENDING.'; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.'
            || ($record['detail_page'] ?? null) !== $source['detail'] || ($record['summary_page'] ?? null) !== $source['summary']
            || ($record['summary_source_file'] ?? null) !== 'c2b9ef2bc73bbcc70a271a58-7626.pdf'
            || ($record['summary_source_sha256'] ?? null) !== '0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43'
            || ($record['electors'] ?? null) !== $source['electors'] || ($record['votes_polled'] ?? null) !== $source['polled']
            || ($record['summary_totals']['valid_candidate_votes'] ?? null) !== $source['valid']
            || $this->officialPdfSummaryResult($record) === null
            || ($record['summary_result'] ?? null) !== $source['result']) {
            return false;
        }
        $expected = $source['candidates'];
        $candidates = $record['candidates'] ?? [];
        if (count($candidates) !== count($expected)) {
            return false;
        }
        foreach (array_values($candidates) as $index => $candidate) {
            if (($candidate['is_nota'] ?? false) || array_map(fn ($key) => $candidate[$key] ?? null, ['candidate_name', 'party_at_election', 'general_votes', 'postal_votes', 'votes']) !== $expected[$index]) {
                return false;
            }
        }

        return true;
    }

    private function hasReconciledDeclaredCandidateVotes(array $record): bool
    {
        if (! in_array($record['source_warning_code'] ?? null, ['official_summary_turnout_only', 'summary_turnout_with_detail_warnings'], true)
            || ! in_array($record['original_extraction_warning'] ?? null, [self::LEGACY_DETAIL_PENDING, self::LEGACY_DETAIL_RECONCILED], true)
            || $this->officialPdfSummaryResult($record) === null) {
            return false;
        }

        $candidateRecord = $record;
        unset($candidateRecord['source_warning_code']);
        $candidateRecord['error'] = self::LEGACY_DETAIL_RECONCILED;
        if (! $this->hasProvisionalCandidateVotes($candidateRecord)) {
            return false;
        }

        $ranked = collect($record['candidates'])->reject(fn (array $row): bool => ($row['is_nota'] ?? false) || strtoupper($row['party_at_election']) === 'NOTA')->sortByDesc('votes')->values();
        if ($ranked->count() < 2) {
            return false;
        }
        $result = $record['summary_result'];
        foreach (['winner' => 0, 'runner' => 1] as $role => $index) {
            if (mb_strtolower(trim($result[$role])) !== mb_strtolower(trim($ranked[$index]['candidate_name']))
                || $result[$role.'_party'] !== $ranked[$index]['party_at_election']
                || $result[$role.'_votes'] !== $ranked[$index]['votes']) {
                return false;
            }
        }

        return true;
    }

    private function hasProvisionalCandidateVotes(array $record, bool $includeReviewed = false): bool
    {
        if (($record['status'] ?? '') !== 'needs_review' && (! $includeReviewed || ! in_array($record['status'] ?? '', ['validated', 'accepted', 'corrected'], true))) {
            return false;
        }

        if ($this->hasReconciledDeclaredCandidateVotes($record) || $this->hasVerifiedTripura2008CandidateVotes($record)) {
            return true;
        }

        if (in_array($record['source_warning_code'] ?? null, [
            'official_pc_summary_reconciled_serial_gap',
            'official_pc_summary_reconciled_detail_warning',
        ], true)) {
            return $this->hasReconciledPcSummaryResult($record);
        }
        if (($record['source_warning_code'] ?? null) === 'official_pc_detailed_result_verified') {
            return $this->hasVerifiedPcDetailResult($record);
        }
        if (($record['source_warning_code'] ?? null) === 'official_turnout_from_residual_source'
            && isset($record['official_detail_result'])) {
            return $this->officialResidualDetailResult($record) !== null;
        }
        if (($record['source_warning_code'] ?? null) === 'official_detailed_pdf_turnout') {
            return $this->hasReconciledWorkbookDetailVotes($record);
        }

        $error = $record['error'] ?? '';
        $documentedDifference = $this->hasDocumentedElectorDifference($record);
        $sourceDetail = ! isset($record['source_warning_code']) && $this->count($record['detail_page'] ?? null) && $record['detail_page'] > 0
            && ! preg_match('/candidate (?:count differs|serial numbers? (?:are )?(?:incomplete|duplicated)|text could not be parsed|vote cells are missing|rows are missing)|(?:constituency names|identities) differ|report pages are missing, duplicated or out of order/i', $error);
        $legacyDetail = (in_array($error, [self::LEGACY_DETAIL_PENDING, self::LEGACY_DETAIL_RECONCILED], true) || $documentedDifference || $sourceDetail)
            && $this->count($record['detail_page'] ?? null) && $record['detail_page'] > 0;
        if ($error === self::LEGACY_DETAIL_RECONCILED || $documentedDifference) {
            $summary = $record['summary_totals'] ?? [];
            $legacyDetail = $legacyDetail && $this->count($record['summary_page'] ?? null) && $record['summary_page'] > 0
                && $this->count($summary['valid_candidate_votes'] ?? null)
                && $summary['valid_candidate_votes'] === ($record['valid_candidate_votes'] ?? null)
                && $this->hasCorroboratedTurnout($record);
        }
        $workbookDetail = in_array($error, [self::WORKBOOK_DETAIL_PENDING, self::WORKBOOK_DETAIL_PENDING.'; '.self::WORKBOOK_TOTAL_AMBIGUOUS,
            self::WORKBOOK_SUMMARY_RECONCILED, self::WORKBOOK_SUMMARY_RECONCILED_NOTA, self::WORKBOOK_SUMMARY_RECONCILED_MISMATCH,
            self::WORKBOOK_SUMMARY_CANDIDATE_DIFFERENCE, self::WORKBOOK_SUMMARY_RECOVERED], true);
        if (! $legacyDetail && ! $workbookDetail) {
            return false;
        }

        $candidates = $record['candidates'] ?? [];
        if ($candidates === [] || collect($candidates)->contains(fn (array $candidate): bool => ! $this->count($candidate['votes'] ?? null)
            || trim($candidate['candidate_name'] ?? '') === '' || trim($candidate['party_at_election'] ?? '') === ''
            || ($this->count($candidate['general_votes'] ?? null) && $this->count($candidate['postal_votes'] ?? null)
                && $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes']))) {
            return false;
        }

        $candidateKeys = collect($candidates)->map(fn (array $candidate): string => mb_strtolower(trim($candidate['candidate_name']).'|'.trim($candidate['party_at_election']).'|'.$candidate['votes']));
        if ($candidateKeys->unique()->count() !== count($candidates)) {
            return false;
        }

        $candidateVotes = array_sum(array_column($candidates, 'votes'));
        if ($candidateVotes <= 0 || ($this->count($record['electors'] ?? null) && $candidateVotes > $record['electors'])
            || ($this->count($record['votes_polled'] ?? null) && $candidateVotes > $record['votes_polled'])) {
            return false;
        }

        if ($legacyDetail) {
            if (! $this->count($record['valid_candidate_votes'] ?? null)) {
                return false;
            }
            $nota = collect($candidates)->filter(fn (array $candidate): bool => ($candidate['is_nota'] ?? false) === true)->values();
            if ($nota->isEmpty()) {
                return $candidateVotes === $record['valid_candidate_votes']
                    || $this->hasDocumentedLegacyCandidateDifference($record, $candidateVotes);
            }
            if ($nota->count() !== 1 || $candidateVotes - $nota[0]['votes'] !== $record['valid_candidate_votes']) {
                return false;
            }
            if (($error === self::LEGACY_DETAIL_RECONCILED || $documentedDifference)
                && ($record['summary_totals']['nota_votes'] ?? null) !== $nota[0]['votes']) {
                return false;
            }

            return true;
        }

        $totals = $record['reported_totals'] ?? [];
        $workbookSummary = ($record['source_warning_code'] ?? '') === 'workbook_pdf_summary';
        $summary = $record['summary_totals'] ?? [];
        $notaVotes = collect($candidates)->filter(fn (array $candidate): bool => ($candidate['is_nota'] ?? false) === true)->pluck('votes')->values();
        if ($workbookSummary && (! is_array($summary)
            || ! $this->count($summary['valid_candidate_votes'] ?? null)
            || ! $this->count($summary['nota_votes'] ?? 0)
            || (isset($summary['nota_votes']) && ($notaVotes->count() !== 1 || $notaVotes[0] !== $summary['nota_votes']))
            || ($candidateVotes !== $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0)
                && ! $this->hasDocumentedCandidateDifference($record, $candidateVotes, $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0))))) {
            return false;
        }
        $componentDifference = $record['source_discrepancy'] ?? [];
        $recoveredSummary = $workbookSummary && $error === self::WORKBOOK_SUMMARY_RECOVERED
            && $totals === [] && ($componentDifference['field'] ?? '') === 'elector_components'
            && $this->count($summary['electors'] ?? null) && ($record['electors'] ?? null) === $summary['electors']
            && $this->count($componentDifference['component_value'] ?? null)
            && ($componentDifference['summary_value'] ?? null) === ($summary['electors'] ?? null)
            && abs($componentDifference['summary_value'] - $componentDifference['component_value']) >= 1
            && abs($componentDifference['summary_value'] - $componentDifference['component_value']) <= 2;
        if (! $recoveredSummary && (count($totals) !== 1 || ! in_array($totals[0]['label'] ?? '', ['Total Votes', 'Total valid votes polled +NOTA'], true)
            || ! $this->count($totals[0]['value'] ?? null)
            || ($candidateVotes !== $totals[0]['value'] && (! $workbookSummary || $error !== self::WORKBOOK_SUMMARY_RECONCILED_MISMATCH)))) {
            return false;
        }

        return collect($candidates)->every(fn (array $candidate): bool => $this->count($candidate['general_votes'] ?? null)
            && $this->count($candidate['postal_votes'] ?? null)
            && $candidate['votes'] === $candidate['general_votes'] + $candidate['postal_votes']
            && trim($candidate['source_sheet'] ?? '') !== ''
            && $this->count($candidate['workbook_row'] ?? null) && $candidate['workbook_row'] > 0);
    }

    private function hasReconciledPcSummaryResult(array $record): bool
    {
        $summary = $record['summary_totals'] ?? null;
        $result = $record['summary_result'] ?? null;
        $candidates = $record['candidates'] ?? null;
        $detailVerified = ($record['source_warning_code'] ?? null) === 'official_pc_summary_reconciled_detail_warning';
        $candidateCount = $detailVerified ? ($record['detail_candidate_count'] ?? null) : ($record['summary_candidate_count'] ?? null);
        if (($record['number_of_seats'] ?? 1) !== 1 || ! is_array($summary) || ! is_array($result) || ! is_array($candidates)
            || count($candidates) < 2 || $candidateCount !== count($candidates)
            || ! $this->count($record['summary_page'] ?? null) || $record['summary_page'] < 1
            || ! is_string($record['summary_source_file'] ?? null) || ! str_ends_with($record['summary_source_file'], '.pdf')
            || ! is_string($record['summary_source_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $record['summary_source_sha256'])) {
            return false;
        }
        if ($detailVerified && (! $this->count($record['detail_page'] ?? null) || $record['detail_page'] < 1
            || ! is_string($record['detail_source_file'] ?? null) || ! str_ends_with($record['detail_source_file'], '.pdf')
            || ! is_string($record['detail_source_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $record['detail_source_sha256']))) {
            return false;
        }

        foreach (['electors', 'votes_polled', 'valid_candidate_votes'] as $field) {
            if (! $this->count($record[$field] ?? null) || $record[$field] < 1 || ($summary[$field] ?? null) !== $record[$field]) {
                return false;
            }
        }
        if ($record['valid_candidate_votes'] > $record['votes_polled'] || $record['votes_polled'] > $record['electors']) {
            return false;
        }
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! $this->count($candidate['votes'] ?? null)
                || trim($candidate['candidate_name'] ?? '') === '' || trim($candidate['party_at_election'] ?? '') === ''
                || ($candidate['is_nota'] ?? false)
                || ($this->count($candidate['general_votes'] ?? null) && $this->count($candidate['postal_votes'] ?? null)
                    && $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes'])) {
                return false;
            }
        }
        if (array_sum(array_column($candidates, 'votes')) !== $record['valid_candidate_votes']) {
            return false;
        }
        $unique = collect($candidates)->map(fn (array $candidate): string => mb_strtolower(trim($candidate['candidate_name']).'|'.trim($candidate['party_at_election']).'|'.$candidate['votes']));
        if ($unique->unique()->count() !== count($candidates)) {
            return false;
        }

        $ranked = collect($candidates)->sortByDesc('votes')->values();
        $winner = $ranked[0];
        $runner = $ranked[1];
        $margin = $winner['votes'] - $runner['votes'];
        if ($margin <= 0) {
            return false;
        }

        return ($result['winner'] ?? null) === $winner['candidate_name']
            && ($result['winner_party'] ?? null) === $winner['party_at_election']
            && ($result['winner_votes'] ?? null) === $winner['votes']
            && ($result['runner'] ?? null) === $runner['candidate_name']
            && ($result['runner_party'] ?? null) === $runner['party_at_election']
            && ($result['runner_votes'] ?? null) === $runner['votes']
            && ($result['margin'] ?? null) === $margin;
    }

    private function hasVerifiedPcDetailResult(array $record): bool
    {
        $totals = $record['detail_verified_totals'] ?? null;
        $result = $record['detail_verified_result'] ?? null;
        $candidates = $record['candidates'] ?? null;
        if (($record['number_of_seats'] ?? null) !== 1 || ($record['status'] ?? null) !== 'needs_review'
            || ($record['error'] ?? null) !== self::PC_DETAIL_VERIFIED
            || ($record['original_extraction_warning'] ?? null) !== self::PC_DETAIL_PENDING
            || ! is_array($totals) || ! is_array($result) || ! is_array($candidates) || count($candidates) < 2
            || ! $this->count($record['detail_page'] ?? null) || $record['detail_page'] < 1
            || ! $this->count($totals['source_page'] ?? null)
            || $totals['source_page'] < $record['detail_page'] || $totals['source_page'] > $record['detail_page'] + 3
            || ($totals['method'] ?? null) !== 'official detailed-result PDF; top candidate rows and totals checked'
            || ! is_string($record['detail_source_file'] ?? null) || preg_match('/^[a-zA-Z0-9._-]+\.pdf$/', $record['detail_source_file']) !== 1
            || ! is_string($record['detail_source_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $record['detail_source_sha256']) !== 1) {
            return false;
        }
        foreach (['electors', 'votes_polled', 'valid_candidate_votes'] as $field) {
            if (! $this->count($record[$field] ?? null) || $record[$field] < 1 || ($totals[$field] ?? null) !== $record[$field]) {
                return false;
            }
        }
        if ($record['valid_candidate_votes'] > $record['votes_polled'] || $record['votes_polled'] > $record['electors']) {
            return false;
        }
        $keys = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! $this->count($candidate['votes'] ?? null)
                || trim($candidate['candidate_name'] ?? '') === '' || trim($candidate['party_at_election'] ?? '') === ''
                || ($candidate['is_nota'] ?? false)
                || ($this->count($candidate['general_votes'] ?? null) && $this->count($candidate['postal_votes'] ?? null)
                    && $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes'])) {
                return false;
            }
            $key = mb_strtolower(trim($candidate['candidate_name']).'|'.trim($candidate['party_at_election']).'|'.$candidate['votes']);
            if (isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
        }
        if (array_sum(array_column($candidates, 'votes')) !== $record['valid_candidate_votes']) {
            return false;
        }
        $ranked = collect($candidates)->sortByDesc('votes')->values();
        $winner = $ranked[0];
        $runner = $ranked[1];
        $margin = $winner['votes'] - $runner['votes'];

        return $margin > 0
            && ($result['winner'] ?? null) === $winner['candidate_name']
            && ($result['winner_party'] ?? null) === $winner['party_at_election']
            && ($result['winner_votes'] ?? null) === $winner['votes']
            && ($result['runner'] ?? null) === $runner['candidate_name']
            && ($result['runner_party'] ?? null) === $runner['party_at_election']
            && ($result['runner_votes'] ?? null) === $runner['votes']
            && ($result['margin'] ?? null) === $margin;
    }

    private function hasCorroboratedTurnout(array $record): bool
    {
        $summary = $record['summary_totals'] ?? null;

        if (($record['source_warning_code'] ?? '') === 'official_general_valid_plus_postal') {
            return $this->hasOfficialPostalSummary($record);
        }

        if (($record['source_warning_code'] ?? '') === 'official_detail_turnout_only') {
            $turnout = $record['turnout_totals'] ?? null;

            return ($record['status'] ?? '') === 'needs_review'
                && is_array($turnout)
                && preg_match('/\.pdf$/i', $record['turnout_source_file'] ?? '') === 1
                && preg_match('/^[a-f0-9]{64}$/', $record['turnout_source_sha256'] ?? '') === 1
                && preg_match('/^[a-f0-9]{64}$/', $record['turnout_ocr_sha256'] ?? '') === 1
                && $this->count($record['turnout_source_page'] ?? null) && $record['turnout_source_page'] > 0
                && $this->count($turnout['electors'] ?? null) && $turnout['electors'] === ($record['electors'] ?? null)
                && $this->count($turnout['general_votes'] ?? null)
                && $this->count($turnout['postal_votes'] ?? null)
                && $this->count($turnout['votes_polled'] ?? null)
                && $turnout['votes_polled'] === $turnout['general_votes'] + $turnout['postal_votes']
                && $turnout['votes_polled'] === ($record['votes_polled'] ?? null)
                && $turnout['votes_polled'] > 0 && $turnout['votes_polled'] <= $turnout['electors'];
        }

        if (($record['source_warning_code'] ?? '') === 'official_summary_turnout_only'
            || (($record['source_warning_code'] ?? '') === 'summary_turnout_with_detail_warnings'
                && isset($record['summary_source_file'], $record['summary_source_sha256']))) {
            return ($record['status'] ?? '') === 'needs_review'
                && is_array($summary)
                && preg_match('/\.pdf$/i', $record['summary_source_file'] ?? '') === 1
                && preg_match('/^[a-f0-9]{64}$/', $record['summary_source_sha256'] ?? '') === 1
                && $this->count($record['summary_page'] ?? null) && $record['summary_page'] > 0
                && $this->count($summary['electors'] ?? null) && $summary['electors'] > 0
                && ($summary['electors'] === ($record['electors'] ?? null) || $this->hasDocumentedElectorDifference($record))
                && $this->count($summary['votes_polled'] ?? null) && $summary['votes_polled'] > 0
                && $summary['votes_polled'] <= $summary['electors']
                && ($summary['votes_polled'] === ($record['votes_polled'] ?? null) || $this->hasDocumentedPolledDifference($record))
                && $this->count($summary['valid_candidate_votes'] ?? null);
        }

        if (($record['source_warning_code'] ?? '') === 'workbook_pdf_summary') {
            return is_array($summary)
                && preg_match('/\.pdf$/i', $record['summary_source_file'] ?? '') === 1
                && preg_match('/^[a-f0-9]{64}$/', $record['summary_source_sha256'] ?? '') === 1
                && $this->count($record['summary_page'] ?? null) && $record['summary_page'] > 0
                && $this->count($summary['electors'] ?? null) && $summary['electors'] === ($record['electors'] ?? null)
                && $this->count($summary['votes_polled'] ?? null) && $summary['votes_polled'] === ($record['votes_polled'] ?? null)
                && $this->count($summary['valid_candidate_votes'] ?? null)
                && (array_sum(array_column($record['candidates'] ?? [], 'votes')) === $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0)
                    || $this->hasDocumentedCandidateDifference($record, array_sum(array_column($record['candidates'] ?? [], 'votes')), $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0)))
                && $this->hasProvisionalCandidateVotes($record, true);
        }

        return is_array($summary)
            && $this->count($record['detail_page'] ?? null) && $record['detail_page'] > 0
            && $this->count($record['summary_page'] ?? null) && $record['summary_page'] > 0
            && $this->count($summary['electors'] ?? null)
            && $this->count($summary['votes_polled'] ?? null)
            && ($summary['electors'] === ($record['electors'] ?? null) || $this->hasDocumentedElectorDifference($record))
            && $summary['votes_polled'] === $record['votes_polled']
            && (($record['source_warning_code'] ?? '') !== 'summary_turnout_with_detail_warnings'
                || ($this->count($summary['valid_candidate_votes'] ?? null)
                    && array_sum(array_column($record['candidates'] ?? [], 'votes')) === $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0)))
            && (($record['source_warning_code'] ?? '') !== 'summary_only_turnout'
                || ($this->count($summary['valid_candidate_votes'] ?? null)
                    && $this->count($summary['nota_votes'] ?? 0)
                    && $summary['votes_polled'] >= $summary['valid_candidate_votes'] + ($summary['nota_votes'] ?? 0)));
    }

    private function hasDocumentedSourceDifference(array $record): bool
    {
        if (($record['status'] ?? '') !== 'needs_review' || isset($record['source_warning_code'])
            || ! $this->count($record['detail_page'] ?? null) || $record['detail_page'] < 1
            || ! $this->count($record['summary_page'] ?? null) || $record['summary_page'] < 1
            || ! $this->count($record['electors'] ?? null) || $record['electors'] < 1
            || ! $this->count($record['votes_polled'] ?? null) || $record['votes_polled'] < 1
            || $record['votes_polled'] > $record['electors']) {
            return false;
        }

        $summary = $record['summary_totals'] ?? null;
        if (($record['error'] ?? '') === 'Detailed and summary polled votes differ') {
            $difference = is_array($summary) && $this->count($summary['votes_polled'] ?? null)
                ? abs($summary['votes_polled'] - $record['votes_polled']) : null;

            return is_array($summary)
                && ($summary['electors'] ?? null) === $record['electors']
                && $this->count($summary['votes_polled'] ?? null)
                && $summary['votes_polled'] > 0 && $summary['votes_polled'] <= $record['electors']
                && $difference > 0 && $difference <= 5
                && $difference * 1000 <= $summary['votes_polled'];
        }
        if (str_starts_with($record['error'] ?? '', 'Summary and detailed totals differ: ')) {
            return is_array($summary)
                && ($summary['electors'] ?? null) === $record['electors']
                && $this->count($summary['votes_polled'] ?? null)
                && $summary['votes_polled'] > 0 && $summary['votes_polled'] <= $record['electors']
                && preg_match('/votes_polled: detail (\d+), summary (\d+)/', $record['error'], $matches) === 1
                && (int) $matches[1] === $record['votes_polled']
                && (int) $matches[2] === $summary['votes_polled'];
        }

        return preg_match('/^Candidate sum (\d+); detailed total (\d+); summary valid votes (\d+)\. Totals differ\.$/', $record['error'] ?? '', $matches) === 1
            && (int) $matches[1] === (int) $matches[2]
            && (int) $matches[2] === ($record['detailed_totals']['votes'] ?? null)
            && (int) $matches[3] === ($record['valid_candidate_votes'] ?? null)
            && (int) $matches[3] > 0;
    }

    private function hasDetailTurnoutWithTextWarning(array $record): bool
    {
        return ($record['status'] ?? '') === 'needs_review'
            && ($record['error'] ?? '') === self::LEGACY_DETAIL_PENDING.'; Some candidate text could not be parsed; see the original PDF.'
            && ! isset($record['source_warning_code'])
            && $this->count($record['detail_page'] ?? null) && $record['detail_page'] > 0
            && $this->count($record['electors'] ?? null) && $record['electors'] > 0
            && $this->count($record['votes_polled'] ?? null) && $record['votes_polled'] > 0
            && $record['votes_polled'] <= $record['electors']
            && $this->count($record['valid_candidate_votes'] ?? null)
            && $record['valid_candidate_votes'] > 0
            && $record['valid_candidate_votes'] <= $record['votes_polled'];
    }

    private function hasDocumentedOfficialTurnout(array $record): bool
    {
        $code = $record['source_warning_code'] ?? null;
        if ($code === 'official_pc_detailed_result_verified') {
            return $this->hasVerifiedPcDetailResult($record);
        }
        $methods = match ($code) {
            'official_turnout_from_residual_source' => ['official constituency summary', 'official detailed turnout row', 'visual transcription of official scanned turnout row; OCR geometry verified'],
            'official_detailed_pdf_turnout' => ['official detailed-result PDF turnout row; candidates reconcile'],
            default => [],
        };
        $expectedNote = match ($code) {
            'official_turnout_from_residual_source' => 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.',
            'official_detailed_pdf_turnout' => 'Official detailed-result PDF prints turnout. Original extraction and candidate warnings remain available for review.',
            default => null,
        };
        $totals = $record['turnout_totals'] ?? null;
        $page = $record['turnout_source_page'] ?? null;

        return ($record['status'] ?? '') === 'needs_review'
            && $expectedNote !== null && ($record['error'] ?? null) === $expectedNote
            && is_array($totals) && in_array($totals['method'] ?? null, $methods, true)
            && $this->count($record['electors'] ?? null) && $record['electors'] > 0
            && $this->count($record['votes_polled'] ?? null) && $record['votes_polled'] > 0
            && $record['votes_polled'] <= $record['electors']
            && ($totals['electors'] ?? null) === $record['electors']
            && ($totals['votes_polled'] ?? null) === $record['votes_polled']
            && $this->count($page) && $page > 0 && ($totals['source_page'] ?? null) === $page
            && preg_match('/^[a-zA-Z0-9._-]+\.pdf$/', $record['turnout_source_file'] ?? '') === 1
            && preg_match('/^[a-f0-9]{64}$/', $record['turnout_source_sha256'] ?? '') === 1;
    }

    /** The archived workbook rows must reconcile exactly with the official PDF turnout row. */
    private function hasReconciledWorkbookDetailVotes(array $record): bool
    {
        if (! $this->hasDocumentedOfficialTurnout($record)
            || ($record['number_of_seats'] ?? 1) !== 1) {
            return false;
        }
        $candidates = $record['candidates'] ?? null;
        if (! is_array($candidates) || count($candidates) < 2) {
            return false;
        }
        $seen = [];
        $total = $general = $postal = $valid = $nonNota = 0;
        $previousRow = null;
        foreach ($candidates as $candidate) {
            $name = trim($candidate['candidate_name'] ?? '');
            $party = trim($candidate['party_at_election'] ?? '');
            $row = $candidate['source_values'] ?? null;
            $rowNumber = $candidate['workbook_row'] ?? null;
            $identity = mb_strtolower($name.'|'.$party);
            if ($name === '' || $party === '' || isset($seen[$identity])
                || ($candidate['source_sheet'] ?? null) !== 'DetailedResult'
                || ! is_int($rowNumber) || $rowNumber < 1
                || ($previousRow !== null && $rowNumber !== $previousRow + 1)
                || ! is_array($row) || count($row) !== 13
                || ($row[0] ?? null) !== ($record['code'] ?? null)
                || trim((string) ($row[1] ?? '')) !== trim((string) ($record['name'] ?? ''))
                || ($row[2] ?? null) !== $candidate['candidate_name']
                || ($row[6] ?? null) !== $candidate['party_at_election']
                || ! $this->count($candidate['votes'] ?? null)
                || ! $this->count($candidate['general_votes'] ?? null)
                || ! $this->count($candidate['postal_votes'] ?? null)
                || $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes']
                || $row[7] !== $candidate['general_votes']
                || $row[8] !== $candidate['postal_votes']
                || $row[9] !== $candidate['votes']
                || $row[10] !== $record['electors']
                || $row[11] !== $record['votes_polled']) {
                return false;
            }
            $seen[$identity] = true;
            $previousRow = $rowNumber;
            $total += $candidate['votes'];
            $general += $candidate['general_votes'];
            $postal += $candidate['postal_votes'];
            if (! ($candidate['is_nota'] ?? false)) {
                $valid += $candidate['votes'];
                $nonNota++;
            }
        }

        return $nonNota >= 2
            && $total === $record['votes_polled']
            && $general === ($record['turnout_totals']['general_votes'] ?? null)
            && $postal === ($record['turnout_totals']['postal_votes'] ?? null)
            && (! isset($record['valid_candidate_votes']) || $valid === $record['valid_candidate_votes']);
    }

    private function hasSummaryTurnoutWithDuplicateCandidates(array $record): bool
    {
        $summary = $record['summary_totals'] ?? null;
        $seatName = preg_replace('/[^a-z0-9]/', '', strtolower($record['constituency_name'] ?? ''));
        $locator = preg_replace('/[^a-z0-9]/', '', strtolower($record['summary_locator'] ?? ''));

        return ($record['status'] ?? '') === 'needs_review'
            && in_array($record['error'] ?? '', ['Duplicate candidate identities require review', self::WORKBOOK_REPEATED_NAMES_RESULT], true)
            && ! isset($record['source_warning_code'])
            && is_array($summary) && $seatName !== '' && str_contains($locator, $seatName)
            && trim($record['source_locator'] ?? '') !== ''
            && $this->count($summary['electors'] ?? null) && $summary['electors'] > 0
            && $summary['electors'] === ($record['electors'] ?? null)
            && $this->count($summary['votes_polled'] ?? null) && $summary['votes_polled'] > 0
            && $summary['votes_polled'] === ($record['votes_polled'] ?? null)
            && $summary['votes_polled'] <= $summary['electors']
            && $this->count($summary['valid_candidate_votes'] ?? null)
            && $summary['valid_candidate_votes'] > 0
            && $summary['valid_candidate_votes'] <= $summary['votes_polled']
            && $summary['valid_candidate_votes'] === ($record['valid_candidate_votes'] ?? null);
    }

    private function hasDocumentedLegacyCandidateDifference(array $record, int $candidateVotes): bool
    {
        if (! $this->hasDocumentedSourceDifference($record)
            || preg_match('/^Candidate sum (\d+); detailed total (\d+); summary valid votes (\d+)\. Totals differ\.$/', $record['error'] ?? '', $matches) !== 1) {
            return false;
        }

        $difference = abs($candidateVotes - $record['valid_candidate_votes']);

        return $candidateVotes === (int) $matches[1]
            && $candidateVotes === (int) $matches[2]
            && $record['valid_candidate_votes'] === (int) $matches[3]
            && $difference > 0 && $difference <= 10
            && $difference * 1000 <= $record['valid_candidate_votes'];
    }

    private function hasOfficialWorkbookSummaryTurnout(array $record): bool
    {
        $error = $record['error'] ?? '';
        $summary = $record['summary_totals'] ?? null;
        $rows = $record['summary_source_rows'] ?? null;
        if (($record['status'] ?? '') !== 'needs_review' || isset($record['source_warning_code'])
            || (! str_contains($error, 'The official workbook has structural inconsistencies. Stored cell values are retained')
                && ! str_contains($error, 'Candidate vote cells are missing or non-numeric; original cells are preserved.'))
            || ! str_starts_with($record['summary_locator'] ?? '', 'Summary sheet ')
            || ! is_array($rows) || $rows === [] || ! is_array($summary)
            || ! $this->count($summary['electors'] ?? null) || $summary['electors'] < 1
            || ! $this->count($summary['votes_polled'] ?? null) || $summary['votes_polled'] < 1
            || $summary['votes_polled'] > $summary['electors']
            || $summary['electors'] !== ($record['electors'] ?? null)
            || $summary['votes_polled'] !== ($record['votes_polled'] ?? null)) {
            return false;
        }

        $seatName = preg_replace('/[^a-z0-9]/', '', strtolower($record['constituency_name'] ?? $record['name'] ?? ''));
        $summaryHeading = preg_replace('/[^a-z0-9]/', '', strtolower(json_encode(array_slice($rows, 0, 3), JSON_THROW_ON_ERROR)));
        if ($seatName === '' || ! str_contains($summaryHeading, $seatName)) {
            return false;
        }

        $foundElectors = $foundVoters = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if (! is_int($cell) && ! is_float($cell)) {
                    continue;
                }
                $foundElectors = $foundElectors || $cell == $summary['electors'];
                $foundVoters = $foundVoters || $cell == $summary['votes_polled'];
            }
        }

        return $foundElectors && $foundVoters;
    }

    /** @return array{winner: string, party: string|null, margin: int}|null */
    /** @return array{winner: string, party: string, margin: int}|null */
    private function officialRepeatedNameWorkbookResult(array $record): ?array
    {
        if (($record['error'] ?? '') !== self::WORKBOOK_REPEATED_NAMES_RESULT
            || ! $this->hasSummaryTurnoutWithDuplicateCandidates($record)) {
            return null;
        }

        $result = $record['summary_result'] ?? null;
        $sheet = $record['summary_result_source_sheet'] ?? null;
        if (! is_array($result) || ! is_string($sheet) || trim($sheet) === ''
            || ! str_starts_with($record['summary_locator'] ?? '', $sheet.':')
            || preg_match('/^[a-zA-Z0-9._-]+\.xlsx$/', $record['summary_result_source_file'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/', $record['summary_result_source_sha256'] ?? '') !== 1
            || ! $this->count($result['winner_votes'] ?? null)
            || ! $this->count($result['runner_votes'] ?? null)
            || ! $this->count($result['margin'] ?? null)
            || $result['winner_votes'] <= $result['runner_votes']
            || $result['margin'] !== $result['winner_votes'] - $result['runner_votes']
            || $result['winner_votes'] > ($record['summary_totals']['valid_candidate_votes'] ?? 0)) {
            return null;
        }

        $candidates = collect($record['candidates'] ?? [])->sortByDesc('votes')->values();
        if ($candidates->count() < 2 || $candidates[0]['votes'] <= $candidates[1]['votes']) {
            return null;
        }
        $nota = $candidates->filter(fn (array $candidate): bool => ($candidate['is_nota'] ?? false) === true);
        $sourceRows = $candidates->pluck('source_row');
        if ($nota->count() !== 1
            || ! $this->count($record['summary_totals']['nota_votes'] ?? null)
            || $nota->first()['votes'] !== $record['summary_totals']['nota_votes']
            || ! $this->count($record['summary_candidate_count'] ?? null)
            || $candidates->count() - 1 !== $record['summary_candidate_count']
            || $sourceRows->unique()->count() !== $candidates->count()
            || $candidates->sum('votes') !== $record['summary_totals']['valid_candidate_votes'] + $record['summary_totals']['nota_votes']) {
            return null;
        }
        foreach (['winner', 'runner'] as $index => $label) {
            $candidate = $candidates[$index];
            if (! is_string($result[$label] ?? null) || trim($result[$label]) === ''
                || ! is_string($result[$label.'_party'] ?? null) || trim($result[$label.'_party']) === ''
                || mb_strtolower(trim($result[$label])) !== mb_strtolower(trim($candidate['candidate_name'] ?? ''))
                || trim($result[$label.'_party']) !== trim($candidate['party_at_election'] ?? '')
                || $result[$label.'_votes'] !== $candidate['votes']) {
                return null;
            }
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    private function officialWorkbookSummaryResult(array $record): ?array
    {
        if (! $this->hasOfficialWorkbookSummaryTurnout($record)
            || ! is_string($record['winner'] ?? null) || trim($record['winner']) === ''
            || ! $this->count($record['margin'] ?? null) || $record['margin'] < 1) {
            return null;
        }

        $rows = $record['summary_source_rows'];
        $validVotes = $record['summary_totals']['valid_candidate_votes'] ?? null;
        if (! $this->count($validVotes) || $validVotes < 1 || $validVotes > $record['votes_polled']
            || ! collect($rows)->contains(fn (array $row): bool => collect($row)->contains(fn ($cell): bool => (is_int($cell) || is_float($cell)) && $cell == $validVotes))) {
            return null;
        }

        $rowFor = fn (string $label): ?array => collect($rows)->first(fn (array $row): bool => in_array($label, $row, true));
        $winnerRow = $rowFor('Winner');
        $runnerRow = $rowFor('Runner-Up');
        $marginRow = $rowFor('Margin');
        $singleSpaced = static fn (string $name): string => trim(preg_replace('/\s+/u', ' ', $name));
        if ($winnerRow === null || $runnerRow === null || $marginRow === null
            || ! collect($winnerRow)->contains(fn ($cell): bool => is_string($cell)
                && $singleSpaced($cell) === $singleSpaced($record['winner']))) {
            return null;
        }

        $vote = function (array $row): ?int {
            $numbers = array_values(array_filter($row, fn ($cell): bool => (is_int($cell) || is_float($cell)) && $cell >= 0 && floor($cell) == $cell));

            return count($numbers) === 1 ? (int) $numbers[0] : null;
        };
        $winnerVotes = $vote($winnerRow);
        $runnerVotes = $vote($runnerRow);
        $sourceMargin = $vote($marginRow);
        if ($winnerVotes === null || $runnerVotes === null || $sourceMargin === null
            || $winnerVotes <= $runnerVotes || $winnerVotes - $runnerVotes !== $sourceMargin
            || $sourceMargin !== $record['margin']) {
            return null;
        }

        $candidate = collect($record['candidates'] ?? [])->firstWhere('candidate_name', $record['winner']);

        return ['winner' => $record['winner'], 'party' => $candidate['party_at_election'] ?? null, 'margin' => $sourceMargin];
    }

    /** @return array{winner: string, party: string, margin: int}|null */
    private function officialPdfSummaryResult(array $record): ?array
    {
        $missingVoterTotal = ($record['source_warning_code'] ?? null) === 'official_summary_result_without_turnout';
        $postalSummary = ($record['source_warning_code'] ?? null) === 'official_general_valid_plus_postal';
        if (! in_array($record['source_warning_code'] ?? '', ['official_summary_turnout_only', 'summary_only_turnout', 'summary_turnout_with_detail_warnings', 'summary_elector_difference', 'round_specific_summary', 'official_summary_result_without_turnout', 'official_general_valid_plus_postal'], true)
            || ! isset($record['summary_source_file'], $record['summary_source_sha256'])
            || (! $missingVoterTotal && ! $this->hasCorroboratedTurnout($record))
            || ($record['number_of_seats'] ?? 1) !== 1) {
            return null;
        }
        if ($missingVoterTotal
            && (($record['status'] ?? null) !== 'needs_review'
                || ! in_array($record['code'] ?? null, [22, 24, 33, 46], true)
                || ($record['summary_page'] ?? null) !== $record['code'] + 12
                || ($record['summary_source_file'] ?? null) !== 'dc469be7915c7a5a9e699aac-9579.pdf'
                || ($record['summary_source_sha256'] ?? null) !== 'a6a2d830c8969fc7364cd70457593b46bc5edf5b854284bae52668ee9d05f76b'
                || ($record['votes_polled'] ?? null) !== null
                || ($record['summary_totals']['votes_polled'] ?? null) !== null
                || ($record['summary_totals']['electors'] ?? null) !== ($record['electors'] ?? null)
                || ($record['summary_totals']['valid_candidate_votes'] ?? null) !== ($record['valid_candidate_votes'] ?? null)
                || ! is_string($record['original_extraction_warning'] ?? null)
                || ! is_string($record['previous_review_note'] ?? null))) {
            return null;
        }
        if (($record['source_warning_code'] ?? null) === 'round_specific_summary') {
            $round = $record['election_round'] ?? null;
            $source = match ($round) {
                '2005-feb' => ['faf93ea0918e67d6bc68067a-9224.pdf', '61b09fc09a7d06373b63174ea5cda8f276cec6f2419b1b91415d7c3d8550ca84', 100000],
                '2005-oct' => ['faf93ea0918e67d6bc68067a-9236.pdf', 'ff4f6192840cd830eabbac40ee7f06650e348f12e22b20ea4ee66575c44d963e', 200000],
                default => null,
            };
            if ($source === null || ! $this->count($record['official_ac_code'] ?? null)
                || $record['official_ac_code'] < 1 || $record['official_ac_code'] > 243
                || ($record['code'] ?? null) !== $source[2] + $record['official_ac_code']
                || ! str_ends_with($record['name'] ?? '', ' / '.$round)
                || ($record['summary_source_file'] ?? null) !== $source[0]
                || ($record['summary_source_sha256'] ?? null) !== $source[1]
                || ! $this->count($record['summary_page'] ?? null) || $record['summary_page'] < 1
                || ($record['summary_totals']['valid_candidate_votes'] ?? null) !== ($record['valid_candidate_votes'] ?? null)
                || ! is_string($record['original_extraction_warning'] ?? null)
                || ! is_string($record['previous_review_note'] ?? null)) {
                return null;
            }
        }

        $result = $record['summary_result'] ?? null;
        if (! is_array($result) || ! is_string($result['winner'] ?? null)
            || trim($result['winner']) === '' || ! is_string($result['winner_party'] ?? null)
            || trim($result['winner_party']) === '' || ! is_string($result['runner'] ?? null)
            || trim($result['runner']) === '' || ! is_string($result['runner_party'] ?? null)
            || trim($result['runner_party']) === '' || ! $this->count($result['winner_votes'] ?? null)
            || ! $this->count($result['runner_votes'] ?? null) || ! $this->count($result['margin'] ?? null)
            || $result['winner_votes'] <= $result['runner_votes']
            || $result['margin'] !== $result['winner_votes'] - $result['runner_votes']
            || $result['winner_votes'] > ($postalSummary
                ? ($record['valid_candidate_votes'] ?? 0)
                : ($record['summary_totals']['valid_candidate_votes'] ?? 0))) {
            return null;
        }

        if ($postalSummary) {
            $ranked = collect($record['candidates'] ?? [])->sortByDesc('votes')->values();
            if ($ranked->count() < 2 || $ranked[0]['votes'] <= $ranked[1]['votes']
                || $result !== ['winner' => $ranked[0]['candidate_name'],
                    'winner_party' => $ranked[0]['party_at_election'], 'winner_votes' => $ranked[0]['votes'],
                    'runner' => $ranked[1]['candidate_name'], 'runner_party' => $ranked[1]['party_at_election'],
                    'runner_votes' => $ranked[1]['votes'], 'margin' => $ranked[0]['votes'] - $ranked[1]['votes']]) {
                return null;
            }
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    /** Both 1989 volumes print the same declared result, while some aggregate totals differ. */
    private function officialPc1989DiscrepancyResult(array $record): ?array
    {
        $source = match ($record['code'] ?? null) {
            49 => ['state' => 'BIHAR', 'name' => 'SIWAN', 'detail_page' => 154, 'summary_page' => 55,
                'detail' => ['electors' => 965656, 'votes_polled' => 562288, 'valid_candidate_votes' => 552892],
                'summary' => ['electors' => 965656, 'votes_polled' => 562244, 'valid_candidate_votes' => 552798],
                'candidate_count' => 20,
                'warning' => 'Detailed and summary votes polled differ; Detailed and summary valid candidate votes differ',
                'result' => ['winner' => 'JANARDAN TIWARI', 'winner_party' => 'BJP', 'winner_votes' => 334637,
                    'runner' => 'ABDUL GAFFUR', 'runner_party' => 'INC', 'runner_votes' => 175686, 'margin' => 158951]],
            138 => ['state' => 'HIMACHAL PRADESH', 'name' => 'MANDI', 'detail_page' => 185, 'summary_page' => 144,
                'detail' => ['electors' => 756145, 'votes_polled' => 470730, 'valid_candidate_votes' => 464947],
                'summary' => ['electors' => 756545, 'votes_polled' => 470730, 'valid_candidate_votes' => 464949],
                'candidate_count' => 7,
                'warning' => 'Detailed and summary electors differ; Detailed and summary valid candidate votes differ',
                'result' => ['winner' => 'MAHESHWAR SINGH', 'winner_party' => 'BJP', 'winner_votes' => 234164,
                    'runner' => 'SUKH RAM', 'runner_party' => 'INC', 'runner_votes' => 206095, 'margin' => 28069]],
            default => null,
        };
        if ($source === null || ($record['source_warning_code'] ?? null) !== 'official_pc_1989_report_discrepancy'
            || ($record['status'] ?? null) !== 'needs_review' || ($record['number_of_seats'] ?? 1) !== 1
            || ($record['state_name'] ?? null) !== $source['state']
            || ($record['constituency_name'] ?? null) !== $source['name']
            || ($record['detail_page'] ?? null) !== $source['detail_page']
            || ($record['summary_page'] ?? null) !== $source['summary_page']
            || ($record['original_extraction_warning'] ?? null) !== $source['warning']
            || ($record['error'] ?? null) !== self::PC_1989_DISCREPANCY_NOTE
            || ($record['official_source_url'] ?? null) !== 'https://old.eci.gov.in/files/file/4120-general-election-1989-vol-i-ii/'
            || ($record['detail_source_file'] ?? null) !== '92de082304013ee1f62be87a-9761.pdf'
            || ($record['detail_source_sha256'] ?? null) !== 'e67c8f9fa3058bbc51579dfe45c7665ea7eb5092378e412d85318d6350980b3b'
            || ($record['summary_source_file'] ?? null) !== '92de082304013ee1f62be87a-9762.pdf'
            || ($record['summary_source_sha256'] ?? null) !== '801ffa9db8ebe320968b17cc8195c83c94eefec94bc8368382392083ce5e15e3'
            || ($record['detailed_report_totals'] ?? null) !== $source['detail']
            || ($record['summary_totals'] ?? null) !== $source['summary']
            || ($record['summary_result'] ?? null) !== $source['result']
            || ($record['electors'] ?? null) !== $source['detail']['electors']
            || ($record['votes_polled'] ?? null) !== $source['detail']['votes_polled']
            || ($record['valid_candidate_votes'] ?? null) !== $source['detail']['valid_candidate_votes']) {
            return null;
        }

        $candidates = $record['candidates'] ?? null;
        if (! is_array($candidates) || count($candidates) !== $source['candidate_count']
            || collect($candidates)->contains(fn ($candidate): bool => ! is_array($candidate)
                || trim($candidate['candidate_name'] ?? '') === ''
                || trim($candidate['party_at_election'] ?? '') === ''
                || ! $this->count($candidate['votes'] ?? null))
            || array_sum(array_column($candidates, 'votes')) !== $source['detail']['valid_candidate_votes']) {
            return null;
        }
        $ranked = collect($candidates)->sortByDesc('votes')->values();
        $result = $source['result'];
        if ($ranked[0]['votes'] <= $ranked[1]['votes']
            || $ranked[0]['candidate_name'] !== $result['winner']
            || $ranked[0]['party_at_election'] !== $result['winner_party']
            || $ranked[0]['votes'] !== $result['winner_votes']
            || $ranked[1]['candidate_name'] !== $result['runner']
            || $ranked[1]['party_at_election'] !== $result['runner_party']
            || $ranked[1]['votes'] !== $result['runner_votes']
            || $ranked[0]['votes'] - $ranked[1]['votes'] !== $result['margin']) {
            return null;
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    private function hasOfficialPostalSummary(array $record): bool
    {
        $source = match ($record['code'] ?? null) {
            22 => ['HABBAKADAL', 59329, 10188, 2998, 7004, 186, 3184, 104, 5],
            23 => ['AMIRAKADAL', 56462, 7141, 6025, 640, 476, 6501, 104, 7],
            43 => ['KOKERNAG', 58733, 23866, 21985, 943, 938, 22923, 108, 7],
            default => null,
        };
        if ($source === null) {
            return false;
        }
        [$name, $electors, $polled, $generalValid, $postal, $rejected, $detailPolled, $detailPage, $candidateCount] = $source;
        $summary = $record['summary_totals'] ?? null;
        $detail = $record['original_detail_totals'] ?? null;
        $difference = $record['candidate_source_discrepancy'] ?? null;
        $candidates = $record['candidates'] ?? null;
        if (($record['source_warning_code'] ?? null) !== 'official_general_valid_plus_postal'
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['name'] ?? null) !== $name
            || ($record['state_name'] ?? null) !== 'Jammu & Kashmir'
            || ($record['number_of_seats'] ?? null) !== 1
            || ($record['detail_page'] ?? null) !== $detailPage
            || ($record['summary_page'] ?? null) !== $record['code'] + 13
            || ($record['summary_source_file'] ?? null) !== 'b837e6720774f65f5f1b0934-8930.pdf'
            || ($record['summary_source_sha256'] ?? null) !== '5a87a21648681a2eed44228cf28c098dc38f34d2ec59fc858acdf49e7bedb4ee'
            || ($record['original_extraction_warning'] ?? null) !== self::LEGACY_DETAIL_PENDING.'; Reported elector and voter totals are inconsistent.'
            || ($record['error'] ?? null) !== self::POSTAL_SUMMARY_NOTE
            || ($record['electors'] ?? null) !== $electors
            || ($record['votes_polled'] ?? null) !== $polled
            || ($record['valid_candidate_votes'] ?? null) !== $generalValid + $postal
            || $summary !== ['electors' => $electors, 'votes_polled' => $polled,
                'valid_candidate_votes' => $generalValid, 'postal_votes' => $postal,
                'rejected_votes' => $rejected]
            || $detail !== ['electors' => $electors, 'votes_polled' => $detailPolled,
                'valid_candidate_votes' => $generalValid + $postal]
            || $difference !== ['candidate_sum' => $generalValid + $postal,
                'printed_general_valid_votes' => $generalValid, 'postal_votes' => $postal]
            || ! is_array($candidates) || count($candidates) !== $candidateCount
            || array_sum(array_column($candidates, 'votes')) !== $generalValid + $postal
            || $polled !== $generalValid + $postal + $rejected
            || $detailPolled + $postal !== $polled) {
            return false;
        }

        return collect($candidates)->every(fn ($candidate): bool => is_array($candidate)
            && $this->count($candidate['votes'] ?? null)
            && trim($candidate['candidate_name'] ?? '') !== ''
            && trim($candidate['party_at_election'] ?? '') !== '');
    }

    /** A fully reconciled detailed PDF page can establish a result without a separate summary page. */
    private function officialResidualDetailResult(array $record): ?array
    {
        if (($record['source_warning_code'] ?? null) !== 'official_turnout_from_residual_source'
            || ! $this->hasDocumentedOfficialTurnout($record)
            || ($record['number_of_seats'] ?? 1) !== 1) {
            return null;
        }
        $result = $record['official_detail_result'] ?? null;
        $allCandidates = $record['candidates'] ?? null;
        if (! is_array($result) || ! is_array($allCandidates) || count($allCandidates) < 2
            || ($result['source_file'] ?? null) !== ($record['turnout_source_file'] ?? null)
            || ($result['source_sha256'] ?? null) !== ($record['turnout_source_sha256'] ?? null)) {
            return null;
        }
        $duplicatePreview = isset($result['duplicate_preview_page']);
        if ($duplicatePreview) {
            if (($result['duplicate_preview_page'] ?? null) !== ($record['detail_page'] ?? null)
                || ($result['source_page'] ?? null) !== ($record['turnout_source_page'] ?? null)
                || $result['source_page'] <= $result['duplicate_preview_page']
                || isset($result['source_pages'])
                || ! $this->count($result['verified_valid_candidate_votes'] ?? null)) {
                return null;
            }
            $candidates = array_values(array_filter($allCandidates,
                fn (array $candidate): bool => ($candidate['source_page'] ?? null) === $result['source_page']));
            $preview = array_values(array_filter($allCandidates,
                fn (array $candidate): bool => ($candidate['source_page'] ?? null) === $result['duplicate_preview_page']));
            if (count($candidates) < 2 || count($preview) < 1 || count($preview) > 2
                || count($candidates) + count($preview) !== count($allCandidates)) {
                return null;
            }
            foreach ($preview as $candidate) {
                $matches = array_filter($candidates, fn (array $complete): bool => ($candidate['candidate_name'] ?? null) === ($complete['candidate_name'] ?? null)
                    && ($candidate['party_at_election'] ?? null) === ($complete['party_at_election'] ?? null)
                    && ($candidate['votes'] ?? null) === ($complete['votes'] ?? null)
                    && ($candidate['postal_votes'] ?? null) === ($complete['postal_votes'] ?? null)
                    && (($candidate['general_votes'] ?? null) === null
                        || $candidate['general_votes'] === ($complete['general_votes'] ?? null)));
                if (count($matches) !== 1) {
                    return null;
                }
            }
        } else {
            if (($result['source_page'] ?? null) !== ($record['detail_page'] ?? null)) {
                return null;
            }
            $candidates = $allCandidates;
        }
        $pages = $result['source_pages'] ?? [$result['source_page']];
        if (! is_array($pages) || ! array_is_list($pages)
            || (isset($result['source_pages']) && count($pages) !== 2)
            || (! isset($result['source_pages']) && count($pages) !== 1)
            || $pages[0] !== $result['source_page']
            || $pages[count($pages) - 1] !== ($record['turnout_source_page'] ?? null)
            || (count($pages) === 2 && $pages[1] !== $pages[0] + 1)) {
            return null;
        }
        $seen = [];
        $total = $valid = $general = $postal = 0;
        $previousPage = $pages[0];
        foreach ($candidates as $index => $candidate) {
            $name = trim($candidate['candidate_name'] ?? '');
            $party = trim($candidate['party_at_election'] ?? '');
            if ($name === '' || $party === '' || isset($seen[mb_strtolower($name.'|'.$party)])
                || ($candidate['source_row'] ?? null) !== $index + 1
                || ! in_array($candidate['source_page'] ?? null, $pages, true)
                || $candidate['source_page'] < $previousPage
                || ! $this->count($candidate['votes'] ?? null)
                || ! $this->count($candidate['general_votes'] ?? null)
                || ! $this->count($candidate['postal_votes'] ?? null)
                || $candidate['votes'] !== $candidate['general_votes'] + $candidate['postal_votes']) {
                return null;
            }
            $seen[mb_strtolower($name.'|'.$party)] = true;
            $previousPage = $candidate['source_page'];
            $total += $candidate['votes'];
            $general += $candidate['general_votes'];
            $postal += $candidate['postal_votes'];
            if (! ($candidate['is_nota'] ?? false)) {
                $valid += $candidate['votes'];
            }
        }
        $ranked = collect($candidates)->reject(fn (array $candidate): bool => ($candidate['is_nota'] ?? false))
            ->sortByDesc('votes')->values();
        if ($ranked->count() < 2 || $ranked[0]['votes'] <= $ranked[1]['votes']
            || $total !== ($record['votes_polled'] ?? null)
            || $valid !== ($duplicatePreview
                ? ($result['verified_valid_candidate_votes'] ?? null)
                : ($record['valid_candidate_votes'] ?? null))
            || $general !== ($record['turnout_totals']['general_votes'] ?? null)
            || $postal !== ($record['turnout_totals']['postal_votes'] ?? null)
            || ($result['winner'] ?? null) !== $ranked[0]['candidate_name']
            || ($result['winner_party'] ?? null) !== $ranked[0]['party_at_election']
            || ($result['winner_votes'] ?? null) !== $ranked[0]['votes']
            || ($result['runner'] ?? null) !== $ranked[1]['candidate_name']
            || ($result['runner_party'] ?? null) !== $ranked[1]['party_at_election']
            || ($result['runner_votes'] ?? null) !== $ranked[1]['votes']
            || ($result['margin'] ?? null) !== $ranked[0]['votes'] - $ranked[1]['votes']) {
            return null;
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    /** The source declares a winner even though two candidates have equal votes. */
    private function officialDeclaredTieResult(array $record): ?array
    {
        $source = match ($record['summary_source_file'] ?? null) {
            'c6fecebc52d31001b62978a5-8744.pdf' => [
                'code' => 43, 'name' => 'MAHAD', 'state' => 'Maharashtra',
                'url' => 'https://old.eci.gov.in/files/file/3714-maharashtra-1962/',
                'sha256' => '3587499768cc77889d6613a4b5a61ec5c55dd196cb1dba9bc98137ce898f7a88',
                'summary_page' => 61, 'detail_page' => 289, 'electors' => 58162,
                'votes_polled' => 36311, 'valid_candidate_votes' => 34013, 'candidates' => 5,
                'winner' => ['name' => 'SHANKAR BABAJI SAWANT', 'party' => 'INC', 'votes' => 12664],
                'runner' => ['name' => 'SAKHARAM VITHOBA SALUNKE', 'party' => 'PSP', 'votes' => 12664],
            ],
            '59e71c23b10c39b1a339d4c9-8623.pdf' => [
                'code' => 54, 'name' => 'KHERAPARA (ST)', 'state' => 'Meghalaya',
                'url' => 'https://old.eci.gov.in/files/file/3674-meghalaya-1988/',
                'sha256' => '0db3e9f2e504d3dc1c1599a22a8bbb7a0d82b8a68dc5ecd1b4dd64e6f8b9bd24',
                'summary_page' => 65, 'detail_page' => 79, 'electors' => 12209,
                'votes_polled' => 8947, 'valid_candidate_votes' => 8623, 'candidates' => 4,
                'winner' => ['name' => 'CHAMBERUN MARAK', 'party' => 'IND', 'votes' => 2591],
                'runner' => ['name' => 'ROSTER M. SANGMA', 'party' => 'INC', 'votes' => 2591],
            ],
            default => null,
        };
        if ($source === null || ($record['source_warning_code'] ?? null) !== 'official_declared_tie'
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['code'] ?? null) !== $source['code'] || ($record['name'] ?? null) !== $source['name']
            || ($record['state_name'] ?? null) !== $source['state']
            || ($record['number_of_seats'] ?? null) !== 1
            || ($record['original_extraction_warning'] ?? null) !== self::LEGACY_DETAIL_PENDING
            || ($record['error'] ?? null) !== 'The official summary declares a winner after equal candidate votes; the winning margin is zero. Check the linked report.'
            || ($record['official_source_url'] ?? null) !== $source['url']
            || ($record['summary_source_sha256'] ?? null) !== $source['sha256']
            || ($record['summary_page'] ?? null) !== $source['summary_page']
            || ($record['detail_page'] ?? null) !== $source['detail_page']
            || ($record['electors'] ?? null) !== $source['electors']
            || ($record['votes_polled'] ?? null) !== $source['votes_polled']
            || ($record['valid_candidate_votes'] ?? null) !== $source['valid_candidate_votes']
            || ($record['summary_totals'] ?? null) !== ['electors' => $source['electors'],
                'votes_polled' => $source['votes_polled'], 'valid_candidate_votes' => $source['valid_candidate_votes']]
            || ($record['summary_result'] ?? null) !== ['winner' => $source['winner']['name'],
                'winner_party' => $source['winner']['party'], 'winner_votes' => $source['winner']['votes'],
                'runner' => $source['runner']['name'], 'runner_party' => $source['runner']['party'],
                'runner_votes' => $source['runner']['votes'], 'margin' => 0]) {
            return null;
        }
        $candidates = $record['candidates'] ?? [];
        if (count($candidates) !== $source['candidates']
            || collect($candidates)->sum('votes') !== $source['valid_candidate_votes']
            || collect($candidates)->max('votes') !== $source['winner']['votes']
            || collect($candidates)->where('votes', $source['winner']['votes'])->count() !== 2
            || ! collect($candidates)->contains(fn (array $candidate): bool => $candidate['candidate_name'] === $source['winner']['name']
                && $candidate['party_at_election'] === $source['winner']['party']
                && $candidate['votes'] === $source['winner']['votes'])
            || ! collect($candidates)->contains(fn (array $candidate): bool => $candidate['candidate_name'] === $source['runner']['name']
                && $candidate['party_at_election'] === $source['runner']['party']
                && $candidate['votes'] === $source['runner']['votes'])) {
            return null;
        }

        return ['winner' => $source['winner']['name'], 'party' => $source['winner']['party'], 'margin' => 0];
    }

    /** Three official summaries disagree with their own vote arithmetic; show declarations without turnout. */
    private function officialAc1971WestBengalVoterConflict(array $record): ?array
    {
        $source = match ($record['code'] ?? null) {
            84 => ['DEGANGA', 100, 309, 74781, 47151, 46831, 43369, 3782, 8,
                'HARUN OP RASHID', 'IND', 20142, 'M. SAWKFTALI', 'INC', 9191, 10951],
            92 => ['SANDESHKHALI (ST)', 108, 311, 73851, 51512, 51422, 49289, 2223, 5,
                'SARAT SARDER', 'CPM', 20053, 'DEBENDRA NATH SINHA', 'INC', 20006, 47],
            98 => ['BARUIPUR (SC)', 114, 312, 78104, 57293, 87293, 54571, 2722, 5,
                'BIMAL MISTRY', 'CPM', 19711, 'RAM KANTA MANDAL', 'INC', 19265, 446],
            default => null,
        };
        if ($source === null || ($record['source_warning_code'] ?? null) !== 'official_ac_voter_total_conflict'
            || ($record['status'] ?? null) !== 'needs_review' || ($record['number_of_seats'] ?? null) !== 1
            || ($record['state_name'] ?? null) !== 'West Bengal' || ($record['name'] ?? null) !== $source[0]
            || ($record['summary_page'] ?? null) !== $source[1]
            || ($record['detail_page'] ?? null) !== $source[2]
            || ($record['electors'] ?? null) !== $source[3]
            || ($record['votes_polled'] ?? null) !== $source[4]
            || ($record['valid_candidate_votes'] ?? null) !== $source[6]
            || ($record['summary_source_file'] ?? null) !== 'fed20e0bd380929811cd1a1f-7309.pdf'
            || ($record['summary_source_sha256'] ?? null) !== '6350616cdca377cb5fe5e77006e098e966e2bdbcdc60a08d25d4b0cae832a305'
            || ($record['official_source_url'] ?? null) !== 'https://old.eci.gov.in/files/file/3186-west-bengal-general-legislative-election-1971/'
            || ($record['original_extraction_warning'] ?? null) !== self::LEGACY_DETAIL_PENDING
            || ($record['error'] ?? null) !== 'The official summary voter total conflicts with its valid and rejected vote totals. The declared winner and margin are shown for review; turnout is withheld.'
            || ($record['summary_totals'] ?? null) !== ['electors' => $source[3], 'votes_polled' => $source[5], 'valid_candidate_votes' => $source[6]]
            || ($record['source_discrepancy'] ?? null) !== ['field' => 'votes_polled', 'detail_value' => $source[4],
                'summary_value' => $source[5], 'valid_votes' => $source[6], 'rejected_votes' => $source[7]]
            || ($record['summary_result'] ?? null) !== ['winner' => $source[9], 'winner_party' => $source[10],
                'winner_votes' => $source[11], 'runner' => $source[12], 'runner_party' => $source[13],
                'runner_votes' => $source[14], 'margin' => $source[15]]) {
            return null;
        }

        $candidates = $record['candidates'] ?? null;
        if (! is_array($candidates) || count($candidates) !== $source[8]
            || array_sum(array_column($candidates, 'votes')) !== $source[6]
            || $source[4] !== $source[6] + $source[7]
            || $source[5] === $source[4]
            || $source[15] !== $source[11] - $source[14]) {
            return null;
        }
        $ranked = collect($candidates)->sortByDesc('votes')->values();
        if (($ranked[0]['candidate_name'] ?? null) !== $source[9]
            || ($ranked[0]['party_at_election'] ?? null) !== $source[10]
            || ($ranked[0]['votes'] ?? null) !== $source[11]
            || ($ranked[1]['candidate_name'] ?? null) !== $source[12]
            || ($ranked[1]['party_at_election'] ?? null) !== $source[13]
            || ($ranked[1]['votes'] ?? null) !== $source[14]) {
            return null;
        }

        return ['winner' => $source[9], 'party' => $source[10], 'margin' => $source[15]];
    }

    /** The official declaration can establish a result even when its voter total cannot establish turnout. */
    private function officialInvalidTurnoutResult(array $record): ?array
    {
        $source = match ($record['summary_source_file'] ?? null) {
            '7a130d7480f6fd17a797d5aa-7685.pdf' => match ($record['code'] ?? null) {
                152 => [
                    'code' => 152, 'name' => 'PERAMBALUR (SC)', 'state' => 'Tamil Nadu',
                    'url' => 'https://old.eci.gov.in/files/file/3326-tamil-nadu-1971/',
                    'sha256' => '9c57d16fc1e05bd43aa0896e80fe6fc946960eb115a360b6b8099fcd404a6dcd',
                    'summary_page' => 166, 'detail_page' => 267, 'electors' => 55108,
                    'votes_polled' => 74732, 'valid_candidate_votes' => 70623, 'candidates' => 4,
                    'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
                ],
                195 => [
                    'code' => 195, 'name' => 'ILAYANGUDI', 'state' => 'Tamil Nadu',
                    'url' => 'https://old.eci.gov.in/files/file/3326-tamil-nadu-1971/',
                    'sha256' => '9c57d16fc1e05bd43aa0896e80fe6fc946960eb115a360b6b8099fcd404a6dcd',
                    'summary_page' => 209, 'detail_page' => 272, 'electors' => 58857,
                    'votes_polled' => 75258, 'valid_candidate_votes' => 73482, 'candidates' => 5,
                    'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
                ],
                default => null,
            },
            '165392d9f968ef073166ef32-9588.pdf' => [
                'code' => 96, 'name' => 'SATTENPALLI', 'state' => 'Andhra Pradesh',
                'url' => 'https://old.eci.gov.in/files/file/4042-andhra-pradesh-1955/',
                'sha256' => 'b087d7c7f4391e0a6c9b60cbd4cda3c1562d70a29e5b1a85c4723caa18b22255',
                'summary_page' => 109, 'detail_page' => 194, 'electors' => 2473,
                'votes_polled' => 40566, 'valid_candidate_votes' => 40566, 'candidates' => 3,
                'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
            ],
            '402db61ff727c908b4ac3170-7462.pdf' => [
                'code' => 130, 'name' => 'KANPUR CITY NORTH', 'state' => 'Uttar Pradesh',
                'url' => 'https://old.eci.gov.in/files/file/3241-uttar-pradesh-1951/',
                'sha256' => '3c2014c43fcc0c5c4636c84fd43cf6d7a68d967736e3f60d44924a7f641bfdb7',
                'summary_page' => 150, 'detail_page' => 393, 'electors' => 5064,
                'votes_polled' => 28326, 'valid_candidate_votes' => 28326, 'candidates' => 13,
                'original_warning' => 'Electorate and voter totals are inconsistent',
            ],
            '6dfd6b3caf24c34e288769cf-8772.pdf' => [
                'code' => 116, 'name' => 'SAUSAR (ST)', 'state' => 'Madhya Pradesh',
                'url' => 'https://old.eci.gov.in/files/file/3728-madhya-pradesh-1957/',
                'sha256' => '195cd97b8e6b172ebc043127587148960526803a0009670887c4cb13536dfdd2',
                'summary_page' => 134, 'detail_page' => 256, 'electors' => 52018,
                'votes_polled' => 96630, 'valid_candidate_votes' => 96630, 'candidates' => 6,
                'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
            ],
            'd31cb3f180e44ec4b9e59209-7464.pdf' => [
                'code' => 234, 'name' => 'HATA', 'state' => 'Uttar Pradesh',
                'url' => 'https://old.eci.gov.in/files/file/3242-uttar-pradesh-1957/',
                'sha256' => '7e0ea66649df0c20d8e381018ce33e049bc16c139c3facf09b04d935019da98c',
                'summary_page' => 256, 'detail_page' => 406, 'electors' => 7807,
                'votes_polled' => 30962, 'valid_candidate_votes' => 30962, 'candidates' => 4,
                'original_warning' => 'Electorate and voter totals are inconsistent',
            ],
            '7ce40cf47befc2b48ff776e3-7475.pdf' => [
                'code' => 315, 'name' => 'CHHIBRAMAU', 'state' => 'Uttar Pradesh',
                'url' => 'https://old.eci.gov.in/files/file/3247-uttar-pradesh-1969/',
                'sha256' => '6149dddc726ecba33c63091f8452964cef9fd012248ba2ab313ecce77cb2c623',
                'summary_page' => 337, 'detail_page' => 505, 'electors' => 73524,
                'votes_polled' => 80269, 'valid_candidate_votes' => 78013, 'candidates' => 11,
                'original_warning' => 'Electorate and voter totals are inconsistent',
            ],
            '3250a94d4b625ec2bea29016-7702.pdf' => [
                'code' => 103, 'name' => 'THONDAMUTHUR', 'state' => 'Tamil Nadu',
                'url' => 'https://old.eci.gov.in/files/file/3333-tamil-nadu-1989/',
                'sha256' => 'f5ef0578ce8bbbbff6c4d7f53cfa29d4d592e9e1f06b56abef79517ddd162c27',
                'summary_page' => 120, 'detail_page' => 285, 'electors' => 123266,
                'votes_polled' => 151869, 'valid_candidate_votes' => 148168, 'candidates' => 16,
                'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
            ],
            '9982b63a332a67579dae045f-7315.pdf' => [
                'code' => 181, 'name' => 'CHAMPDANI', 'state' => 'West Bengal',
                'url' => 'https://old.eci.gov.in/files/file/3189-west-bengal-1982/',
                'sha256' => 'd7aa7423d5d0e2c252d758df89b7b0274f2303689bfc67d44a4e64732d0d81f3',
                'summary_page' => 197, 'detail_page' => 335, 'electors' => 87335,
                'votes_polled' => 91850, 'valid_candidate_votes' => 89899, 'candidates' => 4,
                'original_warning' => 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Reported elector and voter totals are inconsistent.',
            ],
            default => null,
        };
        $summary = $record['summary_totals'] ?? null;
        $result = $record['summary_result'] ?? null;
        $candidates = $record['candidates'] ?? null;
        if ($source === null || ($record['source_warning_code'] ?? null) !== 'official_ac_declared_result_invalid_turnout'
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['code'] ?? null) !== $source['code'] || ($record['name'] ?? null) !== $source['name']
            || ($record['number_of_seats'] ?? null) !== 1
            || ($record['original_extraction_warning'] ?? null) !== $source['original_warning']
            || ($record['error'] ?? null) !== 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.'
            || ($record['official_summary_state'] ?? null) !== $source['state']
            || (isset($record['state_name']) && $record['state_name'] !== $source['state'])
            || ($record['official_source_url'] ?? null) !== $source['url']
            || ($record['summary_source_sha256'] ?? null) !== $source['sha256']
            || ($record['summary_page'] ?? null) !== $source['summary_page']
            || ($record['detail_page'] ?? null) !== $source['detail_page']
            || ! is_array($summary) || ! is_array($result) || ! is_array($candidates)
            || ($record['electors'] ?? null) !== $source['electors']
            || ($record['votes_polled'] ?? null) !== $source['votes_polled']
            || ($record['valid_candidate_votes'] ?? null) !== $source['valid_candidate_votes']
            || $summary !== ['electors' => $source['electors'], 'votes_polled' => $source['votes_polled'],
                'valid_candidate_votes' => $source['valid_candidate_votes']]
            || count($candidates) !== $source['candidates']) {
            return null;
        }

        $ranked = collect($candidates)->sortByDesc('votes')->values();
        if ($ranked->contains(fn ($candidate): bool => ! is_array($candidate)
            || ! $this->count($candidate['votes'] ?? null)
            || trim($candidate['candidate_name'] ?? '') === ''
            || trim($candidate['party_at_election'] ?? '') === '')
            || $ranked->sum('votes') !== $summary['valid_candidate_votes']
            || $ranked[0]['votes'] <= $ranked[1]['votes']
            || $result !== ['winner' => $ranked[0]['candidate_name'], 'winner_party' => $ranked[0]['party_at_election'],
                'winner_votes' => $ranked[0]['votes'], 'runner' => $ranked[1]['candidate_name'],
                'runner_party' => $ranked[1]['party_at_election'], 'runner_votes' => $ranked[1]['votes'],
                'margin' => $ranked[0]['votes'] - $ranked[1]['votes']]) {
            return null;
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    private function hasDocumentedCandidateDifference(array $record, int $candidateVotes, int $summaryVotes): bool
    {
        $difference = $record['source_discrepancy'] ?? [];

        return ($record['error'] ?? '') === self::WORKBOOK_SUMMARY_CANDIDATE_DIFFERENCE
            && ($difference['field'] ?? '') === 'candidate_total'
            && ($difference['candidate_sum'] ?? null) === $candidateVotes
            && ($difference['summary_value'] ?? null) === $summaryVotes
            && ($difference['difference'] ?? null) === $candidateVotes - $summaryVotes
            && $summaryVotes > 0
            && abs($candidateVotes - $summaryVotes) > 0
            && abs($candidateVotes - $summaryVotes) * 1000 <= $summaryVotes;
    }

    private function hasDocumentedElectorDifference(array $record): bool
    {
        $summary = $record['summary_totals'] ?? [];
        $difference = $record['source_discrepancy'] ?? [];
        if (($record['source_warning_code'] ?? '') === 'official_summary_turnout_only') {
            if (! is_array($summary) || ! is_array($difference)
                || ($record['status'] ?? '') !== 'needs_review'
                || ($difference['field'] ?? '') !== 'electors'
                || ! $this->count($record['electors'] ?? null)
                || ! $this->count($summary['electors'] ?? null)
                || ($difference['detail_value'] ?? null) !== $record['electors']
                || ($difference['summary_value'] ?? null) !== $summary['electors']) {
                return false;
            }

            $delta = abs($record['electors'] - $summary['electors']);

            return $delta > 0 && $delta * 10000 <= $summary['electors'] * 5;
        }

        if (! is_array($summary) || ! is_array($difference)
            || ! in_array($record['status'] ?? '', ['needs_review', 'accepted', 'corrected'], true)
            || ($record['source_warning_code'] ?? '') !== 'summary_elector_difference'
            || ($record['original_extraction_warning'] ?? '') !== self::LEGACY_DETAIL_PENDING
            || ($difference['field'] ?? '') !== 'electors'
            || ! $this->count($record['electors'] ?? null)
            || ! $this->count($summary['electors'] ?? null)
            || ! $this->count($summary['votes_polled'] ?? null)
            || ! $this->count($record['votes_polled'] ?? null)
            || ! $this->count($summary['valid_candidate_votes'] ?? null)
            || $summary['valid_candidate_votes'] !== ($record['valid_candidate_votes'] ?? null)
            || $summary['votes_polled'] !== $record['votes_polled']
            || ($difference['detail_value'] ?? null) !== $record['electors']
            || ($difference['summary_value'] ?? null) !== $summary['electors']) {
            return false;
        }
        $delta = abs($record['electors'] - $summary['electors']);

        return $delta > 0 && $delta * 10000 <= $summary['electors'] * 5;
    }

    private function hasDocumentedPolledDifference(array $record): bool
    {
        $summary = $record['summary_totals'] ?? null;
        $difference = $record['source_discrepancy'] ?? null;
        if (($record['status'] ?? '') !== 'needs_review'
            || ($record['source_warning_code'] ?? '') !== 'official_summary_turnout_only'
            || ! is_array($summary) || ! is_array($difference)
            || ($difference['field'] ?? '') !== 'votes_polled'
            || ! $this->count($record['votes_polled'] ?? null)
            || ! $this->count($summary['votes_polled'] ?? null)
            || ! $this->count($summary['electors'] ?? null)
            || $summary['votes_polled'] < 1 || $summary['votes_polled'] > $summary['electors']
            || ($difference['detail_value'] ?? null) !== $record['votes_polled']
            || ($difference['summary_value'] ?? null) !== $summary['votes_polled']
            || ($difference['valid_detail_value'] ?? null) !== ($record['valid_candidate_votes'] ?? null)
            || ($difference['valid_summary_value'] ?? null) !== ($summary['valid_candidate_votes'] ?? null)) {
            return false;
        }

        $delta = abs($summary['votes_polled'] - $record['votes_polled']);

        return $delta > 0 && $delta * 1000 <= $summary['votes_polled'] * 2;
    }
}
