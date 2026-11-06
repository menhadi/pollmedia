<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgriInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_entry_and_old_bookmarks_have_the_same_destination(): void
    {
        $this->get('/farmers?lang=hi')->assertStatus(301)->assertRedirect('/agri-insights?lang=hi');
        $page = $this->get('/agri-insights')->assertOk()->assertSee('Insight')->assertSee('Jatipur example reports')->assertDontSee('Agri Insights')->assertDontSee('Farmer assistant');
        $page->assertSee('id="farmer-story"', false)->assertDontSee('id="farmer-story-details"', false)->assertSee('id="farmer-soil-details"', false);
        $this->assertLessThan(strpos($page->getContent(), 'id="farmer-story"'), strpos($page->getContent(), 'data-voice="farmer-story"'));
        $page->assertDontSee('name="story" required', false);
    }

    public function test_english_example_has_complete_illustrative_inputs_and_honest_missing_sources(): void
    {
        $this->get('/agri-insights/example')->assertStatus(301)->assertRedirect('/agri-insights/examples/field-planning/en');
        $this->get('/agri-insights/examples/field-planning/en')->assertOk()->assertSee('Illustrative')->assertSee('2 acres')->assertSee('6.8')->assertSee('180 kg/ha')->assertSee('Dated reference only')->assertDontSee('dummy')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
