<?php

namespace Tests\Feature;

use App\Services\ByElectionRecords;
use App\Services\ElectionArchive;
use App\Services\ElectoralMapResults;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use App\Services\StateTablePreview;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StateElectionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_lok_sabha_links_redirect_to_the_same_seat_in_the_final_edition(): void
    {
        $old = ElectionArchive::PC_2019_SUPERSEDED;
        $final = ElectionArchive::PC_2019_FINAL;
        foreach ([1 => 1, 327 => 327, 328 => 329, 542 => 543] as $before => $after) {
            $path = '/india/elections/lok-sabha?edition=';
            $this->get($path.$old.'&code='.$before.'&format=report&state=Tamil%20Nadu')
                ->assertRedirect($path.$final.'&code='.$after.'&format=report&state=Tamil%20Nadu')->assertStatus(301);
        }
        $this->get('/india/state/tamil-nadu?pc_edition='.$old.'&lang=hi')
            ->assertRedirect('/india/state/tamil-nadu?pc_edition='.$final.'&lang=hi');
        $this->get('/india/elections/lok-sabha?edition='.$old.'&code=543')->assertNotFound();
        $catalogue = app(ElectionArchive::class);
        $ids = fn ($data) => collect($data['pc'])->map(fn ($row) => substr(hash('sha256', $row[1]), 0, 24));
        $this->assertTrue($ids($catalogue->catalogue())->contains($old));
        $this->assertFalse($ids($catalogue->publicCatalogue())->contains($old));
        $this->assertTrue($ids($catalogue->publicCatalogue())->contains($final));
    }

    public function test_national_aliases_preserve_search_and_language(): void
    {
        $this->get('/india/elections?year=2019&lang=hi')->assertRedirect(route('elections.all-india', ['year' => 2019, 'lang' => 'hi']))->assertStatus(301);
        $this->get('/india?lang=hi')->assertRedirect(route('home', ['lang' => 'hi']))->assertStatus(301);
    }

    public function test_sections_keep_histories_and_year_selections_separate(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $summary = function ($year, $id, $name, $electors, $polled) use ($analytics) {
            return $analytics->summarize([['code' => 1, 'status' => 'validated', 'has_warning' => false, 'constituency_name' => $name, 'number_of_seats' => 1, 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => [['candidate_name' => $name.' winner', 'party_at_election' => 'A', 'votes' => (int) ($polled * 0.75)], ['candidate_name' => 'Runner up', 'party_at_election' => 'B', 'votes' => (int) ($polled * 0.25)]]]]) + ['id' => str_repeat($id, 24), 'year' => $year, 'label' => $year.' source report', 'state' => 'Uttar Pradesh', 'source_url' => 'https://www.eci.gov.in/'.$id, 'review_count' => 0, 'constituency_results' => [['code' => 1, 'name' => $name, 'result' => ['winner' => $name.' winner', 'party' => 'A', 'margin' => (int) ($polled * .5)], 'source_candidate' => null, 'has_warning' => false, 'note' => null]]];
        };
        $pc = [$summary(2024, 'a', 'Parliament Seat', 1000, 800), $summary(2019, 'b', 'Old Parliament Seat', 1000, 600)];
        $ac = [$summary(2022, 'c', 'Assembly Seat', 500, 400)];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($pc, $ac) {
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'pc')->andReturn($pc);
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'ac')->andReturn($ac);
        });
        $page = $this->get('/india/state/uttar-pradesh')->assertOk()
            ->assertSeeInOrder(['id="pc-results"', 'Seats won by party', 'Vote share by party', 'id="margin-summary-title"', 'class="state-atlas-map"', 'data-kind="pc"', 'Parliament Seat winner', 'id="pc-history"'], false)
            ->assertDontSee('id="ac-results"', false)->assertSee('Official report');
        $page->assertSee('data-map-party', false)->assertSee('data-map-query', false)
            ->assertSee('Explore Census')->assertDontSee('id="politics"', false)->assertDontSee('id="place-facts"', false)
            ->assertDontSee('id="census-places"', false)->assertDontSee('data-map-village-link', false);
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//main//a[contains(@class,"state-census-button")][following-sibling::*[1][self::details and @id="state-methodology" and not(@open)]]')->length);
        $this->assertSame(1, $xpath->query('//main//details//table')->length);
        $this->assertSame(1, $xpath->query('//details[@id="state-methodology" and not(@open)]//details[not(@open)]//table')->length);
        $this->assertSame(1, $xpath->query('//details[contains(@class,"result-coverage") and not(@open)]')->length);
        $this->assertSame(2, $xpath->query('//main//table[contains(@class,"atlas-party-history") and thead/tr/th="Party" and thead/tr/th="Votes" and thead/tr/th="Share (%)"]')->length);
        $this->assertSame(2, $xpath->query('//main//*[@data-party-list or @data-seat-list]')->length);
        $assembly = $this->get('/india/state/uttar-pradesh?election=ac')->assertOk()
            ->assertSee('data-kind="ac"', false)->assertSee('Assembly Seat winner')->assertSee('id="ac-history"', false)->assertDontSee('id="pc-results"', false);
        foreach (['pc' => 'Lok Sabha', 'ac' => 'State Assembly · AC'] as $reportKind => $reportLabel) {
            $report = $this->get('/india/state/uttar-pradesh?format=report&election='.$reportKind)->assertOk()->assertSee($reportLabel)->assertSee('Print / Save as PDF')->assertSee('Sources and coverage by election year')->assertSee('Methodology & disclaimer', false)->assertDontSee('<select', false)->assertDontSee('<details', false)->assertDontSee('data-history-chart');
            $this->assertSame(4, substr_count($report->getContent(), 'class="report-plot"'));
            $report->assertSeeInOrder(['Chart values', 'Sources and coverage by election year', 'Methodology & disclaimer'], false);
        }
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $page->getContent(), $charts);
        $this->assertCount(4, $charts[1]);
        $this->assertSame([2019, 2024], array_column(json_decode($charts[1][0], true)['rows'], 'year'));
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $assembly->getContent(), $assemblyCharts);
        $this->assertSame([2022], array_column(json_decode($assemblyCharts[1][0], true)['rows'], 'year'));
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('b', 24).'&ac_edition='.str_repeat('c', 24))->assertOk()->assertSee('2019 Lok Sabha (PC) results')->assertDontSee('2022 Assembly (AC) results')->assertSee('data-year="2019"', false)->assertDontSee('data-year="2022"', false)->assertSee('edition='.str_repeat('b', 24), false);
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('c', 24))->assertNotFound();
        $this->get('/india/state/uttar-pradesh?election=ac&edition='.str_repeat('c', 24))->assertOk()->assertSee('2022 Assembly (AC) results');
    }

    public function test_margin_table_keeps_zero_and_tied_seats_and_excludes_unavailable_or_uncontested_results(): void
    {
        $rows = collect([['North', 0], ['South', 0], ['East', 5], ['West', 12], ['Missing', null], ['Unopposed', 99]])
            ->map(fn ($pair, $code) => ['name' => $pair[0], 'code' => $code + 1, 'result' => ['margin' => $pair[1], 'winner' => $pair[0].' winner', 'party' => 'Example party', 'uncontested' => $pair[0] === 'Unopposed']]);
        $data = ['seatRows' => $rows, 'election' => ['label' => '2024', 'tables' => 6], 'title' => 'Gujarat',
            'sectionLabel' => 'Lok Sabha (PC)', 'kind' => 'pc', 'edition' => str_repeat('a', 24)];
        $html = view('state-winning-margins', $data)->render();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $this->assertStringContainsString('4 of 6 constituencies', $doc->documentElement->textContent);
        $this->assertStringNotContainsString('>Mode<', $html);
        $cells = fn ($n) => $doc->querySelector('tbody tr:nth-child('.$n.') td')->textContent;
        $this->assertSame(['12', '0', '0', '3', '4'], array_map($cells, range(1, 5)));
        $this->assertSame(['West', 'North', 'South'], array_map(fn ($a) => $a->textContent, iterator_to_array($doc->querySelectorAll('tbody a'))));
        $this->assertSame('South winner', $doc->querySelector('tbody tr:nth-child(3) td:nth-child(4)')->textContent);
        $this->assertSame('Example party', $doc->querySelector('tbody tr:nth-child(3) td:nth-child(5)')->textContent);
        $other = ['id' => str_repeat('b', 24), 'label' => '2019 edition', 'constituency_results' => $rows->slice(4)->all(), 'tables' => 2];
        $history = view('state-margin-history', ['state' => 'gujarat', 'kind' => 'pc', 'history' => [
            $data['election'] + ['id' => $data['edition'], 'constituency_results' => $rows->all()], $other,
        ]])->render();
        $historical = HTMLDocument::createFromString($history, LIBXML_NOERROR, 'UTF-8');
        $values = fn ($n) => array_map(fn ($c) => trim($c->textContent), iterator_to_array($historical->querySelectorAll('tbody tr:nth-child('.$n.') td')));
        $this->assertSame(['4 / 6', '12', '0', '3', '4'], $values(1));
        $this->assertSame(['0 / 2', '—', '—', '—', '—'], $values(2));
        $this->assertStringContainsString('pc_edition='.$other['id'].'#margin-summary-title', $historical->querySelector('tbody tr:nth-child(2) a')->getAttribute('href'));
        $data['seatRows'] = $rows->slice(4);
        $empty = view('state-winning-margins', $data)->render();
        $this->assertStringContainsString('0 of 6 constituencies', $empty);
        $this->assertStringNotContainsString('<table>', $empty);
    }

    public function test_long_state_tables_send_twenty_rows_then_return_the_requested_table(): void
    {
        $url = 'https://localhost/india/state/uttar-pradesh?election=ac';
        $rows = '';
        foreach (range(1, 27) as $index) {
            $rows .= "<tr><th>Seat {$index}</th><td>{$index}</td></tr>";
        }
        $html = "<!doctype html><html><body><main><table><thead><tr><th>Name</th><th>Votes</th></tr></thead><tbody>{$rows}</tbody></table></main></body></html>";
        $service = app(StateTablePreview::class);
        $initial = HTMLDocument::createFromString($service->render($html, Request::create($url)));
        $this->assertCount(20, $initial->querySelectorAll('tbody tr'));
        $this->assertNull($initial->querySelector('th[data-number]:first-child'));
        $this->assertCount(20, $initial->querySelectorAll('td[data-number]'));
        $link = $initial->querySelector('[data-rows-url]');
        $this->assertStringNotContainsString('Seat 27', $initial->saveHtml());
        $reply = $service->render($html, Request::create($link->getAttribute('data-rows-url')))->getData(true);
        $this->assertSame(27, $reply['count']);
        $this->assertStringContainsString('Seat 27', $reply['bodies'][0]);
        $this->assertSame([1], $reply['numericColumns']);
        $full = HTMLDocument::createFromString($service->render($html, Request::create($link->getAttribute('href'))));
        $this->assertCount(27, $full->querySelectorAll('tbody tr'));
        $this->assertNull($full->querySelector('[data-rows-url]'));
        $this->assertSame(404, $service->render($html, Request::create($url.'&table_rows=missing'))->getStatusCode());
    }

    public function test_current_directory_excludes_former_names_and_keeps_election_types_separate(): void
    {
        foreach ([['pc', 2024, 'a', 'Present PC'], ['pc', 2019, 'b', 'Former PC'], ['ac', 2022, 'c', 'Present AC'], ['ac', 2017, 'd', 'Former AC']] as [$kind,$year,$id,$name]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => 1, 'kind' => $kind, 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Maharashtra', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('e', 64)]);
        }
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) {
            $mock->shouldReceive('forState')->with('Maharashtra', 'pc')->andReturn([]);
            $mock->shouldReceive('forState')->with('Maharashtra', 'ac')->andReturn([]);
        });
        $this->get('/india/state/maharashtra')->assertOk()->assertSee('Present Pc')->assertDontSee('Present Ac')->assertDontSee('Former Pc')->assertDontSee('Former Ac')->assertSee('data-fragment-form', false);
        $this->get('/india/state/maharashtra?pc_scope=archive')->assertOk()->assertSee('Former Pc')->assertDontSee('Present Ac')->assertDontSee('Present Pc')->assertDontSee('Former Ac');
        $this->get('/india/state/maharashtra?election=ac&ac_scope=archive')->assertOk()->assertSee('Former Ac')->assertDontSee('Present Pc')->assertDontSee('Present Ac');
        $this->getJson('/india/state/maharashtra?suggest=pc&pc_q=Present')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.label', 'Present Pc')->assertJsonPath('suggestions.0.type', 'PC');
        $this->getJson('/india/state/maharashtra?suggest=ac&ac_scope=archive&ac_q=Former')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.label', 'Former Ac')->assertJsonPath('suggestions.0.type', 'Archive · AC');
        $this->get('/india/state/maharashtra?pc_q=missing')->assertOk()->assertDontSee('Present Pc')->assertDontSee('Present Ac');
    }

    public function test_national_election_table_combines_records_and_pages_without_repeating_a_source_row(): void
    {
        $archive = $this->mock(HistoricalElectionArchive::class);
        foreach (['pc' => 'a', 'ac' => 'b'] as $kind => $letter) {
            $edition = str_repeat($letter, 24);
            $archive->shouldReceive('editions')->with($kind)->andReturn([['id' => $edition]]);
            foreach (range(1, 22) as $code) {
                DB::table('historical_constituency_index')->insert([
                    'edition_id' => $edition, 'record_code' => $code, 'kind' => $kind,
                    'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Gujarat',
                    'constituency_name' => $kind.' seat '.$code, 'status' => 'validated',
                    'has_warning' => false, 'candidate_count' => 4, 'extraction_sha256' => str_repeat('f', 64),
                ]);
            }
        }
        $this->mock(ElectoralMapResults::class)->shouldReceive('indexedRecords')->andReturnUsing(
            fn ($kind, $entries) => $entries->map(fn ($r) => [
                'id' => $r->edition_id.':'.$r->record_code, 'kind' => $kind, 'state' => $r->state_label,
                'name' => $r->constituency_name, 'winner' => null, 'party' => null, 'year' => $r->year,
                'url' => url('/test/'.$kind.'/'.$r->record_code),
            ])->all()
        );
        $by = $this->mock(ByElectionRecords::class);
        $by->shouldReceive('index')->andReturn(collect([[
            'id' => str_repeat('c', 24), 'kind' => 'ac', 'year' => null, 'state' => 'Gujarat',
            'constituency' => 'Special seat', 'status' => 'needs_review', 'candidate_count' => 2,
        ]]));
        $by->shouldReceive('record')->andReturn(['candidates' => [['name' => 'Unconfirmed candidate', 'votes' => 100]]]);
        $url = '/india/elections/all-india';
        $first = $this->get($url)->assertOk()->assertSee('45 constituency records')->assertSee('Records by election type')->assertSee('State election results')
            ->assertSee('Lok Sabha (PC)')->assertSee('Assembly (AC)')->assertSee('Special seat')->assertDontSee('Unconfirmed candidate');
        $links = function (string $html): array {
            $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

            return array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($doc->querySelectorAll('tbody th a')));
        };
        $seen = $links($first->getContent());
        $this->assertCount(20, $seen);
        foreach ([2 => 20, 3 => 5] as $page => $expected) {
            $response = $this->getJson($url.'?rows_only=1&page='.$page)->assertOk()->assertJsonPath('total', 45);
            $more = $links('<table><tbody>'.$response->json('rows').'</tbody></table>');
            $this->assertCount($expected, $more);
            $this->assertSame([], array_intersect($seen, $more));
            $seen = array_merge($seen, $more);
        }
        $this->assertCount(45, array_unique($seen));
        $this->get($url.'?type=by&year=0&state=Gujarat&q=Special')->assertOk()->assertSee('Special seat')->assertDontSee('pc seat');
        $this->get($url.'?q=%25')->assertOk()->assertSee('No constituency records match');
    }
}
