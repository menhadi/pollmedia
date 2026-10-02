<?php

namespace Tests\Feature;

use App\Services\OriginalHistoricalEducationPackage;
use App\Services\OriginalNationalEducationExpansionPackage;
use App\Services\OriginalNationalEducationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalNationalEducationExpansionPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_import_preserves_all_published_rows_and_retry_adds_nothing(): void
    {
        [$directory, $prior, $package, $service] = $this->publishedBaseline();
        try {
            $saved = DB::table('census_catalogue_rows')->where('edition_id', $prior['edition_id'])->orderBy('record_key')->get();
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $this->assertSame(12, $result['before_rows']);
            $this->assertSame(29, $result['after_rows']);
            $this->assertSame(17, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame(12, $result['publication']['active_before_rows']);
            $this->assertSame(17, $result['publication']['active_after_rows']);
            $this->assertSame(12, $result['publication']['retained_observation_rows']);
            $this->assertSame(5, $result['publication']['new_observation_rows']);
            $this->assertSame(55, $result['publication']['new_numeric_values']);
            $this->assertSame(179, $result['verified_statistics']['reported_integer_cells']);
            $this->assertSame(8, $result['verified_statistics']['original_dot_null_cells']);
            $this->assertSame(5, $result['verified_statistics']['arithmetic_discrepancies']);
            $this->assertEquals($saved, DB::table('census_catalogue_rows')->where('edition_id', $prior['edition_id'])->orderBy('record_key')->get());
            $new = DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->get()->keyBy('record_key');
            foreach ($saved as $old) {
                foreach (['state_code', 'district_code', 'level', 'residence', 'name', 'geography', 'values', 'flags', 'source_row'] as $field) {
                    $this->assertSame($old->{$field}, $new[$old->record_key]->{$field}, $field);
                }
            }
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $result['edition_id']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(0, $retry['added_editions']);
            $this->assertSame(17, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_numeric_values']);
            $this->assertDatabaseCount('census_catalogue_rows', 29);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_wrong_current_pointer_rolls_back_expansion_and_recovery_preserves_both_snapshots(): void
    {
        [$directory, $prior, $package, $service] = $this->publishedBaseline();
        try {
            try {
                $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, 0);
                $this->fail('Wrong baseline pointer was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 12);
            $this->assertDatabaseCount('census_editions', 1);
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $recovered = $service->recoverPublication($result['receipt'], $result['receipt_sha256']);
            $this->assertSame($prior['edition_id'], $recovered['restored_publication_id']);
            $this->assertSame(0, $recovered['deleted_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 29);
            $this->assertSame('published', DB::table('census_editions')->where('id', $prior['edition_id'])->value('status'));
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            DB::table('census_publications')->where('source_key', 'census-original-education-32022-1961-c3a')->update(['edition_id' => $prior['edition_id']]);
            try {
                $service->recoverPublication($retry['receipt'], $retry['receipt_sha256']);
                $this->fail('Recovery replaced a changed pointer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 29);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_public_original_area_filters_keep_madras_flag_state_universe_and_india_dots(): void
    {
        [$directory, $prior, $package, $service] = $this->publishedBaseline();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'MADRAS', 'field' => 'TOT_F']))
                ->assertOk()->assertSee('1 matching source records')->assertSee('16,775,975')->assertSee('difference 50000')
                ->assertSee('Physical page 121, printed page 114')->assertSee('state rows use their own printed populations');
            $state = DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->where('name', 'MADRAS — All ages')->first();
            $geography = json_decode($state->geography, true);
            $this->assertSame(4, $geography['original_block_ordinal']);
            $this->assertSame('Not mapped', $geography['modern_LGD_mapping']);
            $this->assertStringContainsString('INDIA* NEFA exclusion is not applied', $geography['definition_context']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'INDIA*', 'field' => 'MATRIC_AND_ABOVE_F']))
                ->assertOk()->assertSee('12 matching source records')->assertSee('Original dot notation: ..')->assertSee('Not reported');
            $this->getJson(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'KERALA']))->assertStatus(422);
            $this->assertSame(0, DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->where('name', 'like', 'KERALA%')->count());
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_expansion_manifest_pin_rejects_legacy_and_other_source_adapters_and_rehashed_tamper(): void
    {
        $package = $this->package('national-original-education-expansion-1961-v2.zip');
        foreach ([new OriginalNationalEducationPackage, new OriginalHistoricalEducationPackage] as $adapter) {
            $this->assertRejected($adapter, $package);
        }
        $this->assertRejected(new OriginalNationalEducationExpansionPackage, $this->package('national-original-education-india-1961-v1.zip'));
        foreach (['value', 'flag', 'manifest', 'extra'] as $change) {
            $path = tempnam(sys_get_temp_dir(), 'national-expansion-');
            copy($package, $path);
            try {
                $zip = new ZipArchive;
                $zip->open($path);
                if ($change === 'extra') {
                    $zip->addFromString('../extra.txt', 'extra');
                } elseif ($change === 'manifest') {
                    $manifest = json_decode($zip->getFromName('manifest.json'), true);
                    $manifest['scope'] = 'National complete';
                    $zip->addFromString('manifest.json', json_encode($manifest));
                } else {
                    $payload = json_decode($zip->getFromName('education-population.json'), true);
                    if ($change === 'value') {
                        $payload['rows'][16]['values']['ILLITERATE_F'] -= 50000;
                    } else {
                        $payload['rows'][16]['flags'] = [];
                    }
                    $raw = json_encode($payload);
                    $zip->addFromString('education-population.json', $raw);
                    $manifest = json_decode($zip->getFromName('manifest.json'), true);
                    $manifest['files']['education-population.json'] = hash('sha256', $raw);
                    $zip->addFromString('manifest.json', json_encode($manifest));
                }
                $zip->close();
                $this->assertRejected(new OriginalNationalEducationExpansionPackage, $path);
            } finally {
                unlink($path);
            }
        }
        $this->assertDatabaseCount('census_editions', 0);
        $this->artisan('census:import-national-original-education-expansion', ['package' => $package, 'sha256' => hash_file('sha256', $package), '--check' => true])->assertExitCode(0);
        $this->assertDatabaseCount('census_editions', 0);
    }

    private function publishedBaseline(): array
    {
        $directory = sys_get_temp_dir().'/national-expansion-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $package = $this->package('national-original-education-india-1961-v1.zip');
        $prior = app(OriginalNationalEducationPackage::class)->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, 0);

        return [$directory, $prior, $this->package('national-original-education-expansion-1961-v2.zip'), app(OriginalNationalEducationExpansionPackage::class)];
    }

    private function package(string $name): string
    {
        $path = base_path('../exports/historical-1961-20261001/'.$name);
        if (! is_file($path)) {
            $this->markTestSkipped('Preserved reviewed original package absent.');
        }

        return $path;
    }

    private function retrievedAt(): string
    {
        return '2026-10-02T07:25:32.066512+00:00';
    }

    private function assertRejected(OriginalHistoricalEducationPackage $service, string $package): void
    {
        try {
            $service->verify($package, hash_file('sha256', $package));
            $this->fail('Unreviewed source package accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    private function cleanup(string $directory): void
    {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
