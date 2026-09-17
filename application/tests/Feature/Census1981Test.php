<?php

namespace Tests\Feature;

use App\Services\CensusHistory;
use Database\Seeders\PilibhitCensus1981Seeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Census1981Test extends TestCase
{
    use RefreshDatabase;

    public function test_verified_values_reconcile_and_source_discrepancy_is_withheld(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitCensus1981Seeder::class]);
        $data = app(CensusHistory::class)->edition1981();
        $this->assertCount(12, $data['rows']);
        foreach (collect($data['rows'])->groupBy('geography') as $records) {
            $indexed = $records->keyBy('area');
            foreach ($records as $row) {
                if ($row['female'] !== null) {
                    $this->assertSame($row['population'], $row['male'] + $row['female']);
                }
            }
            foreach (['population', 'male', 'households'] as $field) {
                $this->assertSame($indexed['total'][$field], $indexed['rural'][$field] + $indexed['urban'][$field]);
            }
        }
        foreach (collect($data['rows'])->groupBy('area') as $records) {
            foreach (['population', 'male', 'households'] as $field) {
                $this->assertSame($records->firstWhere('geography', 'district')[$field], $records->where('geography', '!=', 'district')->sum($field));
            }
        }
        $this->get('/india/district/pilibhit/census-1981')->assertOk()->assertSee('1,008,312')->assertSee('175,781');
        $this->get('/india/district/pilibhit/census-1981?area=rural')->assertOk()->assertSee('Withheld: source discrepancy')->assertSee('366,455')->assertDontSee('386,465');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&area=urban')->assertOk()->assertSee('22,667')->assertSee('#page=163')->assertSee('#page=167')->assertDontSee('1,008,312');
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur')->assertOk()->assertSee('#page=113')->assertSee('#page=118');
        $this->get('/india/district/pilibhit/census-1981?geography=pilibhit')->assertOk()->assertSee('#page=56')->assertSee('#page=61');
        $this->get('/india/district/pilibhit/census-1981?geography=amaria')->assertSessionHasErrors('geography');
        $this->get('/india/district/pilibhit/census-1981?area=unknown')->assertSessionHasErrors('area');
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('census.1981'));
        $this->seed(PilibhitCensus1981Seeder::class);
        $source = DB::table('data_sources')->where('key', 'census-pilibhit-summary-1981')->value('id');
        $this->assertSame(1, DB::table('source_releases')->where('data_source_id', $source)->count());
    }

    public function test_unpublished_edition_is_not_exposed(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/district/pilibhit/census-1981')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('census.1981'));
    }
}
