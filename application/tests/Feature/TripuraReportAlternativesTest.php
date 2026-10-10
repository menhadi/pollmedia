<?php

namespace Tests\Feature;

use App\Http\Controllers\ConstituencyOverviewController;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TripuraReportAlternativesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_east_and_west_report_choices_restore_shares_without_modifying_records(): void
    {
        $editions = ['2e749f2174f08a9ea1fc803d' => '2019 Including Vellore', '70e603b1037bf7ca8e1350b0' => '2019 Excluding Vellore'];
        $datasets = [];
        foreach ($editions as $edition => $label) {
            $datasets[$edition] = json_decode(file_get_contents(database_path('fixtures/tripura-2019-report-alternatives.json')), true, 512, JSON_THROW_ON_ERROR)[$edition];
            foreach (collect($datasets[$edition]['records'])->where('state_code', 'S23') as $raw) {
                DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $raw['code'], 'kind' => 'pc', 'year' => 2019, 'edition_label' => $label, 'state_label' => 'Tripura', 'constituency_name' => $raw['constituency_name'], 'status' => 'validated', 'has_warning' => false, 'candidate_count' => count($raw['candidates']), 'extraction_sha256' => str_repeat('a', 64)]);
            }
        }
        $archive = $this->mock(HistoricalElectionArchive::class);
        $archive->shouldReceive('load')->andReturnUsing(function ($edition) use (&$datasets) {
            return [$datasets[$edition]];
        });
        foreach (['Tripura East' => 482126, 'Tripura West' => 573532] as $name => $votes) {
            foreach ($editions as $edition => $label) {
                $request = Request::create('/india/constituency', 'GET', ['kind' => 'pc', 'state' => 'Tripura', 'name' => $name, 'edition' => $edition]);
                $view = app(ConstituencyOverviewController::class)->index($request, $archive, app(ElectionArchive::class), app(HistoricalElectionReview::class), app(HistoricalElectionAnalytics::class));
                $data = $view->getData();
                $this->assertCount(2, $data['rows']);
                $this->assertCount(2, $data['reportAlternatives']);
                $this->assertCount(1, $data['chartSourceRows']);
                $this->assertSame($edition, $data['chartReport']['entry']->edition_id);
                $html = view('place-history-charts', $data)->render();
                $this->assertStringContainsString('CPIM and CPM', $html);
                foreach ($datasets as $source) {
                    $this->assertStringContainsString($source['source_url'], $html);
                }
                preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $html, $matches);
                $party = json_decode($matches[1][1], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($votes, $party['rows'][0]['party0']);
                $this->assertNotNull($party['rows'][0]['party0_share']);
                $this->assertStringContainsString('2019 report comparison:', view('place-history-charts', $data + ['reportMode' => true])->render());
                $code = $data['chosen']['entry']->record_code;
                $parameters = ['kind' => 'pc', 'state' => 'Tripura', 'name' => $name, 'edition' => $edition, 'code' => $code];
                $overview = $this->get(route('constituency.overview', $parameters))->assertOk();
                $printUrl = route('constituency.overview', ['kind' => 'pc', 'state' => 'Tripura', 'name' => $name, 'format' => 'report', 'edition' => $edition, 'code' => $code]);
                $overview->assertSee($printUrl);
                $printed = $this->get($printUrl)->assertOk();
                foreach ([$overview, $printed] as $response) {
                    $response->assertSee('Report alternative: 2019 Including Vellore')
                        ->assertSee('Report alternative: 2019 Excluding Vellore')
                        ->assertSee('Charts show '.$label)
                        ->assertSee('CPIM and CPM');
                    foreach ($datasets as $source) {
                        $response->assertSee($source['source_url']);
                    }
                }
                preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $overview->getContent(), $publicCharts);
                $fixed = json_decode($publicCharts[1][0], true, 512, JSON_THROW_ON_ERROR);
                $expectedParty = $edition === '2e749f2174f08a9ea1fc803d' ? 'CPIM' : 'CPM';
                $this->assertContains($expectedParty, array_column($fixed['series'], 'label'));
                $this->assertNotContains($expectedParty === 'CPIM' ? 'CPM' : 'CPIM', array_column($fixed['series'], 'label'));
                $this->assertSame($votes, json_decode($publicCharts[1][1], true)['rows'][0]['party0']);
                $printed->assertSee($expectedParty)->assertSee('<svg', false);
            }
        }
        $revision = '70e603b1037bf7ca8e1350b0';
        $original = $datasets[$revision];
        foreach (['round', 'error', 'candidate_votes', 'other_party'] as $field) {
            foreach ($datasets[$revision]['records'] as &$record) {
                if ($field === 'candidate_votes') {
                    $record['candidates'][0]['votes']++;
                } elseif ($field === 'other_party') {
                    $record['candidates'][0]['party_at_election'] = 'Distinct party';
                } else {
                    $record[$field] = 'Distinct source information';
                }
            }
            unset($record);
            $request = Request::create('/india/constituency', 'GET', ['kind' => 'pc', 'state' => 'Tripura', 'name' => 'Tripura East']);
            $view = app(ConstituencyOverviewController::class)->index($request, $archive, app(ElectionArchive::class), app(HistoricalElectionReview::class), app(HistoricalElectionAnalytics::class));
            $this->assertCount(0, $view->getData()['reportAlternatives'], $field);
            $this->assertCount(2, $view->getData()['chartSourceRows'], $field);
            $datasets[$revision] = $original;
        }
    }
}
