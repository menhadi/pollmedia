<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VillagePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_electoral_connections_follow_lgd_codes_and_exclude_unmapped_profiles_only_when_filtered(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/village/131548-alam-dandi')->assertOk()->assertSee('Pilibhit AC')->assertSee('Pilibhit PC')
            ->assertSee('/india/ac/pilibhit#people')->assertSee('/india/pc/pilibhit#people')->assertSee('spreadsheet row 626')
            ->assertSee('whole constituency, not this village');
        $this->get('/india/village/02436000-alam-dandi?year=2001')->assertOk()->assertSee('Pilibhit AC')->assertSee('separate from Census 2001 geography');
        $this->get('/india/village/131249-gularia-bhindara')->assertOk()->assertSee('No village-code match')->assertDontSee('/india/ac/pilibhit#people');
        $this->get('/india/villages?pc=pilibhit')->assertOk()->assertSee('1,432 village profiles');
        $this->get('/india/villages?ac=pilibhit&village=131548')->assertOk()->assertSee('1 village profiles');
        $this->get('/india/villages?ac=barkhera&village=131548')->assertStatus(422);
        $this->get('/india/villages?pc=pilibhit&village=131249')->assertStatus(422);
        $this->get('/india/villages?ac=unknown')->assertSessionHasErrors('ac');
        $this->get('/india/villages?pc=unknown')->assertSessionHasErrors('pc');
        $this->get('/india/villages?ac=pilibhit&page=2')->assertOk()->assertSee('ac=pilibhit');
        $this->get('/india/ac/pilibhit')->assertOk()->assertSee('/india/villages?ac=pilibhit');
        $this->get('/india/pc/pilibhit')->assertOk()->assertSee('/india/villages?pc=pilibhit');
    }

    public function test_current_administration_dropdowns_scope_villages_and_preserve_census_edition(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/villages?current_block=1443&panchayat=82042')->assertOk()
            ->assertSee('Current LGD block')->assertSee('Current gram panchayat')->assertSee('Alam Dandi')
            ->assertSee('Chand Dandi')->assertDontSee('Current LGD identifiers, gram panchayat and electoral mappings remain unverified.');
        $this->get('/india/villages?panchayat=82042&village=131548')->assertOk()->assertSee('1 village profiles');
        $this->get('/india/villages?year=2001&panchayat=82042&village=02436000')->assertOk()
            ->assertSee('1 village profiles')->assertSee('year=2001');
        $this->get('/india/villages?current_block=1439&panchayat=82042')->assertSessionHasErrors('panchayat');
        $this->get('/india/villages?panchayat=999999')->assertSessionHasErrors('panchayat');
        $this->get('/india/villages?current_block=999999')->assertSessionHasErrors('current_block');
        $this->get('/india/villages?panchayat=82042&village=131191')->assertStatus(422);
        $this->get('/india/villages?subdistrict=00792&panchayat=82042')->assertSessionHasErrors('panchayat');
        $this->get('/india/villages?current_block=1443&page=2')->assertOk()->assertSee('current_block=1443');
        $this->get('/india/villages?current_subdistrict=6867')->assertOk()->assertSee('Amariya')->assertSee('Current LGD subdistrict / tehsil');
        $this->get('/india/villages?current_subdistrict=790&panchayat=82042&village=131548')->assertOk()->assertSee('1 village profiles');
        $this->get('/india/villages?current_subdistrict=6867&panchayat=82042')->assertSessionHasErrors('panchayat');
        $this->get('/india/villages?current_subdistrict=999999')->assertSessionHasErrors('current_subdistrict');
    }

    public function test_current_lgd_records_use_explicit_census_codes_and_keep_snapshot_dates(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/village/131548-alam-dandi')->assertOk()
            ->assertSee('Current village administration')->assertSee('Chand Dandi')->assertSee('82042')
            ->assertSee('Lalaurikhera')->assertSee('1443')->assertSee('LGD 173')
            ->assertSee('2026-09-16')->assertSee('not a live feed')->assertSee('Census 2011 code 131548');
        $this->get('/india/village/02436000-alam-dandi?year=2001')->assertOk()
            ->assertSee('Chand Dandi')->assertSee('Census 2001 code 02436000');
        $source = DB::table('data_sources')->where('key', 'lgd-pilibhit')->value('id');
        $release = DB::table('source_releases')->where('data_source_id', $source)->first();
        $payload = json_decode($release->payload, true);
        $payload['villages'] = [];
        DB::table('source_releases')->where('id', $release->id)->update(['payload' => json_encode($payload)]);
        $this->get('/india/village/131548-alam-dandi')->assertOk()->assertSee('No current LGD village record')
            ->assertDontSee('Chand Dandi')->assertSee('Village at a glance');
    }

    /**
     * A basic feature test example.
     */
    public function test_village_profiles_keep_census_identity_and_scope(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/villages?q=Puranpur')->assertOk()->assertSee('Choose your area')->assertSee('Puranpur')->assertDontSee('type="search"', false);
        $this->get('/india/villages?subdistrict=00792')->assertOk()->assertSee('498 village profiles')->assertSee('Next');
        $this->get('/india/villages?subdistrict=00790&village=131191')->assertOk()->assertSee('1 village profiles');
        $this->get('/india/villages?subdistrict=00792&village=131191')->assertStatus(422);
        $this->get('/india/villages?year=2001')->assertOk()->assertSee('Open 2001 handbook PDF')->assertDontSee('2,498 people');
        $this->get('/india/villages?subdistrict=99999')->assertSessionHasErrors('subdistrict');
        $this->get('/india/village/131191-bagnera-bagneri')->assertOk()->assertSee('2,498')->assertSee('448')->assertSee('1,380')->assertSee('422')->assertSee('Census 2011')->assertSee('00790')->assertSee('catalog/6342')->assertSee('Original Census workbook')->assertDontSee('2,031,007')->assertSee('Assembly & parliamentary constituency', false);
        $this->get('/india/village/131191-wrong-name')->assertRedirect('/india/village/131191-bagnera-bagneri')->assertStatus(301);
        $this->get('/india/village/999999-unknown')->assertNotFound();
        $this->get('/india/district/pilibhit')->assertOk()->assertSee('/india/village/131191-bagnera-bagneri');
        $this->get('/')->assertOk()->assertSee('/india/villages');
        foreach (json_decode(file_get_contents(database_path('fixtures/sample.json')), true)['census']['villages'] as $village) {
            $this->get(route('villages.show', ['code' => $village['code'], 'slug' => Str::slug($village['name'])]))->assertOk()->assertSee(number_format($village['population']));
        }
    }

    public function test_historical_census_uses_its_own_codes_and_values(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/villages?year=2001&subdistrict=0003')->assertOk()->assertSee('366 village profiles')->assertSee('1,216')->assertDontSee('498 village profiles');
        $this->get('/india/village/02399700-bagnera-bagneri?year=2001')->assertOk()->assertSee('2,064')->assertSee('318')->assertSee('919')->assertSee('450')->assertSee('Census 2001')->assertSee('catalog/20761')->assertDontSee('2,498');
        $this->get('/india/village/02399700-wrong?year=2001')->assertRedirect('/india/village/02399700-bagnera-bagneri?year=2001');
        $this->get('/india/village/02399700-bagnera-bagneri')->assertNotFound();
        $this->get('/india/village/131191-bagnera-bagneri?year=2001')->assertNotFound();
        $this->get('/india/villages?year=2001&subdistrict=00792')->assertSessionHasErrors('subdistrict');
    }

    public function test_verified_blocks_and_cross_year_links_are_scoped_to_the_source(): void
    {
        $this->seed(PilibhitSeeder::class);
        $this->get('/india/villages?year=2011&block=Amariya')->assertOk()->assertSee('201 village profiles');
        $this->get('/india/villages?year=2011&subdistrict=00792&block=Amariya')->assertSessionHasErrors('block');
        $this->get('/india/villages?year=2001&block=Amariya')->assertSessionHasErrors('block');
        $this->get('/india/village/131191-bagnera-bagneri')->assertOk()->assertSee('Development block in the 2011 handbook: Amariya')->assertSee('/india/village/02399700-bagnera-bagneri?year=2001')->assertSee('#page=83');
        $this->get('/india/village/02399700-bagnera-bagneri?year=2001')->assertOk()->assertSee('/india/village/131191-bagnera-bagneri?year=2011');
        $this->get('/india/village/131658-mohof-forest')->assertOk()->assertSee('No unique village match');
    }

    public function test_villages_require_an_accepted_source_release(): void
    {
        $this->get('/india/villages')->assertStatus(503);
    }
}
