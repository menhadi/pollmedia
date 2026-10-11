<?php

namespace Tests\Feature;

use App\Services\ElectionArchive;
use App\Services\ElectionGeographySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicLoadProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_reuses_place_count_until_cache_expires(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('elections.navigation.place-count.v1');
        $this->mock(ElectionArchive::class, fn ($mock) => $mock->shouldReceive('nationalAssemblyEntries')->andReturn([]));
        $counts = 0;
        DB::listen(function ($query) use (&$counts): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"places"')) {
                $counts++;
            }
        });
        $summary = app(ElectionGeographySummary::class);
        $first = $summary->states()->firstWhere('slug', 'uttar-pradesh')['places'];
        $this->assertSame($first, $summary->states()->firstWhere('slug', 'uttar-pradesh')['places']);
        $this->assertSame(1, $counts);
        $this->travel(301)->seconds();
        $summary->states();
        $this->assertSame(2, $counts);
    }

    public function test_feedback_page_does_not_create_a_recursive_crawl_link(): void
    {
        $this->get('/report-problem?path=%2Findia%2Fconstituency')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('class="footer-report"', false)
            ->assertSee('value="/india/constituency"', false);
    }
}
