<?php

namespace Tests\Feature;

use App\Services\OfficialMarketCatalog;
use App\Services\OfficialMarketPrices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfficialMarketPricesTest extends TestCase
{
    private array $context = ['state' => ['code' => '9', 'name' => 'Uttar Pradesh'], 'district' => ['code' => '173', 'name' => 'Pilibhit']];

    public function test_without_authorised_configuration_no_prices_are_fetched_or_invented(): void
    {
        Http::preventStrayRequests();
        config(['my_place.agmarknet.api_key' => null]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertSame('not_configured', $result['reason']);
        $this->assertSame([], $result['records']);
        Http::assertNothingSent();
    }

    public function test_only_exact_source_geography_dated_ordered_prices_are_retained_without_inventing_units(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        $row = ['state' => 'Uttar Pradesh', 'district' => 'Pilibhit', 'market' => 'Pilibhit mandi', 'commodity' => 'Wheat', 'arrival_date' => '26/10/2026', 'min_price' => '100', 'modal_price' => '150', 'max_price' => '200'];
        $rows = [$row, array_replace($row, ['state' => 'Maharashtra']), array_replace($row, ['district' => 'Sitapur']), array_replace($row, ['arrival_date' => '31/02/2026']), array_replace($row, ['modal_price' => 0]), array_replace($row, ['modal_price' => 999]), array_replace($row, ['arrival_date' => '28/10/2026'])];
        $result = app(OfficialMarketPrices::class)->verifiedRows($rows, $this->context);
        $this->assertCount(1, $result);
        $this->assertSame('2026-10-26', $result[0]['date']);
        $this->assertNull($result[0]['unit']);
        $this->assertSame(150.0, $result[0]['modal']);
    }

    public function test_source_failure_is_unavailable_and_does_not_expose_the_api_key(): void
    {
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174000']);
        Http::fake(['api.data.gov.in/*' => Http::response([], 503)]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertSame('source_unavailable', $result['reason']);
        $this->assertStringNotContainsString('private-test-key', json_encode($result));
    }

    public function test_connected_empty_feed_keeps_coverage_and_update_dates_distinct_from_failure(): void
    {
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174001']);
        Http::fake(['api.data.gov.in/*' => Http::response(['records' => [], 'updated_date' => '2026-10-27T17:00:45Z'], 200)]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertSame('no_matching_records', $result['reason']);
        $this->assertSame([], $result['records']);
        $this->assertSame('2026-10-27T17:00:45Z', $result['source_updated_at']);
        $this->assertNotNull($result['retrieved_at']);
        $this->assertStringNotContainsString('private-test-key', json_encode($result));
        Http::assertSent(fn ($request) => ($request['filters[state]'] ?? null) === 'Uttar Pradesh' && ($request['filters[district]'] ?? null) === 'Pilibhit');
    }

    public function test_historical_feed_returns_latest_observation_per_market_and_preserves_older_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174002']);
        $row = ['State' => 'Uttar Pradesh', 'District' => 'Pillibhit', 'Market' => 'Pilibhit APMC', 'Commodity' => 'Paddy(Common)', 'Arrival_Date' => '21/10/2026', 'Min_Price' => 1850, 'Modal_Price' => 1868.32, 'Max_Price' => 2100];
        Http::fake(['*/123e4567-*' => Http::response(['records' => []]), '*/35985678-*' => Http::response(['records' => [$row, array_replace($row, ['Arrival_Date' => '20/10/2026']), array_replace($row, ['District' => 'Bareilly'])], 'updated_date' => '2026-10-26T23:35:12Z'])]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertCount(1, $result['records']);
        $this->assertSame(1868.32, $result['records'][0]['modal']);
        $this->assertSame('2026-10-21', $result['latest_reported_date']);
        $this->assertSame('historical', $result['source']);
        Http::assertSent(fn ($request) => ($request['filters[District]'] ?? null) === 'Pillibhit' && ($request['sort[Arrival_Date]'] ?? null) === 'desc');
        $other = $this->context;
        $other['district'] = ['code' => '174', 'name' => 'Other district'];
        $this->assertSame([], app(OfficialMarketPrices::class)->forPlace($other)['records']);
    }

    public function test_previous_verified_reports_survive_an_upstream_outage_with_an_explicit_flag(): void
    {
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174003']);
        $scope = hash('sha256', json_encode(['9', '173', null, 0]));
        Cache::put('my-place-last-prices:v4:'.$scope, ['records' => [['date' => '2026-10-21', 'modal' => 1868.32]], 'retrieved_at' => '2026-10-27T18:00:00Z']);
        Http::fake(['api.data.gov.in/*' => Http::response([], 503)]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertTrue($result['cached_fallback']);
        $this->assertSame('2026-10-21', $result['records'][0]['date']);
    }

    public function test_official_chattisgarh_spelling_is_scoped_to_state_22_and_the_exact_district(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174004']);
        $context = ['state' => ['code' => '22', 'name' => 'Chhattisgarh'], 'district' => ['code' => '646', 'name' => 'Balod']];
        $oldScope = hash('sha256', json_encode(['22', '646', null, 0]));
        Cache::put('my-place-markets:v4:'.$oldScope, ['records' => [], 'reason' => 'no_matching_records']);
        $row = ['state' => 'Chattisgarh', 'district' => 'Balod', 'market' => 'Dondi APMC', 'commodity' => 'Paddy(Common)', 'arrival_date' => '27/10/2026', 'min_price' => 2400, 'modal_price' => 2403, 'max_price' => 2405];
        Http::fake(['api.data.gov.in/*' => Http::response(['records' => [$row, array_replace($row, ['district' => 'Durg']), array_replace($row, ['state' => 'Maharashtra'])]])]);
        $service = app(OfficialMarketPrices::class);
        $result = $service->forPlace($context);
        $this->assertCount(1, $result['records']);
        $this->assertSame(2403.0, $result['records'][0]['modal']);
        $this->assertSame([], $service->verifiedRows([$row], $this->context));
        Http::assertSent(fn ($request) => ($request['filters[state]'] ?? null) === 'Chattisgarh' && ($request['filters[district]'] ?? null) === 'Balod');
    }

    public function test_daily_reports_do_not_hide_other_items_available_in_the_archive(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174005']);
        $catalog = $this->mock(OfficialMarketCatalog::class);
        $catalog->shouldReceive('decorate')->andReturnUsing(fn (array $rows): array => $rows);
        $catalog->shouldReceive('all')->andReturn([]);
        $daily = ['State' => 'Uttar Pradesh', 'District' => 'Pilibhit', 'Market' => 'Pilibhit APMC', 'Commodity' => 'Wheat', 'Arrival_Date' => '28/10/2026', 'Min_Price' => 2600, 'Modal_Price' => 2600, 'Max_Price' => 2600];
        $older = array_replace($daily, ['District' => 'Pillibhit', 'Arrival_Date' => '27/10/2026', 'Min_Price' => 2500, 'Modal_Price' => 2500, 'Max_Price' => 2500]);
        $potato = array_replace($older, ['Commodity' => 'Potato', 'Arrival_Date' => '21/10/2026']);
        Http::fake(['*/123e4567-*' => Http::response(['records' => [$daily]]), '*/35985678-*' => Http::response(['records' => [$older, $potato, array_replace($potato, ['District' => 'Bareilly'])], 'total' => 2000])]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertCount(2, $result['records']);
        $this->assertSame(['Wheat', 'Potato'], array_column($result['records'], 'commodity'));
        $this->assertSame([2600.0, 2500.0], array_column($result['records'], 'modal'));
        $this->assertSame(['daily', 'historical'], array_column($result['records'], 'source'));
        $this->assertSame('2026-10-21', $result['records'][1]['date']);
        $this->assertSame('mixed', $result['source']);
        $this->assertSame(3, $result['next_offset']);
        $this->assertFalse($result['partial_sources']);
        Http::assertSentCount(2);
    }

    public function test_daily_revision_wins_a_same_date_archive_copy_and_feed_failure_is_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'private-test-key', 'my_place.agmarknet.resource_id' => '123e4567-e89b-12d3-a456-426614174006']);
        $catalog = $this->mock(OfficialMarketCatalog::class);
        $catalog->shouldReceive('decorate')->andReturnUsing(fn (array $rows): array => $rows);
        $catalog->shouldReceive('all')->andReturn([]);
        $row = ['State' => 'Uttar Pradesh', 'District' => 'Pilibhit', 'Market' => 'Pilibhit APMC', 'Commodity' => 'Wheat', 'Arrival_Date' => '28/10/2026', 'Min_Price' => 2600, 'Modal_Price' => 2600, 'Max_Price' => 2600];
        Http::fake(['*/123e4567-*' => Http::response(['records' => [$row]]), '*/35985678-*' => Http::sequence()->push(['records' => [array_replace($row, ['District' => 'Pillibhit', 'Min_Price' => 2590, 'Modal_Price' => 2590, 'Max_Price' => 2590])]])->push([], 503)]);
        $result = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertCount(1, $result['records']);
        $this->assertSame(2600.0, $result['records'][0]['modal']);
        $this->assertSame('daily', $result['records'][0]['source']);
        Cache::flush();
        $partial = app(OfficialMarketPrices::class)->forPlace($this->context);
        $this->assertCount(1, $partial['records']);
        $this->assertTrue($partial['partial_sources']);
    }
}
