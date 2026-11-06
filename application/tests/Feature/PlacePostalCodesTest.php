<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSearchController;
use App\Services\PlacePostalCodes;
use Illuminate\Http\Request;
use PDO;
use Tests\TestCase;

class PlacePostalCodesTest extends TestCase
{
    private string $postalPath;

    private PlacePostalCodes $postal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postalPath = tempnam(sys_get_temp_dir(), 'postal-test-');
        $db = new PDO('sqlite:'.$this->postalPath);
        $db->exec('CREATE TABLE places(kind TEXT,code TEXT,state TEXT,district TEXT,subdistrict TEXT,name TEXT,state_name TEXT,district_name TEXT,subdistrict_name TEXT,pin TEXT,raw_pin TEXT,source_row INTEGER,status TEXT); CREATE TABLE metadata(kind TEXT,json TEXT)');
        $insert = $db->prepare('INSERT INTO places VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ([['village', '100', '262001', 'reported'], ['village', '100', '262002', 'reported'], ['village', '100', '262002', 'reported'], ['local-body', '100', '262003', 'reported'], ['village', '101', null, 'missing'], ['village', '102', '123', 'reported']] as $i => [$kind, $code, $pin, $status]) {
            $insert->execute([$kind, $code, '9', '173', '1', '<Example>', 'Uttar Pradesh', 'Pilibhit', 'Example', $pin, $pin ?? '0', $i, $status]);
        }
        foreach (['village', 'local-body'] as $kind) {
            $db->prepare('INSERT INTO metadata VALUES (?,?)')->execute([$kind, json_encode(['source_url' => 'https://www.data.gov.in/', 'updated_at' => '2026-11-01T00:00:00Z', 'retrieved_on' => '2026-11-02'])]);
        }
        $this->postal = new PlacePostalCodes($this->postalPath);
    }

    protected function tearDown(): void
    {
        unlink($this->postalPath);
        parent::tearDown();
    }

    public function test_exact_identity_preserves_multiple_pins_and_separates_urban_codes(): void
    {
        $result = $this->postal->lookup('village', '100', '9', ['district' => '173', 'subdistrict' => '1']);
        $this->assertSame(['262001', '262002'], $result['pins']);
        $this->assertSame('multiple', $result['status']);
        $this->assertSame(['262003'], $this->postal->lookup('local-body', '100', '9')['pins']);
    }

    public function test_parent_or_state_disagreement_withholds_pins(): void
    {
        foreach ([['state' => '8', 'district' => '173'], ['state' => '9', 'district' => '999']] as $identity) {
            $result = $this->postal->lookup('village', '100', $identity['state'], ['district' => $identity['district']]);
            $this->assertSame('identity_mismatch', $result['status']);
            $this->assertSame([], $result['pins']);
        }
        $this->assertSame('identity_mismatch', $this->postal->village(['lgd' => '100', 'state' => '9', 'administration' => ['district_code' => '173', 'subdistrict_code' => '2']])['status']);
    }

    public function test_missing_invalid_and_unknown_pins_are_not_guessed(): void
    {
        foreach (['101', '102', '999'] as $code) {
            $this->assertSame([], $this->postal->lookup('village', $code, '9')['pins']);
        }
        $this->assertSame('unavailable', (new PlacePostalCodes($this->postalPath.'-absent'))->lookup('village', '100')['status']);
    }

    public function test_pin_search_deduplicates_source_rows_and_keeps_entity_kind(): void
    {
        $this->assertSame(1, $this->postal->search('262002')['total']);
        $this->assertSame('local-body', $this->postal->search('262003')['rows'][0]['kind']);
        $this->assertFalse($this->postal->search('26200')['available']);
        $this->assertSame([], $this->postal->search('262002', 2)['rows']);
    }

    public function test_postal_panel_shows_dates_links_and_multiple_pin_explanation(): void
    {
        $html = view('place-postal-codes', ['postal' => $this->postal->lookup('village', '100', '9')])->render();
        $this->assertStringContainsString('262001', $html);
        $this->assertStringContainsString('262002', $html);
        $this->assertStringContainsString('more than one PIN', $html);
        $this->assertStringContainsString('2026-11-01', $html);
        $this->assertStringContainsString('/postal-areas?pin=262001', $html);
    }

    public function test_mismatch_panel_does_not_publish_a_pin(): void
    {
        $html = view('place-postal-codes', ['postal' => $this->postal->lookup('village', '100', '8')])->render();
        $this->assertStringContainsString('disagree on the administrative identity', $html);
        $this->assertStringNotContainsString('262001', $html);
    }

    public function test_main_search_redirects_a_pin_to_all_linked_areas(): void
    {
        $request = Request::create('/search', 'GET', ['q' => ' 262001 ']);
        $response = app(PublicSearchController::class)->index($request);
        $this->assertSame(route('postal-areas.index', ['pin' => '262001']), $response->getTargetUrl());
    }

    public function test_header_pin_suggestions_include_all_results_and_area_links(): void
    {
        $this->app->instance(PlacePostalCodes::class, $this->postal);
        $request = Request::create('/search', 'GET', ['q' => '262003'], server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(PublicSearchController::class)->index($request);
        $rows = $response->getData(true)['suggestions'];
        $this->assertSame('PIN 262003', $rows[0]['label']);
        $this->assertSame('1 linked areas · view all', $rows[0]['period']);
        $this->assertSame(route('postal-areas.show', ['kind' => 'local-body', 'code' => '100']), $rows[1]['url']);
        $this->assertSame([], $this->postal->suggestions('26200'));
        $this->assertCount(1, $this->postal->suggestions('999999'));
    }
}
