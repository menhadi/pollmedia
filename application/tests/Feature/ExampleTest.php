<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/')->assertOk()->assertSee('India,')->assertSee('/india/state/uttar-pradesh')->assertSee('/india/state/tamil-nadu')->assertDontSee('Accepted contests in linked profiles');
        $this->get('/india/state/uttar-pradesh')->assertOk()->assertSee('Uttar Pradesh,');
        $this->get('/india/state/unknown')->assertNotFound();
    }
}
