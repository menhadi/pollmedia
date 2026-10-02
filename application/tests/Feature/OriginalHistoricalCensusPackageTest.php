<?php

namespace Tests\Feature;

use App\Services\OriginalHistoricalCensusPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalHistoricalCensusPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_nine_district_revision_retains_reported_discrepancy_and_exact_retry_counts(): void
    {
        $path = base_path('../exports/historical-1961-20261001/kerala-original-pca-evidence-1961-v7.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Nine-district original evidence unavailable.');
        }
        $service = app(OriginalHistoricalCensusPackage::class);
        $data = $service->verify($path, hash_file('sha256', $path));
        $this->assertCount(30, $data['rows']);
        $this->assertSame(1428, array_sum(array_map(fn (array $row): int => count($row['values']), $data['rows'])));
        $this->assertSame('I', $data['rows'][3]['original_serial']);
        $this->assertSame(932007, $data['rows'][22]['values']['NON_WORK_P']);
        $this->assertSame(398588, $data['rows'][22]['values']['NON_WORK_M']);
        $this->assertSame(583419, $data['rows'][22]['values']['NON_WORK_F']);
        $this->assertStringContainsString('932,007', implode(' ', $data['rows'][22]['flags']));
        $this->assertSame('TRIVANDRUM DISTRICT', $data['rows'][27]['original_name']);
        $this->assertSame('540166.42', $data['rows'][27]['values']['AREA_ACRES']);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $pilot = dirname($path).DIRECTORY_SEPARATOR.'kerala-original-pca-evidence-1961-v5.zip';
            $prior = $service->importDraftPackage($pilot, hash_file('sha256', $pilot), $directory, $data['retrieved_at'], true);
            $result = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior['edition_id']);
            $this->assertSame(12, $result['before_rows']);
            $this->assertSame(42, $result['after_rows']);
            $this->assertSame(30, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame(18, $result['publication']['new_observation_rows']);
            $this->assertSame(12, $result['publication']['retained_observation_rows']);
            $this->assertSame(864, $result['publication']['new_numeric_values']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'NON_WORK_P']))
                ->assertOk()->assertSee('932,007')->assertSee('50,000');
            $retry = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior['edition_id']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(30, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_observation_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 42);
            $this->assertDatabaseHas('census_editions', ['id' => $prior['edition_id'], 'status' => 'superseded']);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_nine_district_warning_and_digit_evidence_cannot_be_dropped(): void
    {
        $original = base_path('../exports/historical-1961-20261001/kerala-original-pca-evidence-1961-v7.zip');
        if (! is_file($original)) {
            $this->markTestSkipped('Nine-district original evidence unavailable.');
        }
        foreach (['warning', 'crop'] as $case) {
            $path = tempnam(sys_get_temp_dir(), 'pca-nine-');
            try {
                copy($original, $path);
                $zip = new ZipArchive;
                $zip->open($path);
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $member = $case === 'warning' ? 'evidence/pca-mapping-audit-20261001T1901.json' : 'evidence/kerala-pca-alleppey-full-verified-candidates.json';
                $evidence = json_decode($zip->getFromName($member), true, 512, JSON_THROW_ON_ERROR);
                if ($case === 'warning') {
                    $evidence['rows'][22]['flags'] = array_values(array_filter($evidence['rows'][22]['flags'], fn (string $flag): bool => ! str_contains($flag, 'Source discrepancy')));
                } else {
                    $evidence['digit_review_render']['sha256'] = str_repeat('0', 64);
                }
                $raw = json_encode($evidence, JSON_THROW_ON_ERROR);
                $manifest['files'][$member] = hash('sha256', $raw);
                $zip->addFromString($member, $raw);
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                $zip->close();
                try {
                    app(OriginalHistoricalCensusPackage::class)->verify($path, hash_file('sha256', $path));
                    $this->fail('Missing source evidence accepted: '.$case);
                } catch (HttpException $error) {
                    $this->assertSame(422, $error->getStatusCode());
                }
            } finally {
                unlink($path);
            }
        }
        $this->assertDatabaseCount('census_catalogue_rows', 0);
    }

    public function test_expanded_verified_districts_preserve_decimal_acreage_and_published_pilot(): void
    {
        $path = $this->expandedPackagePath();
        $service = app(OriginalHistoricalCensusPackage::class);
        $data = $service->verify($path, hash_file('sha256', $path));
        $this->assertCount(24, $data['rows']);
        $this->assertSame('727694.88', $data['rows'][12]['values']['AREA_ACRES']);
        $this->assertSame('TRICHUR DISTRICT', $data['rows'][12]['original_name']);
        $this->assertSame(544439, $data['rows'][12]['values']['TOT_WORK_P']);
        $this->assertSame(18989, $data['rows'][16]['values']['WORK_CATEGORY_IV_M']);
        $this->assertArrayNotHasKey('NON_WORK_P', $data['rows'][18]['values']);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $pilot = dirname($path).DIRECTORY_SEPARATOR.'kerala-original-pca-evidence-1961-v5.zip';
            $prior = $service->importDraftPackage($pilot, hash_file('sha256', $pilot), $directory, $data['retrieved_at'], true);
            $result = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior['edition_id']);
            $this->assertSame(12, $result['before_rows']);
            $this->assertSame(36, $result['after_rows']);
            $this->assertSame(12, $result['publication']['new_observation_rows']);
            $this->assertSame(12, $result['publication']['retained_observation_rows']);
            $this->assertSame(414, $result['publication']['new_numeric_values']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'AREA_ACRES']))
                ->assertOk()->assertSee('Area in acres')->assertSee('727694.88')->assertSee('Not reported');
            $this->get(route('census-catalogue.index', ['edition' => $prior['edition_id']]))
                ->assertOk()->assertSee('Earlier snapshot');
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_expanded_evidence_rejects_identity_page_cell_and_unit_drift(): void
    {
        $original = $this->expandedPackagePath();
        foreach (['identity', 'page', 'cell', 'unit', 'render'] as $case) {
            $path = tempnam(sys_get_temp_dir(), 'pca-expanded-');
            try {
                copy($original, $path);
                $zip = new ZipArchive;
                $zip->open($path);
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $member = $case === 'cell' ? 'evidence/pca-mapping-audit-20261001T1901.json'
                    : 'evidence/kerala-pca-trichur-full-verified-candidates.json';
                $evidence = json_decode($zip->getFromName($member), true, 512, JSON_THROW_ON_ERROR);
                if ($case === 'identity') {
                    $evidence['rows'][0]['original_serial'] = '5';
                } elseif ($case === 'page') {
                    $evidence['physical_pages'][0] = 188;
                } elseif ($case === 'cell') {
                    $evidence['rows'][12]['values']['SC_P']++;
                } elseif ($case === 'unit') {
                    $manifest['measure_units']['AREA_ACRES'] = 'square kilometres';
                } else {
                    $evidence['render_hashes']['182'] = str_repeat('0', 64);
                }
                $raw = json_encode($evidence, JSON_THROW_ON_ERROR);
                $manifest['files'][$member] = hash('sha256', $raw);
                $zip->addFromString($member, $raw);
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                $zip->close();
                try {
                    app(OriginalHistoricalCensusPackage::class)->verify($path, hash_file('sha256', $path));
                    $this->fail('Unverified expansion accepted: '.$case);
                } catch (HttpException $error) {
                    $this->assertSame(422, $error->getStatusCode(), $case);
                }
                $this->assertDatabaseCount('census_editions', 0);
            } finally {
                unlink($path);
            }
        }
    }

    private function expandedPackagePath(): string
    {
        $path = base_path('../exports/historical-1961-20261001/kerala-original-pca-evidence-1961-v6-candidate.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Expanded original PCA evidence package is unavailable.');
        }

        return $path;
    }

    public function test_additive_revision_preserves_prior_snapshot_and_reports_coverage_separately(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $service = app(OriginalHistoricalCensusPackage::class);
            $path = $this->packagePath();
            $data = $service->verify($path, hash_file('sha256', $path));
            $priorData = $data;
            $priorData['rows'] = array_slice($data['rows'], 0, 3);
            $prior = $service->stageVerifiedEdition($this->createRun($priorData), $priorData, $data['retrieved_at']);
            DB::table('census_editions')->where('id', $prior)->update(['status' => 'published']);
            DB::table('census_publications')->insert(['source_key' => $data['manifest']['source_key'], 'edition_id' => $prior]);
            $priorRows = DB::table('census_catalogue_rows')->where('edition_id', $prior)->get()->all();
            $result = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior);
            $this->assertSame(3, $result['before_rows']);
            $this->assertSame(9, $result['after_rows']);
            $this->assertSame(6, $result['added_rows']);
            $this->assertSame(3, $result['publication']['new_observation_rows']);
            $this->assertSame(3, $result['publication']['retained_observation_rows']);
            $this->assertSame(3, $result['publication']['active_before_rows']);
            $this->assertSame(6, $result['publication']['active_after_rows']);
            $this->assertSame(42, $result['publication']['new_numeric_values']);
            $this->assertEquals($priorRows, DB::table('census_catalogue_rows')->where('edition_id', $prior)->get()->all());
            $this->assertDatabaseHas('census_editions', ['id' => $prior, 'status' => 'superseded']);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $result['edition_id']]);
            $backup = json_decode(file_get_contents($result['baseline_backup']), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($prior, $backup['publications'][0]['edition_id']);
            $this->assertSame('published', $backup['editions'][0]['status']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $retry = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(0, $retry['publication']['new_observation_rows']);
            $this->assertSame(6, $retry['publication']['retained_observation_rows']);
            $this->assertDatabaseCount('census_catalogue_reviews', 1);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_revision_rejects_removed_rows_changed_values_identities_notes_and_stale_pointer(): void
    {
        $service = app(OriginalHistoricalCensusPackage::class);
        $path = $this->packagePath();
        $data = $service->verify($path, hash_file('sha256', $path));
        foreach (['row', 'value', 'field', 'identity', 'note', 'source', 'pointer'] as $case) {
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
            mkdir($directory);
            DB::beginTransaction();
            try {
                $priorData = $data;
                if ($case === 'row') {
                    $extra = $priorData['rows'][0];
                    $extra['record_key'] = hash('sha256', 'synthetic-missing-row');
                    $priorData['rows'][] = $extra;
                } elseif ($case === 'value') {
                    $priorData['rows'][0]['values']['P_LIT']++;
                } elseif ($case === 'field') {
                    $priorData['rows'][0]['values']['SC_P'] = 1;
                } elseif ($case === 'identity') {
                    $priorData['rows'][0]['original_name'] = 'Changed historical identity';
                } elseif ($case === 'note') {
                    $priorData['rows'][0]['flags'][] = 'Additional prior source warning';
                } elseif ($case === 'source') {
                    $priorData['manifest']['original_sha256'] = str_repeat('0', 64);
                }
                $prior = $service->stageVerifiedEdition($this->createRun($priorData), $priorData, $data['retrieved_at']);
                DB::table('census_editions')->where('id', $prior)->update(['status' => 'published']);
                DB::table('census_publications')->insert(['source_key' => $data['manifest']['source_key'], 'edition_id' => $prior]);
                try {
                    $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true,
                        $case === 'pointer' ? $prior + 999 : $prior);
                    $this->fail('Unsafe revision was accepted: '.$case);
                } catch (HttpException $error) {
                    $this->assertSame($case === 'pointer' ? 409 : 422, $error->getStatusCode(), $case);
                }
                $this->assertDatabaseCount('census_editions', 1);
                $this->assertDatabaseCount('import_runs', 1);
                $this->assertDatabaseCount('census_catalogue_rows', count($priorData['rows']));
                $this->assertDatabaseCount('census_catalogue_reviews', 0);
                $this->assertDatabaseHas('census_publications', ['edition_id' => $prior]);
                $this->assertDatabaseHas('census_editions', ['id' => $prior, 'status' => 'published']);
            } finally {
                DB::rollBack();
                foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    public function test_retry_rejects_stored_value_drift_even_when_row_count_matches(): void
    {
        $service = app(OriginalHistoricalCensusPackage::class);
        $data = $service->verify($this->packagePath(), hash_file('sha256', $this->packagePath()));
        $run = $this->createRun($data);
        $edition = $service->stageVerifiedEdition($run, $data, $data['retrieved_at']);
        $row = DB::table('census_catalogue_rows')->where('edition_id', $edition)->first();
        $values = json_decode($row->values, true, 512, JSON_THROW_ON_ERROR);
        $values['P_LIT']++;
        DB::table('census_catalogue_rows')->where('id', $row->id)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Existing original PCA stored evidence differs.');
        $service->stageVerifiedEdition($run, $data, $data['retrieved_at']);
    }

    public function test_revision_can_add_measures_without_claiming_new_geography_rows(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $service = app(OriginalHistoricalCensusPackage::class);
            $path = $this->packagePath();
            $data = $service->verify($path, hash_file('sha256', $path));
            $priorData = $data;
            foreach ($priorData['rows'] as &$row) {
                unset($row['values']['No_HH']);
            }
            unset($row);
            $prior = $service->stageVerifiedEdition($this->createRun($priorData), $priorData, $data['retrieved_at']);
            DB::table('census_editions')->where('id', $prior)->update(['status' => 'published']);
            DB::table('census_publications')->insert(['source_key' => $data['manifest']['source_key'], 'edition_id' => $prior]);
            $result = $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true, $prior);
            $this->assertSame(0, $result['publication']['new_observation_rows']);
            $this->assertSame(6, $result['publication']['retained_observation_rows']);
            $this->assertSame(6, $result['publication']['new_numeric_values']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 12);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $result['edition_id']]);
            $priorRow = DB::table('census_catalogue_rows')->where('edition_id', $prior)->first();
            $this->assertArrayNotHasKey('No_HH', json_decode($priorRow->values, true));
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_publication_conflict_rolls_back_new_draft_and_preserves_pointer(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $service = app(OriginalHistoricalCensusPackage::class);
            $path = $this->packagePath();
            $data = $service->verify($path, hash_file('sha256', $path));
            $data['rows'][0]['flags'][] = 'Prior source edition preserved.';
            $run = $this->createRun($data);
            $prior = $service->stageVerifiedEdition($run, $data, $data['retrieved_at']);
            DB::table('census_editions')->where('id', $prior)->update(['status' => 'published']);
            DB::table('census_publications')->insert(['source_key' => $data['manifest']['source_key'], 'edition_id' => $prior]);
            try {
                $service->importDraftPackage($path, hash_file('sha256', $path), $directory, $data['retrieved_at'], true);
                $this->fail('Publication conflict was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_editions', 1);
            $this->assertDatabaseCount('census_catalogue_rows', 6);
            $this->assertDatabaseCount('import_runs', 1);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $prior]);
            $this->assertDatabaseCount('census_catalogue_reviews', 0);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_command_check_mode_writes_no_database_rows(): void
    {
        $path = $this->packagePath();
        $this->artisan('census:import-original-pca', ['package' => $path, 'sha256' => hash_file('sha256', $path), '--check' => true])->assertExitCode(0);
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
    }

    public function test_publication_preserves_notes_and_retry_adds_no_review(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $service = app(OriginalHistoricalCensusPackage::class);
            $path = $this->packagePath();
            $sha = hash_file('sha256', $path);
            $result = $service->importDraftPackage($path, $sha, $directory, '2026-10-01T16:01:44+00:00', true);
            $this->assertTrue($result['published']);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $result['edition_id']]);
            $row = DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->first();
            $this->assertNotEmpty(json_decode($row->flags, true));
            $this->get(route('civic.place', ['record' => $row->id, 'group' => 'literacy']))
                ->assertOk()->assertSee('7,919,220')->assertSee('Historical literacy definition');
            $this->get(route('civic.place', ['record' => $row->id, 'group' => 'households']))
                ->assertOk()->assertSee('Occupied residential houses')->assertSee('2,803,533');
            $this->get(route('civic.place', ['record' => $row->id, 'group' => 'work']))
                ->assertOk()->assertSee('Male cultivators')->assertSee('904,502')->assertSee('Worker categories');
            $service->importDraftPackage($path, $sha, $directory, '2026-10-01T16:01:44+00:00', true);
            $this->assertDatabaseCount('census_catalogue_reviews', 1);
            $this->assertDatabaseCount('census_catalogue_rows', 6);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_import_cannot_replace_official_retrieval_timestamp(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Original PCA retrieval timestamp differs from preserved receipt.');
        $path = $this->packagePath();
        app(OriginalHistoricalCensusPackage::class)->importDraftPackage($path, hash_file('sha256', $path), sys_get_temp_dir(), '2020-01-01T00:00:00Z');
    }

    public function test_draft_import_archives_original_and_records_exact_retry_counts(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pca-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $service = app(OriginalHistoricalCensusPackage::class);
            $path = $this->packagePath();
            $sha = hash_file('sha256', $path);
            $result = $service->importDraftPackage($path, $sha, $directory, '2026-10-01T16:01:44+00:00');
            $this->assertSame(6, $result['added_rows']);
            $this->assertSame(1, $result['added_editions']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $run = DB::table('import_runs')->first();
            $this->assertSame($run->sha256, hash_file('sha256', $run->raw_path));
            $retry = $service->importDraftPackage($path, $sha, $directory, '2026-10-01T16:01:44+00:00');
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(6, $retry['unchanged_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 6);
            $this->assertDatabaseCount('census_publications', 0);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_staging_retries_preserve_source_rows_without_duplicates(): void
    {
        $service = app(OriginalHistoricalCensusPackage::class);
        $data = $service->verify($this->packagePath(), hash_file('sha256', $this->packagePath()));
        $run = $this->createRun($data);
        $edition = $service->stageVerifiedEdition($run, $data, '2026-10-01T16:01:44+00:00');
        $this->assertSame($edition, $service->stageVerifiedEdition($run, $data, '2026-10-01T16:01:44+00:00'));
        $this->assertDatabaseCount('census_editions', 1);
        $this->assertDatabaseCount('census_catalogue_rows', 6);
        $row = DB::table('census_catalogue_rows')->where('edition_id', $edition)->where('name', 'CANNANORE DISTRICT')->where('residence', 'Total')->first();
        $this->assertSame(735038, json_decode($row->values, true)['P_LIT']);
        $this->assertSame(278556, json_decode($row->values, true)['OCCUPIED_HOUSES']);
        $this->assertSame(152971, json_decode($row->values, true)['CULTIVATOR_P']);
        $this->assertStringStartsWith('O', $row->state_code);
        $this->assertStringContainsString('not Census or LGD code', $row->geography);
        $this->assertDatabaseCount('census_publications', 0);
    }

    public function test_mismatched_evidence_creates_no_edition_or_rows(): void
    {
        $service = app(OriginalHistoricalCensusPackage::class);
        $data = $service->verify($this->packagePath(), hash_file('sha256', $this->packagePath()));
        $run = $this->createRun($data);
        $data['rows'][0]['values']['P_LIT']++;
        try {
            $service->stageVerifiedEdition($run, $data, '2026-10-01T16:01:44+00:00');
            $this->fail('Mismatched evidence was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
    }

    private function createRun(array $data): int
    {
        $manifest = $data['manifest'];
        $connector = DB::table('import_connectors')->insertGetId([
            'name' => 'Original PCA test', 'url' => $manifest['source_url'], 'format' => 'json',
            'record_key' => 'source_record_identity', 'options' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('import_runs')->insertGetId([
            'import_connector_id' => $connector, 'status' => 'needs_review', 'origin' => 'upload',
            'source_url' => $manifest['source_url'], 'sha256' => $manifest['original_sha256'],
            'extracted' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function test_inconsistent_source_count_is_retained_with_note(): void
    {
        $this->withChangedPackage(true, function (string $path): void {
            $data = app(OriginalHistoricalCensusPackage::class)->verify($path, hash_file('sha256', $path));
            $this->assertSame(7919221, $data['rows'][0]['values']['P_LIT']);
            $this->assertStringContainsString('Source discrepancy', end($data['rows'][0]['flags']));
        });
    }

    public function test_inconsistent_source_count_without_note_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Original PCA discrepancy lacks a note.');
        $this->withChangedPackage(false, function (string $path): void {
            app(OriginalHistoricalCensusPackage::class)->verify($path, hash_file('sha256', $path));
        });
    }

    public function test_wrong_outer_checksum_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        app(OriginalHistoricalCensusPackage::class)->verify($this->packagePath(), str_repeat('0', 64));
    }

    private function packagePath(): string
    {
        $path = base_path('../exports/historical-1961-20261001/kerala-original-pca-evidence-1961.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Original PCA evidence package is unavailable.');
        }

        return $path;
    }

    private function withChangedPackage(bool $noted, callable $assertion): void
    {
        $path = tempnam(sys_get_temp_dir(), 'original-pca-');
        try {
            copy($this->packagePath(), $path);
            $zip = new ZipArchive;
            $zip->open($path);
            $member = 'evidence/pca-mapping-audit-20261001T1901.json';
            $audit = json_decode($zip->getFromName($member), true, 512, JSON_THROW_ON_ERROR);
            $audit['rows'][0]['values']['P_LIT']++;
            if ($noted) {
                $audit['rows'][0]['flags'][] = 'Source discrepancy: P_LIT differs from M_LIT+F_LIT; reported values preserved.';
            }
            $raw = json_encode($audit, JSON_THROW_ON_ERROR);
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifest['files'][$member] = hash('sha256', $raw);
            $zip->addFromString($member, $raw);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $zip->close();
            $assertion($path);
        } finally {
            unlink($path);
        }
    }

    public function test_original_pca_preserves_names_values_and_notes(): void
    {
        $path = base_path('../exports/historical-1961-20261001/kerala-original-pca-evidence-1961.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Original PCA evidence package is unavailable.');
        }
        $data = app(OriginalHistoricalCensusPackage::class)->verify($path, hash_file('sha256', $path));
        $this->assertCount(6, $data['rows']);
        $this->assertSame('CANNANORE DISTRICT', $data['rows'][3]['original_name']);
        $this->assertSame(7919220, $data['rows'][0]['values']['P_LIT']);
        $this->assertSame(2803533, $data['rows'][0]['values']['OCCUPIED_HOUSES']);
        $this->assertSame(904502, $data['rows'][0]['values']['CULTIVATOR_M']);
        $this->assertNotEmpty($data['rows'][0]['flags']);
    }
}
