<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndicatorBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_india_filters_use_only_accepted_measurements_and_available_periods(): void
    {
        $this->seed(PilibhitSeeder::class);
        $observation = DB::table('observations')->first();
        $this->get('/india/data')->assertOk()->assertSee('India historical data')
            ->assertViewHas('measurements', fn ($rows) => $rows->total() === 3);
        $this->get('/india/data?place='.$observation->place_id.'&indicator='.$observation->indicator_id.'&period='.$observation->period)
            ->assertOk()->assertViewHas('measurements', fn ($rows) => $rows->total() === 1)
            ->assertSee('Official evidence')->assertSee('Definition');
        DB::table('source_releases')->where('id', $observation->source_release_id)->update(['status' => 'pending']);
        $this->get('/india/data')->assertOk()->assertSee('No accepted measurements match')
            ->assertViewHas('periods', fn ($periods) => $periods->isEmpty());
    }

    public function test_missing_and_zero_values_remain_distinct_and_foreign_data_stays_out_of_india(): void
    {
        $this->seed(PilibhitSeeder::class);
        $row = DB::table('observations')->first();
        $place = DB::table('places')->insertGetId(['slug' => 'foreign-data-test', 'name' => 'Foreign data test', 'type' => 'borough', 'country_code' => 'GB']);
        foreach (['2001' => null, '2011' => 0] as $period => $value) {
            DB::table('observations')->insert(['place_id' => $place, 'indicator_id' => $row->indicator_id,
                'source_release_id' => $row->source_release_id, 'period' => $period, 'value' => $value, 'status' => 'reported']);
        }
        $this->get('/data?country=GB')->assertOk()->assertSee('Foreign data test')->assertSee('Not available')
            ->assertViewHas('measurements', fn ($rows) => $rows->total() === 2 && $rows->whereNull('value')->count() === 1);
        $this->get('/data?country=GB&period=2011')->assertOk()->assertDontSee('Not available')
            ->assertViewHas('measurements', fn ($rows) => (float) $rows->first()->value === 0.0);
        $this->get('/india/data?country=GB')->assertOk()->assertDontSee('Foreign data test');
        $this->get('/india/data?place='.$place)->assertOk()->assertViewHas('measurements', fn ($rows) => $rows->total() === 0);
    }
}
