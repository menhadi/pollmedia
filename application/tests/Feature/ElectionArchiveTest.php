<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectionArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_years_are_available_before_any_results_are_imported(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $this->get('/admin/imports/elections?archive_type=ac&archive_year=1951')->assertOk()
            ->assertSee('3241-uttar-pradesh-1951')->assertSee('historical mapping pending');
        $this->get('/admin/imports/elections?archive_type=pc&archive_year=2019')->assertOk()
            ->assertSee('Including Vellore PC')->assertSee('Excluding Vellore PC');
        $this->get('/admin/imports/elections?archive_type=ac&archive_year=2024')->assertOk()
            ->assertSee('No entry for this year');
        $this->get('/admin/imports/elections?archive_type=invalid')->assertSessionHasErrors('archive_type');
        $this->assertCount(21, app(ElectionArchive::class)->entries('pc'));
        $this->assertCount(18, app(ElectionArchive::class)->entries('ac'));
        $this->assertDatabaseCount('election_contests', 0);
    }
}
