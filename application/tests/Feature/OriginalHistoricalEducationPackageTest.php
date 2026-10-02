<?php

namespace Tests\Feature;

use App\Services\HistoricalCensusPackage;
use App\Services\OriginalHistoricalEducationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalHistoricalEducationPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_backup_retry_and_null_counts_with_portable_storage_fixture(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(0, $result['before_rows']);
            $this->assertSame(2, $result['after_rows']);
            $this->assertSame(2, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame(6, $result['publication']['new_numeric_values']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $baseline = json_decode(file_get_contents($result['baseline_backup']), true);
            $this->assertSame([], $baseline['rows']);
            $this->assertSame([], $baseline['publications']);
            $stored = DB::table('census_catalogue_rows')->where('residence', 'Urban')->first();
            $this->assertNull(json_decode($stored->values, true)['ED_A_ENGINEERING_F']);
            $this->assertSame('TERRITORY', $stored->level);
            $this->assertSame('000', $stored->district_code);
            $this->assertStringStartsWith('O', $stored->state_code);
            $this->assertStringContainsString('not Census or LGD', $stored->geography);
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(2, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_numeric_values']);
            $this->assertDatabaseCount('import_runs', 1);
            $this->assertDatabaseCount('census_catalogue_rows', 2);
            $this->assertDatabaseCount('census_catalogue_reviews', 1);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'ED_A_ENGINEERING_F']))
                ->assertOk()->assertSee('Original education categories')->assertSee('Engineering')
                ->assertSee('Not reported')->assertSee('Not applicable to this source table')
                ->assertSee('Original ellipsis')->assertSee('Physical page 168, printed page 164')
                ->assertSee('Official original PDF');
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_conflicting_publication_rolls_back_the_edition_and_preserves_existing_rows(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $priorRun = app(HistoricalCensusPackage::class)->createRun([
                'source' => ['key' => $verified['manifest']['source_key'], 'year' => 1961,
                    'original_sha256' => str_repeat('a', 64), 'source_url' => $verified['manifest']['source_url']],
                'evidence' => ['prior_source' => true], 'rows' => [],
            ], $package);
            $existing = DB::table('census_editions')->insertGetId([
                'import_run_id' => $priorRun,
                'source_key' => $verified['manifest']['source_key'], 'name' => 'Prior source edition',
                'year' => 1961, 'status' => 'published', 'sha256' => str_repeat('a', 64),
                'source_url' => $verified['manifest']['source_url'], 'fields' => '[]', 'row_count' => 0, 'flag_count' => 0,
                'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/30470',
                'scope' => 'Prior source evidence', 'retrieved_at' => $verified['retrieved_at'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('census_publications')->insert(['source_key' => $verified['manifest']['source_key'], 'edition_id' => $existing]);
            try {
                $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
                $this->fail('Publication conflict was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_editions', 1);
            $this->assertDatabaseCount('census_catalogue_rows', 0);
            $this->assertDatabaseCount('import_runs', 1);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $existing]);
            $this->assertDatabaseHas('census_editions', ['id' => $existing, 'status' => 'published']);
            $backups = glob($directory.'/*-before-*.json');
            $this->assertCount(1, $backups);
            $this->assertSame($existing, json_decode(file_get_contents($backups[0]), true)['publications'][0]['edition_id']);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_duplicate_retry_rejects_storage_drift_without_correcting_it(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at']);
            DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->where('residence', 'Urban')
                ->update(['values' => '{"TOT_P":999}']);
            try {
                $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at']);
                $this->fail('Stored drift was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 2);
            $this->assertSame('{"TOT_P":999}', DB::table('census_catalogue_rows')->where('residence', 'Urban')->value('values'));
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_recovery_restores_the_pointer_preserves_rows_and_refuses_a_later_pointer(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $recovery = $service->recoverPublication($result['receipt'], $result['receipt_sha256']);
            $this->assertSame(0, $recovery['deleted_rows']);
            $this->assertNull($recovery['restored_publication_id']);
            $this->assertDatabaseCount('census_catalogue_rows', 2);
            $this->assertDatabaseHas('census_editions', ['id' => $result['edition_id'], 'status' => 'draft']);
            $republished = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(0, $republished['added_rows']);
            DB::table('census_publications')->where('source_key', $verified['manifest']['source_key'])->update(['edition_id' => null]);
            try {
                $service->recoverPublication($republished['receipt'], $republished['receipt_sha256']);
                $this->fail('Recovery overwrote a changed publication pointer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 2);
            $this->assertDatabaseHas('census_editions', ['id' => $result['edition_id'], 'status' => 'published']);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_reviewed_tripura_batch_import_has_exact_counts_and_public_source_definitions(): void
    {
        $package = $this->reviewedPackage();
        $service = app(OriginalHistoricalEducationPackage::class);
        $verified = $service->verify($package, hash_file('sha256', $package));
        $this->assertCount(18, $verified['rows']);
        $this->assertSame(425, $verified['statistics']['reported_integer_cells']);
        $this->assertSame(76, $verified['statistics']['original_ellipsis_null_cells']);
        $directory = sys_get_temp_dir().'/education-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(18, $result['added_rows']);
            $this->assertSame(425, $result['publication']['new_numeric_values']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'ED_A_MEDICINE_P', 'residence' => 'Urban']))
                ->assertOk()->assertSee('Medicine')->assertSee('Urban')->assertSee('Physical page 168')
                ->assertSee('adding both would count the same people twice')->assertSee('XI');
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(18, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 18);
            $this->artisan('census:import-original-education', ['package' => $package, 'sha256' => hash_file('sha256', $package), '--check' => true])->assertExitCode(0);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_rehashed_mutation_of_counts_notes_and_manifest_cannot_override_reviewed_evidence(): void
    {
        $original = $this->reviewedPackage();
        $service = app(OriginalHistoricalEducationPackage::class);
        foreach (['cell', 'notes', 'manifest', 'extra'] as $change) {
            $path = tempnam(sys_get_temp_dir(), 'education-');
            copy($original, $path);
            try {
                $zip = new ZipArchive;
                $zip->open($path);
                if ($change === 'manifest') {
                    $manifest = json_decode($zip->getFromName('manifest.json'), true);
                    $manifest['boundary_basis'] = 'Modern LGD geography';
                    $zip->addFromString('manifest.json', json_encode($manifest));
                } elseif ($change === 'extra') {
                    $zip->addFromString('../unexpected.txt', 'unexpected');
                } else {
                    $data = json_decode($zip->getFromName('education-population.json'), true);
                    if ($change === 'cell') {
                        $data['rows'][0]['values']['TOT_P'] = 1;
                    } else {
                        $data['rows'][0]['flags'] = [];
                    }
                    $zip->addFromString('education-population.json', json_encode($data));
                }
                $zip->close();
                try {
                    $service->verify($path, hash_file('sha256', $path));
                    $this->fail('Rehashed mutation was accepted: '.$change);
                } catch (HttpException $error) {
                    $this->assertSame(422, $error->getStatusCode());
                }
            } finally {
                unlink($path);
            }
        }
    }

    private function reviewedPackage(): string
    {
        $path = base_path('../exports/historical-1961-20261001/tripura-original-education-population-1961-v2.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Reviewed original Tripura batch unavailable. Portable storage tests still run.');
        }

        return $path;
    }

    /** Synthetic evidence isolates transaction/storage behavior; verification is mocked only in these portable tests. */
    private function storageFixture(): array
    {
        $directory = sys_get_temp_dir().'/education-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $pdf = '%PDF-1.4 portable storage test';
        $retrieved = '2026-10-01T15:46:41+00:00';
        $manifest = ['source_key' => 'census-original-education-30470-1961', 'year' => 1961,
            'catalogue' => '30470', 'original_sha256' => hash('sha256', $pdf), 'original' => 'original/test.pdf',
            'source_url' => 'https://censusindia.gov.in/nada/index.php/catalog/30470/download/33651/24040_1961_GPET.pdf',
            'boundary_basis' => 'Original source geography', 'scope' => 'Population columns only'];
        $fields = [];
        foreach (['TOT_P', 'TOT_M', 'TOT_F', 'ED_A_ENGINEERING_F'] as $field) {
            $fields[$field] = ['original_label' => $field === 'ED_A_ENGINEERING_F' ? 'Engineering' : 'Total',
                'sex' => substr($field, -1), 'residence_scheme' => 'Urban', 'universe' => 'Whole source population', 'parent_field' => null];
        }
        $rows = [];
        foreach (['Urban', 'Rural'] as $residence) {
            $key = hash('sha256', $residence);
            $values = ['TOT_P' => 7, 'TOT_M' => 5, 'TOT_F' => 2];
            if ($residence === 'Urban') {
                $values['ED_A_ENGINEERING_F'] = null;
            }
            $rows[] = ['record_key' => $key, 'original_name' => 'TRIPURA', 'original_level' => 'TERRITORY',
                'parent_original_name' => null, 'table' => 'B-III Part A', 'source_record_identity' => 'fixture:'.$residence,
                'residence' => $residence, 'values' => $values, 'flags' => ['Original ellipsis retained'],
                'value_evidence' => ['ED_A_ENGINEERING_F' => ['original_table' => 'B-III Part A', 'source_row_sequence' => 10,
                    'physical_page' => 168, 'printed_page' => 164, 'source_column' => 4, 'missing_cell_notation' => '...']]];
        }
        $verified = ['manifest' => $manifest, 'rows' => $rows, 'fields' => $fields, 'notes' => ['Different original residence definitions'], 'retrieved_at' => $retrieved];
        $package = $directory.'/portable.zip';
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE);
        $zip->addFromString($manifest['original'], $pdf);
        $zip->close();
        $service = Mockery::mock(OriginalHistoricalEducationPackage::class)->makePartial();
        $service->shouldReceive('verify')->andReturn($verified);

        return [$service, $package, $verified, $directory];
    }

    private function cleanup(string $directory): void
    {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
