<?php

namespace Tests\Feature;

use App\Services\OfficialMarketCatalog;
use App\Services\OfficialMarketPrices;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfficialMarketHistoryTest extends TestCase
{
    private array $context = ['state' => ['code' => '9', 'name' => 'Uttar Pradesh'], 'district' => ['code' => '173', 'name' => 'Pilibhit']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Kolkata')->setDate(2026, 10, 27));
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key']);
    }

    private function catalog(): array
    {
        return ['status' => 'success', 'data' => [
            ['id' => 1, 'cmdt_name' => 'Wheat', 'comm_code' => 1, 'price_unit_name' => 'Quintal', 'languages' => [['language' => 2, 'multi_lingual_cname' => 'गेहूँ']]],
            ['id' => 2, 'cmdt_name' => 'Egg', 'comm_code' => 367, 'price_unit_name' => 'Nos'],
            ['id' => 3, 'cmdt_name' => 'Mint(Pudina)', 'comm_code' => 360, 'price_unit_name' => 'Bundle'],
            ['id' => 4, 'cmdt_name' => 'Green Tea', 'comm_code' => 2029, 'price_unit_name' => 'Kilogram'],
        ]];
    }

    private function row(string $date, float $modal, array $extra = []): array
    {
        return array_replace(['State' => 'Uttar Pradesh', 'District' => 'Pillibhit', 'Market' => 'Pilibhit APMC', 'Commodity' => 'Wheat', 'Commodity_Code' => 1, 'Variety' => 'Dara', 'Grade' => 'FAQ', 'Arrival_Date' => $date, 'Min_Price' => 1, 'Modal_Price' => $modal, 'Max_Price' => 9999], $extra);
    }

    public function test_official_units_cover_multiple_denominations_without_assuming_quintals(): void
    {
        Http::fake(['api.agmarknet.gov.in/*' => Http::response($this->catalog())]);
        $catalog = app(OfficialMarketCatalog::class);
        $records = $catalog->decorate([['commodity' => 'Wheat', 'commodity_code' => 1], ['commodity' => 'Egg'], ['commodity' => 'Mint(Pudina)'], ['commodity' => 'Green Tea'], ['commodity' => 'Unknown', 'unit' => null], ['commodity' => 'Wheat', 'commodity_code' => 99, 'unit' => null]]);
        $this->assertSame(['Quintal', 'Nos', 'Bundle', 'Kilogram', null, null], array_column($records, 'unit'));
        $this->assertSame('गेहूँ', $records[0]['commodity_hi']);
    }

    public function test_monthly_series_preserves_gaps_and_compares_the_same_calendar_days(): void
    {
        $rows = [$this->row('21/10/2026', 2600), $this->row('20/10/2026', 2400), $this->row('21/10/2025', 2000), $this->row('30/10/2025', 4000), $this->row('01/09/2026', 2200), $this->row('21/10/2026', 9999, ['District' => 'Other']), $this->row('22/10/2026', 9999, ['Grade' => 'Non-FAQ'])];
        Http::fake(['api.agmarknet.gov.in/*' => Http::response($this->catalog()), 'api.data.gov.in/*' => Http::response(['records' => $rows, 'total' => count($rows)])]);
        $result = app(OfficialMarketPrices::class)->history($this->context, 'Wheat', 'Dara', 'FAQ');
        $this->assertNull($result['reason']);
        $this->assertCount(12, $result['months']);
        $this->assertNull($result['months'][0]['value']);
        $this->assertSame(2500.0, $result['comparison']['current']['value']);
        $this->assertSame(2000.0, $result['comparison']['previous']['value']);
        $this->assertSame(3000.0, $result['comparison']['previous_full_month']['value']);
        $this->assertSame(25.0, $result['comparison']['change_percent']);
        $this->assertSame('Quintal', $result['unit']);
    }

    public function test_history_paginates_without_turning_missing_comparison_into_zero(): void
    {
        $rows = array_fill(0, 1000, $this->row('21/10/2026', 2600));
        Http::fake(['api.agmarknet.gov.in/*' => Http::response($this->catalog()), 'api.data.gov.in/*' => function ($request) use ($rows) {
            return Http::response(['records' => $request['offset'] === 0 ? $rows : [$this->row('01/09/2025', 2000)], 'total' => 1001]);
        }]);
        $result = app(OfficialMarketPrices::class)->history($this->context, 'Wheat', 'Dara', 'FAQ');
        $this->assertSame(1, $result['comparison']['current']['observations']);
        $this->assertNull($result['comparison']['previous']['value']);
        $this->assertNull($result['comparison']['change_percent']);
        Http::assertSent(fn ($request) => ($request['offset'] ?? null) === 1000);
    }

    public function test_every_commodity_is_browsable_and_source_pages_do_not_silently_stop_at_100(): void
    {
        config(['my_place.agmarknet.resource_id' => '9ef84268-d588-465a-a308-a864a43d0070']);
        Http::fake(['api.agmarknet.gov.in/*' => Http::response($this->catalog()), 'api.data.gov.in/*' => Http::response(['records' => [$this->row('21/10/2026', 2600)], 'total' => 2000])]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context, 'Wheat', 1000);
        $this->assertCount(4, $result['catalog']);
        $this->assertSame(1001, $result['next_offset']);
        $this->assertSame('Quintal', $result['records'][0]['unit']);
        Http::assertSent(fn ($request) => ($request['offset'] ?? null) === 1000 && ($request['filters[commodity]'] ?? null) === 'Wheat');
    }

    public function test_chhattisgarh_history_accepts_only_the_official_source_spelling_and_matching_district(): void
    {
        $context = ['state' => ['code' => '22', 'name' => 'Chhattisgarh'], 'district' => ['code' => '646', 'name' => 'Balod']];
        $row = $this->row('21/10/2026', 2403, ['State' => 'Chattisgarh', 'District' => 'Balod']);
        Http::fake(['api.agmarknet.gov.in/*' => Http::response($this->catalog()), 'api.data.gov.in/*' => Http::response(['records' => [$row, array_replace($row, ['District' => 'Durg'])], 'total' => 2])]);
        $result = app(OfficialMarketPrices::class)->history($context, 'Wheat', 'Dara', 'FAQ');
        $this->assertNull($result['reason']);
        $this->assertSame(2403.0, $result['comparison']['current']['value']);
        $this->assertSame(1, $result['comparison']['current']['observations']);
        Http::assertSent(fn ($request) => ($request['filters[State]'] ?? null) === 'Chattisgarh' && ($request['filters[District]'] ?? null) === 'Balod');
    }
}
