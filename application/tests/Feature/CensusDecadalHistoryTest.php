<?php

namespace Tests\Feature;

use Tests\TestCase;

class CensusDecadalHistoryTest extends TestCase
{
    public function test_all_historical_records_and_source_notes_are_public(): void
    {
        $this->get('/india/census/history')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 432)
            ->assertSee('2011 jurisdictions')->assertSee('estimated population')->assertSee('Official source footnotes');
        $this->get('/india/census/history?state=00&year=2011')->assertOk()
            ->assertSee('1,210,854,977')->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $this->get('/india/census/history?state=25&year=1900')->assertOk()
            ->assertSee('32,005')->assertSee('has not been shifted');
        $this->get('/india/census/history?state=99')->assertRedirect();
        $this->get('/india/census/history?year=2021')->assertRedirect();
    }
}
