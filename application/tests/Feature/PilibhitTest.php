<?php

namespace Tests\Feature;

use App\Services\OfficeholderDirectory;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PilibhitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PilibhitSeeder::class);
    }

    public function test_district_and_pc_do_not_share_demographic_totals(): void
    {
        $this->get('/india/district/pilibhit')->assertOk()->assertSee('2,031,007')->assertSee('362,573')->assertSee('Citizen participation');
        $this->get('/india/pc/pilibhit')->assertOk()->assertDontSee('2,031,007')->assertSee('Jitin Prasada')->assertSee('Wikipedia')->assertSee('Constituency development totals have not been validated');
    }

    public function test_seed_is_idempotent_and_sir_is_filtered(): void
    {
        $before=DB::table('office_assignments')->count();
        $this->seed(PilibhitSeeder::class);
        $this->assertSame($before,DB::table('office_assignments')->count());
        $this->getJson('/api/sir?state=09&ac=127')->assertOk()->assertJsonPath('total_rows',20)->assertJsonPath('listed_records',1784)->assertJsonCount(10,'rows');
        $this->getJson('/api/sir?state=09&ac=128')->assertJsonPath('total_rows',0);
        $this->getJson('/api/sir')->assertJsonCount(0,'rows');
        $this->getJson('/api/sir?page=-1')->assertUnprocessable();
    }

    public function test_reviewed_officeholder_change_updates_page_and_preserves_history(): void
    {
        $office=DB::table('offices')->where('key','dm-pilibhit')->value('id');
        $old=DB::table('office_assignments')->where('office_id',$office)->first();
        $person=DB::table('people')->insertGetId(['key'=>'test-successor','display_name'=>'Test successor']);
        $service=app(OfficeholderDirectory::class);
        $id=$service->replace($office,$person,$old->source_release_id,'2026-09-16');
        $this->assertSame($id,$service->replace($office,$person,$old->source_release_id,'2026-09-16'));
        $this->get('/india/district/pilibhit')->assertSee('Test successor')->assertDontSee('Gyanendra Singh (IAS)');
        $prior=DB::table('office_assignments')->find($old->id);
        $this->assertNotNull($prior->superseded_at);
        $this->assertNull($prior->effective_to); // Unknown tenure end is not invented.
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/district/pilibhit')->assertSee('Test successor')->assertDontSee('Gyanendra Singh (IAS)');
        $this->assertSame(2,DB::table('office_assignments')->where('office_id',$office)->count());
    }
}
