<?php

namespace Tests\Feature;

use App\Services\CensusHistory;
use Database\Seeders\PilibhitCensus1981Seeder;
use Database\Seeders\PilibhitVillages1981Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Census1981VillagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_village_selection_preserves_scope_and_uninhabited_rows(): void
    {
        $this->seed([PilibhitCensus1981Seeder::class, PilibhitVillages1981Seeder::class]);
        $data = app(CensusHistory::class)->villages1981();
        $data['villages'] = array_values(array_filter($data['villages'], fn ($row) => $row['tahsil'] === 'puranpur'));
        $this->assertCount(388, $data['villages']);
        $this->assertCount(41, collect($data['villages'])->where('status', 'uninhabited'));
        $identifiers = [];
        foreach ($data['villages'] as $village) {
            $identifiers[] = $village['tahsil_code'].':'.$village['code'];
            if ($village['status'] === 'uninhabited') {
                foreach (['population', 'male', 'female', 'households'] as $field) {
                    $this->assertNull($village[$field]);
                }
            } else {
                if ($village['population'] !== null && $village['female'] !== null) {
                    $this->assertSame($village['population'], $village['male'] + $village['female']);
                } else {
                    $this->assertNotEmpty($village['quality_note']);
                }
                $this->assertGreaterThan(0, $village['households']);
            }
        }
        $this->assertCount(388, array_unique($identifiers));
        foreach (collect($data['verified_source_subtotals'])->where('tahsil', 'puranpur') as $subtotal) {
            $group = collect($data['villages'])->filter(fn ($row) => (int) $row['code'] >= $subtotal['first_code'] && (int) $row['code'] <= $subtotal['last_code']);
            foreach (['population', 'male', 'female', 'households'] as $field) {
                $this->assertSame($subtotal[$field], $group->sum($field), $subtotal['name'].' '.$field);
            }
        }
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur')->assertOk()->assertSee('All 388 source records')->assertSee('source coverage')->assertSee('Bandar Bojh')->assertSee('Not tabulated');
        $response = $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=1');
        $response->assertOk()->assertSee('687')->assertSee('384')->assertSee('303')->assertSee('3:1')->assertSee('1 source records shown');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=4')->assertOk()->assertSee('Uninhabited')->assertSee('Not tabulated');
        $this->get('/india/district/pilibhit/census-1981?geography=pilibhit&village=1')->assertSessionHasErrors('village');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=999')->assertSessionHasErrors('village');
        $this->get('/india/district/pilibhit/census-1981?geography=pilibhit')->assertOk()->assertDontSee('All 388 source records')->assertSee('Select Puranpur or Bisalpur');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=27')->assertOk()->assertSee('Jamanian Khas')->assertSee('3,319')->assertSee('1,805')->assertSee('1,514')->assertSee('#page=169')->assertSee('#page=170');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=53')->assertOk()->assertSee('Grant No. 16 urf Rampur')->assertSee('Uninhabited')->assertSee('Not tabulated');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=80')->assertOk()->assertSee('Madho Tanda')->assertSee('5,786')->assertSee('3,103')->assertSee('2,683')->assertSee('#page=171')->assertSee('#page=172');
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=104')->assertOk()->assertSee('Kalinagar')->assertSee('4,773')->assertSee('896')->assertSee('#page=173')->assertSee('#page=174');
        $this->assertSame(range(1, 388), array_map(fn ($row) => (int) $row['code'], $data['villages']));
        $this->assertCount(6, $data['forest_ranges']);
        $this->assertSame(219936, collect($data['villages'])->sum(fn ($row) => $row['source_printed_population'] ?? $row['population'] ?? 0));
        $this->assertSame(39131, collect($data['villages'])->sum('households'));
        $this->assertSame(933, collect($data['forest_ranges'])->sum('population'));
        $this->assertSame(318, collect($data['forest_ranges'])->sum('households'));
        foreach (['166', '223', '285', '376'] as $code) {
            $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village='.$code)->assertOk()->assertSee('Withheld: see note');
        }
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur&village=388')->assertOk()->assertSee('Chakpur Taluka Ajitpur Bilha')->assertSee('358')->assertSee('#page=191')->assertSee('Forest ranges');
        $this->seed(PilibhitVillages1981Seeder::class);
        $source = DB::table('data_sources')->where('key', 'census-pilibhit-historical-villages-1981')->value('id');
        $this->assertSame(1, DB::table('source_releases')->where('data_source_id', $source)->count());
        $this->assertDatabaseMissing('data_sources', ['key' => 'census-pilibhit-villages-1981']);
    }

    public function test_missing_village_release_does_not_disable_summary(): void
    {
        $this->seed(PilibhitCensus1981Seeder::class);
        $this->get('/india/district/pilibhit/census-1981?geography=puranpur')->assertOk()->assertSee('243,536')->assertSee('Verified village transcription has not yet been imported.');
    }

    public function test_bisalpur_codes_are_scoped_and_subtotals_reconcile(): void
    {
        $this->seed([PilibhitCensus1981Seeder::class, PilibhitVillages1981Seeder::class]);
        $data = app(CensusHistory::class)->villages1981();
        $rows = collect($data['villages'])->where('tahsil', 'bisalpur');
        $this->assertCount(113, $rows);
        $this->assertCount(12, $rows->where('status', 'uninhabited'));
        $this->assertCount(501, collect($data['villages'])->map(fn ($row) => $row['tahsil_code'].':'.$row['code'])->unique());
        foreach ($rows as $row) {
            if ($row['status'] === 'inhabited' && $row['population'] !== null) {
                $this->assertSame($row['population'], $row['male'] + $row['female']);
            }
        }
        foreach (collect($data['verified_source_subtotals'])->where('tahsil', 'bisalpur') as $subtotal) {
            $group = $rows->filter(fn ($row) => (int) $row['code'] >= $subtotal['first_code'] && (int) $row['code'] <= $subtotal['last_code']);
            foreach (['population', 'male', 'female', 'households'] as $field) {
                $this->assertSame($subtotal[$field], $group->sum($field));
            }
        }
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur&village=1')->assertOk()->assertSee('Khamria Pandai')->assertSee('3,476')->assertSee('2:1')->assertSee('Partial Bisalpur coverage')->assertDontSee('Bandar Bojh')->assertDontSee('Forest ranges / separately');
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur&village=30')->assertOk()->assertSee('Withheld: see note')->assertSee('563')->assertSee('#page=120');
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur&village=65')->assertOk()->assertSee('Barkhera Kalan Mustaqil')->assertSee('5,834')->assertSee('3,214')->assertSee('#page=122')->assertSee('#page=123');
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur&village=113')->assertOk()->assertSee('Jadupur Patti')->assertSee('418')->assertSee('#page=124')->assertSee('#page=125');
        $this->get('/india/district/pilibhit/census-1981?geography=bisalpur&village=388')->assertSessionHasErrors('village');
    }
}
