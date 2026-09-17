<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CensusHistory;
use Database\Seeders\PilibhitPopulationHistorySeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CensusHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_retains_district_scope_filters_and_reconciled_values(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitPopulationHistorySeeder::class]);
        $data = app(CensusHistory::class)->population();
        $this->assertCount(33, $data['rows']);
        foreach (collect($data['rows'])->groupBy('year') as $records) {
            $indexed = $records->keyBy('area');
            foreach ($records as $row) {
                $this->assertSame($row['population'], $row['male'] + $row['female']);
            }
            $this->assertSame($indexed['total']['population'], $indexed['rural']['population'] + $indexed['urban']['population']);
        }
        $this->get('/india/district/pilibhit/census-history')->assertOk()->assertSee('470,273')->assertSee('1,645,183')->assertSee('PDF page 20');
        $this->get('/india/district/pilibhit/census-history?area=urban&census_year=1901')->assertOk()->assertSee('54,820')->assertSee('26,750')->assertDontSee('249,580');
        $this->get('/india/district/pilibhit/census-history?census_year=1881')->assertSessionHasErrors('census_year');
        $this->get('/india/district/pilibhit')->assertOk()->assertSee(route('census.history'));
        $this->get('/india/ac/puranpur')->assertOk()->assertDontSee('population through the decades');
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('census.history'));
        $this->seed(PilibhitPopulationHistorySeeder::class);
        $source = DB::table('data_sources')->where('key', 'census-pilibhit-population-history')->value('id');
        $this->assertSame(1, DB::table('source_releases')->where('data_source_id', $source)->count());
    }

    public function test_archive_requires_admin_and_does_not_claim_historical_villages_are_imported(): void
    {
        $this->get('/admin/imports/census-history')->assertRedirect(route('admin.login'));
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $this->get('/admin/imports/census-history?census_year=1981')->assertOk()->assertSee('29728')->assertSee('pending');
        $this->get('/admin/imports/census-history?census_year=1961')->assertOk()->assertSee('One-village survey only');
        $this->get('/india/district/pilibhit/census-history')->assertNotFound();
    }
}
