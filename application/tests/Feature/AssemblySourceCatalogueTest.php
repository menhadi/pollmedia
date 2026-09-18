<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssemblySourceCatalogueTest extends TestCase
{
    public function test_national_and_historical_states_are_listed_without_claiming_extracted_results(): void
    {
        Storage::fake('local');
        $this->get('/india/elections/assembly/sources')->assertOk()->assertSee('427 report editions')
            ->assertSee('Bombay')->assertSee('Kerala')->assertSee('not necessarily downloaded or extracted');
        $this->get('/india/elections/assembly/sources?state=Kerala&year=2021')->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->count() === 1 && $rows->first()['collected'] === 0);
        $this->get('/india/elections/assembly/sources?state=invalid')->assertRedirect();
    }
}
