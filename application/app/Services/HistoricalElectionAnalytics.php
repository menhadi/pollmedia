<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HistoricalElectionAnalytics
{
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
            $key = 'election-analysis-v12:'.hash('sha256', $body.$state.$kind.$reviewVersion);
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

                return $this->summarize($records) + ['review_count' => collect($records)->where('has_warning', true)->count(), 'id' => $id, 'year' => $data['year'], 'label' => $label, 'source_url' => $url, 'state' => $sourceState];
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
            $duplicateCandidateTurnout = $this->hasSummaryTurnoutWithDuplicateCandidates($record);
            $turnoutElectors = $electorDifference ? $record['summary_totals']['electors'] : ($record['electors'] ?? null);
            $turnoutPolled = $polledDifference ? $record['summary_totals']['votes_polled'] : ($record['votes_polled'] ?? null);
            if ($this->count($turnoutElectors) && $turnoutElectors > 0 && $this->count($turnoutPolled) && $turnoutPolled <= $turnoutElectors
                && (! $hasWarning || $this->hasCorroboratedTurnout($record) || $provisionalTurnout || $detailTurnoutWithTextWarning || $sourceDifference || $workbookSummaryTurnout || $documentedTurnout || $duplicateCandidateTurnout)) {
                $electors += $turnoutElectors;
                $polled += $turnoutPolled;
                $turnoutCount++;
                if ($hasWarning) {
                    $turnoutReviewCount++;
                    if ($provisionalTurnout || $detailTurnoutWithTextWarning) {
                        $turnoutDetailCount++;
                    }
                    if ($electorDifference || $polledDifference || $sourceDifference) {
                        $turnoutDiscrepancyCount++;
                    }
                }
            }
            if ($hasWarning && ! $provisionalCandidates) {
                $workbookResult = $this->officialRepeatedNameWorkbookResult($record)
                    ?? $this->officialWorkbookSummaryResult($record)
                    ?? $this->officialPdfSummaryResult($record)
                    ?? $this->officialInvalidTurnoutResult($record);
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
                $name = trim($ranked[0]['candidate_name'] ?? '');
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
        if (($record['number_of_seats'] ?? 1) !== 1) {
            return null;
        }

        $uncontested = $this->uncontestedResult($record, $edition);
        if ($uncontested !== null) {
            return $uncontested;
        }

        $officialResult = $this->officialRepeatedNameWorkbookResult($record)
            ?? $this->officialPdfSummaryResult($record)
            ?? $this->officialInvalidTurnoutResult($record);
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

    private function hasProvisionalCandidateVotes(array $record, bool $includeReviewed = false): bool
    {
        if (($record['status'] ?? '') !== 'needs_review' && (! $includeReviewed || ! in_array($record['status'] ?? '', ['validated', 'accepted', 'corrected'], true))) {
            return false;
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

        if (($record['source_warning_code'] ?? '') === 'official_summary_turnout_only') {
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
        if ($winnerRow === null || $runnerRow === null || $marginRow === null
            || ! in_array($record['winner'], $winnerRow, true)) {
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
        if (($record['source_warning_code'] ?? '') !== 'official_summary_turnout_only'
            || ! $this->hasCorroboratedTurnout($record)
            || ($record['number_of_seats'] ?? 1) !== 1) {
            return null;
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
            || $result['winner_votes'] > ($record['summary_totals']['valid_candidate_votes'] ?? 0)) {
            return null;
        }

        return ['winner' => $result['winner'], 'party' => $result['winner_party'], 'margin' => $result['margin']];
    }

    /** The official declaration can establish a result even when its voter total cannot establish turnout. */
    private function officialInvalidTurnoutResult(array $record): ?array
    {
        $summary = $record['summary_totals'] ?? null;
        $result = $record['summary_result'] ?? null;
        $candidates = $record['candidates'] ?? null;
        if (($record['source_warning_code'] ?? null) !== 'official_ac_declared_result_invalid_turnout'
            || ($record['status'] ?? null) !== 'needs_review'
            || ($record['code'] ?? null) !== 315 || ($record['name'] ?? null) !== 'CHHIBRAMAU'
            || ($record['number_of_seats'] ?? null) !== 1
            || ($record['original_extraction_warning'] ?? null) !== 'Electorate and voter totals are inconsistent'
            || ($record['error'] ?? null) !== 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.'
            || ($record['official_summary_state'] ?? null) !== 'Uttar Pradesh'
            || ($record['official_source_url'] ?? null) !== 'https://old.eci.gov.in/files/file/3247-uttar-pradesh-1969/'
            || ($record['summary_source_file'] ?? null) !== '7ce40cf47befc2b48ff776e3-7475.pdf'
            || ($record['summary_source_sha256'] ?? null) !== '6149dddc726ecba33c63091f8452964cef9fd012248ba2ab313ecce77cb2c623'
            || ($record['summary_page'] ?? null) !== 337 || ($record['detail_page'] ?? null) !== 505
            || ! is_array($summary) || ! is_array($result) || ! is_array($candidates)
            || ($record['electors'] ?? null) !== 73524 || ($record['votes_polled'] ?? null) !== 80269
            || ($record['valid_candidate_votes'] ?? null) !== 78013
            || $summary !== ['electors' => 73524, 'votes_polled' => 80269, 'valid_candidate_votes' => 78013]
            || count($candidates) !== 11) {
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
