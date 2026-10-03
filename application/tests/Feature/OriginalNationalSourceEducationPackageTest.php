<?php

namespace Tests\Feature;

use App\Services\OriginalHistoricalEducationPackage;
use App\Services\OriginalNationalEducationExpansionPackage;
use App\Services\OriginalNationalEducationPackage;
use App\Services\OriginalNationalSourceEducationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalNationalSourceEducationPackageTest extends TestCase
{
    use RefreshDatabase;

    public static function baselines(): array
    {
        return ['twelve published ages' => [false, 12, 12, 30, 330],
            'seventeen published rows' => [true, 29, 17, 25, 275]];
    }

    #[DataProvider('baselines')]
    public function test_additive_publication_preserves_prior_rows_and_retry_is_a_noop(bool $expanded, int $storedBefore, int $activeBefore, int $newRows, int $newValues): void
    {
        [$directory, $prior, $package, $service] = $this->baseline($expanded);
        try {
            $before = DB::table('census_catalogue_rows')->orderBy('id')->get()->keyBy('id');
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $this->assertSame($storedBefore, $result['before_rows']);
            $this->assertSame($storedBefore + 42, $result['after_rows']);
            $this->assertSame(42, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame($activeBefore, $result['publication']['active_before_rows']);
            $this->assertSame(42, $result['publication']['active_after_rows']);
            $this->assertSame($activeBefore, $result['publication']['retained_observation_rows']);
            $this->assertSame($newRows, $result['publication']['new_observation_rows']);
            $this->assertSame($newValues, $result['publication']['new_numeric_values']);
            foreach ($before as $id => $saved) {
                $this->assertEquals($saved, DB::table('census_catalogue_rows')->find($id));
            }
            $new = DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->get()->keyBy('record_key');
            foreach (DB::table('census_catalogue_rows')->where('edition_id', $prior['edition_id'])->get() as $old) {
                foreach (['state_code', 'district_code', 'level', 'residence', 'name', 'geography', 'values', 'flags', 'source_row'] as $field) {
                    $this->assertSame($old->{$field}, $new[$old->record_key]->{$field}, $field);
                }
            }
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $this->assertSame(454, $result['verified_statistics']['reported_integer_cells']);
            $this->assertSame(8, $result['verified_statistics']['original_dot_null_cells']);
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $result['edition_id']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(0, $retry['corrected_rows']);
            $this->assertSame(0, $retry['added_editions']);
            $this->assertSame(42, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_observation_rows']);
            $this->assertSame(0, $retry['publication']['new_numeric_values']);
            $this->assertDatabaseCount('census_catalogue_rows', $storedBefore + 42);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_wrong_pointer_rolls_back_and_recovery_restores_pointer_without_deletion(): void
    {
        [$directory, $prior, $package, $service] = $this->baseline(true);
        try {
            $runsBefore = DB::table('import_runs')->count();
            try {
                $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, 0);
                $this->fail('Wrong prior publication accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 29);
            $this->assertDatabaseCount('census_editions', 2);
            $this->assertDatabaseCount('import_runs', $runsBefore);
            $this->assertSame($prior['edition_id'], (int) DB::table('census_publications')->where('source_key', $this->sourceKey())->value('edition_id'));
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $backup = file_get_contents($result['baseline_backup']);
            file_put_contents($result['baseline_backup'], $backup.' ');
            try {
                $service->recoverPublication($result['receipt'], $result['receipt_sha256']);
                $this->fail('Changed baseline backup accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
            file_put_contents($result['baseline_backup'], $backup);
            $this->artisan('census:recover-national-original-education-source-placements', ['receipt' => $result['receipt'], 'sha256' => $result['receipt_sha256']])->assertExitCode(0);
            $this->assertDatabaseCount('census_catalogue_rows', 71);
            $this->assertSame($prior['edition_id'], (int) DB::table('census_publications')->where('source_key', $this->sourceKey())->value('edition_id'));
            $this->assertSame('draft', DB::table('census_editions')->where('id', $result['edition_id'])->value('status'));
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(25, $retry['publication']['new_observation_rows']);
            DB::table('census_publications')->where('source_key', $this->sourceKey())->update(['edition_id' => $prior['edition_id']]);
            try {
                $service->recoverPublication($retry['receipt'], $retry['receipt_sha256']);
                $this->fail('Recovery replaced a later publication pointer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 71);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_public_scope_dates_repeats_warnings_and_unassigned_levels_are_readable(): void
    {
        [$directory, $prior, $package, $service] = $this->baseline(true);
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
            $edition = $result['edition_id'];
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'NORTH-EAST FRONTIER AGENCY*']))
                ->assertOk()->assertSee('1 matching source records')->assertSee('38,705')->assertSee('Unassigned in source review')
                ->assertSee('Printed covered portion only')->assertSee('SOURCE AREA')->assertSee('Age group: All ages')->assertSee('Original education row 1');
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'GOA, DAMAN AND DIU']))
                ->assertOk()->assertSee('2 matching source records')->assertSee('1960-12-15')->assertSee('Physical page 117')->assertSee('Physical page 129');
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'DADRA AND NAGAR HAVELI']))
                ->assertOk()->assertSee('1962-03-01')->assertSee('difference 1');
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'TRIPURA', 'field' => 'TOT_F']))
                ->assertOk()->assertSee('550,768')->assertSee('difference 200');
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'level' => 'STUDY ZONE']))
                ->assertOk()->assertSee('3 matching source records')->assertSee('not an administrative or politically constituted zone');
            $this->get(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'INDIA*', 'field' => 'MATRIC_AND_ABOVE_F']))
                ->assertOk()->assertSee('12 matching source records')->assertSee('Original dot notation: ..')->assertSee('Not reported');
            $this->getJson(route('census-catalogue.index', ['edition' => $edition, 'original_area' => 'ASSAM']))->assertStatus(422);
            $rows = DB::table('census_catalogue_rows')->where('edition_id', $edition)->get();
            $this->assertSame(22, $rows->where('level', 'SOURCE AREA')->count());
            $this->assertSame(3, $rows->where('level', 'STUDY ZONE')->count());
            $bihar = $rows->firstWhere('record_key', '8d09c4996b1f79aa353676194e905b5baf8d9c84b90e985fadb37583e04d8e08');
            $this->assertStringContainsString('32022|1961|C-III Part A|32022:C-III-A:119:3', json_decode($bihar->geography, true)['source_record_identity']);
            $verified = $service->verify($package, hash_file('sha256', $package));
            foreach (array_slice($verified['rows'], 17) as $row) {
                $this->assertNull($row['original_level']);
                $this->assertNull($row['parent_original_name']);
                $this->assertNull($row['modern_LGD_identifiers']);
            }
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_package_pins_reject_wrong_adapters_rehashed_tampering_and_check_writes_nothing(): void
    {
        $package = $this->package('national-original-education-source-placements-1961-v3.zip');
        foreach ([new OriginalHistoricalEducationPackage, new OriginalNationalEducationPackage, new OriginalNationalEducationExpansionPackage] as $adapter) {
            $this->assertRejected($adapter, $package);
        }
        $this->assertRejected(new OriginalNationalSourceEducationPackage, $this->package('national-original-education-expansion-1961-v2.zip'));
        foreach (['value', 'marker', 'date', 'classification', 'flag', 'manifest', 'extra'] as $change) {
            $path = tempnam(sys_get_temp_dir(), 'source-education-');
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
                    $index = array_search('NORTH-EAST FRONTIER AGENCY*', array_column($payload['rows'], 'original_name'), true);
                    if ($change === 'value') {
                        $payload['rows'][$index]['values']['TOT_P'] += 297853;
                    } elseif ($change === 'marker') {
                        $payload['rows'][$index]['raw_left_heading'] = 'NORTH-EAST FRONTIER AGENCY';
                    } elseif ($change === 'date') {
                        $payload['rows'][$index]['enumeration_date'] = '1961-03-01';
                    } elseif ($change === 'classification') {
                        $payload['rows'][$index]['original_level'] = 'STATE';
                    } else {
                        $payload['rows'][$index]['flags'] = [];
                    }
                    $raw = json_encode($payload);
                    $zip->addFromString('education-population.json', $raw);
                    $manifest = json_decode($zip->getFromName('manifest.json'), true);
                    $manifest['files']['education-population.json'] = hash('sha256', $raw);
                    $zip->addFromString('manifest.json', json_encode($manifest));
                }
                $zip->close();
                $this->assertRejected(new OriginalNationalSourceEducationPackage, $path);
            } finally {
                unlink($path);
            }
        }
        $this->artisan('census:import-national-original-education-source-placements', ['package' => $package, 'sha256' => hash_file('sha256', $package), '--check' => true])->assertExitCode(0);
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_existing_collection_lock_prevents_import_without_new_records(): void
    {
        [$directory, $prior, $package, $service] = $this->baseline(true);
        $lock = Cache::lock('census-national-collection', 3600);
        $this->assertTrue($lock->get());
        try {
            try {
                $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $this->retrievedAt(), true, $prior['edition_id']);
                $this->fail('Active collection lock was ignored.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 29);
            $this->assertDatabaseCount('census_editions', 2);
        } finally {
            $lock->release();
            $this->cleanup($directory);
        }
    }

    private function baseline(bool $expanded): array
    {
        $directory = sys_get_temp_dir().'/source-education-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $first = $this->package('national-original-education-india-1961-v1.zip');
        $prior = app(OriginalNationalEducationPackage::class)->importDraftPackage($first, hash_file('sha256', $first), $directory, $this->retrievedAt(), true, 0);
        if ($expanded) {
            $second = $this->package('national-original-education-expansion-1961-v2.zip');
            $prior = app(OriginalNationalEducationExpansionPackage::class)->importDraftPackage($second, hash_file('sha256', $second), $directory, $this->retrievedAt(), true, $prior['edition_id']);
        }

        return [$directory, $prior, $this->package('national-original-education-source-placements-1961-v3.zip'), app(OriginalNationalSourceEducationPackage::class)];
    }

    private function package(string $name): string
    {
        $path = base_path('../exports/historical-1961-20261001/'.$name);
        if (! is_file($path)) {
            $this->markTestSkipped('Preserved reviewed original package absent.');
        }

        return $path;
    }

    private function sourceKey(): string
    {
        return 'census-original-education-32022-1961-c3a';
    }

    private function retrievedAt(): string
    {
        return '2026-10-02T07:25:32.066512+00:00';
    }

    private function assertRejected(OriginalHistoricalEducationPackage $service, string $path): void
    {
        try {
            $service->verify($path, hash_file('sha256', $path));
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
