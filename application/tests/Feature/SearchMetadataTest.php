<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_contains_distinct_available_editions_and_excludes_drafts(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $urls = [];
        foreach ($xml->url as $entry) {
            $urls[] = (string) $entry->loc;
        }
        $this->assertCount(2664, $urls);
        $this->assertCount(count($urls), array_unique($urls));
        $this->assertContains(url('/india/pc/pilibhit?year=2019'), $urls);
        $this->assertNotContains(url('/india/pc/pilibhit?year=2024'), $urls);
        $this->assertNotContains(url('/india'), $urls);
        $this->assertCount(1216, array_filter($urls, fn ($url) => str_contains($url, '/india/village/') && str_ends_with($url, '?year=2001')));
        $this->assertCount(1435, array_filter($urls, fn ($url) => str_contains($url, '/india/village/') && ! str_contains($url, '?')));
        $this->assertStringNotContainsString('/reports/', $response->getContent());
        $this->assertStringNotContainsString('<lastmod>', $response->getContent());
        foreach (['/india/pc/pilibhit', '/india/pc/pilibhit?year=2019', '/india/village/131548-alam-dandi'] as $path) {
            $this->get($path)->assertOk()->assertSee('<link rel="canonical" href="'.url($path).'">', false);
        }
        $historical = current(array_filter($urls, fn ($url) => str_contains($url, '/india/village/') && str_ends_with($url, '?year=2001')));
        $this->get($historical)->assertOk()->assertSee('<link rel="canonical" href="'.$historical.'">', false)->assertSee('Census 2001');
    }

    public function test_duplicate_filters_have_clean_canonicals_and_valid_breadcrumb_metadata(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $this->get('/india?q=Pilibhit')->assertOk()->assertSee('<link rel="canonical" href="'.url('/').'">', false);
        $this->get('/india/pc/pilibhit?year=2024')->assertOk()->assertSee('<link rel="canonical" href="'.url('/india/pc/pilibhit').'">', false);
        $response = $this->get('/india/village/131548-alam-dandi?year=2011')->assertOk();
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $response->getContent(), $matches);
        $schema = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('BreadcrumbList', $schema['@type']);
        $this->assertSame('Alam Dandi village', $schema['itemListElement'][4]['name']);
        $this->assertSame(url('/india/village/131548-alam-dandi'), $schema['itemListElement'][4]['item']);
        $this->get('/reports/pilibhit')->assertOk()->assertSee('noindex');
    }
}
