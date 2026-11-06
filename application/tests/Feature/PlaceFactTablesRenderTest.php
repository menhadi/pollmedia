<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class PlaceFactTablesRenderTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_facts_and_sources_are_in_initial_html_with_safe_visible_text(): void
    {
        $html = view('place-fact-tables', ['facts' => ['name' => 'Example </script>', 'sections' => [['title' => 'Water facts', 'note' => 'Missing observations are excluded.', 'rows' => [['label' => 'Household tap coverage', 'question' => 'What is the recorded coverage?', 'answer' => '0.00%', 'period' => 'JJM retrieved 2026-10-13', 'source_url' => 'https://ejalshakti.gov.in/example', 'links' => []]]]]]])->render();
        $this->assertStringContainsString('<table class=', $html);
        $this->assertStringContainsString('scope="row">Household tap coverage', $html);
        $this->assertStringContainsString('0.00%', $html);
        $this->assertStringNotContainsString('JJM retrieved 2026-10-13', $html);
        $this->assertStringNotContainsString('Dates &amp;', $html);
        $this->assertStringNotContainsString('https://ejalshakti.gov.in/example', $html);
        $this->assertStringNotContainsString('<th scope="col">Question', $html);
        $this->assertStringContainsString('Example &lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Example </script>', $html);
    }

    public function test_comparisons_use_separate_columns_and_omit_unavailable_rows(): void
    {
        $html = view('place-fact-tables', ['facts' => ['name' => 'Example', 'sections' => [['title' => 'Comparisons', 'rows' => [
            ['label' => 'Largest village', 'place' => 'Reported village', 'value' => '100 people'],
            ['label' => 'Missing observation', 'place' => 'Unknown village', 'value' => 'Unavailable'],
            ['label' => 'Tap coverage', 'value' => '0.00%'],
        ]]]]])->render();
        $this->assertStringContainsString('scope="col">Place</th>', $html);
        $this->assertStringContainsString('Reported village</td>', $html);
        $this->assertStringContainsString('0.00%', $html);
        $this->assertStringNotContainsString('Missing observation', $html);
        $this->assertStringNotContainsString('Unknown village', $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    public function test_historical_village_metadata_preserves_its_census_edition_url(): void
    {
        $request = Request::create('/india/village/1-example?year=2001');
        $route = new Route('GET', 'india/village/{village}', fn () => null);
        $route->name('villages.show');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        $html = view('place-fact-tables', ['facts' => ['name' => 'Example', 'sections' => []]])->render();
        $this->assertSame(1, preg_match('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $match));
        $schema = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('http://localhost/india/village/1-example?year=2001', $schema['url']);
    }
}
