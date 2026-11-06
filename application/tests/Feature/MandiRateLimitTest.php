<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MandiRateLimitTest extends TestCase
{
    public function test_price_and_history_budgets_remain_enforced_without_consuming_each_other(): void
    {
        config(['cache.default' => 'array']);
        $price = Route::getRoutes()->getByName('my-place.markets');
        $history = Route::getRoutes()->getByName('my-place.market-history');
        $this->assertNotNull($price);
        $this->assertNotNull($history);
        $priceLimit = collect($price->gatherMiddleware())->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));
        $historyLimit = collect($history->gatherMiddleware())->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));
        Route::get('/__tests/mandi-prices', fn () => response()->json(['ok' => true]))->middleware($priceLimit);
        Route::get('/__tests/mandi-history', fn () => response()->json(['ok' => true]))->middleware($historyLimit);
        Route::get('/__tests/public-data', fn () => response()->json(['ok' => true]))->middleware('throttle:120,1');
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/__tests/public-data')->assertOk();
            $this->getJson('/__tests/mandi-prices')->assertOk();
        }
        $this->getJson('/__tests/mandi-prices')->assertStatus(429);
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/__tests/mandi-history')->assertOk();
        }
        $this->getJson('/__tests/mandi-history')->assertStatus(429);
        $this->getJson('/__tests/public-data')->assertOk();
    }
}
