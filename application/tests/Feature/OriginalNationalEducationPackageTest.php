<?php

namespace Tests\Feature;

use App\Services\OriginalHistoricalEducationPackage;
use App\Services\OriginalNationalEducationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalNationalEducationPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_age_identity_nulls_notes_and_source_locator_survive_portable_storage_and_retry(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $this->assertSame(2, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame(1, $result['publication']['new_numeric_values']);
            $stored = DB::table('census_catalogue_rows')->orderBy('source_row')->get();
            $this->assertSame('INDIA* — All ages', $stored[0]->name);
            $this->assertSame('INDIA* — 0-4', $stored[1]->name);
            $this->assertSame('0-4', json_decode($stored[1]->geography, true)['original_age_group']);
            $this->assertSame('Not mapped', json_decode($stored[1]->geography, true)['modern_LGD_mapping']);
            $this->assertSame('All Areas', $stored[1]->residence);
            $this->assertNull(json_decode($stored[1]->values, true)['MATRIC_AND_ABOVE_F']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'MATRIC_AND_ABOVE_F']))
                ->assertOk()->assertSee('Age group: 0-4')->assertSee('Original dot notation: ..')
                ->assertSee('Original age label')->assertSee('Physical page 116, printed page 109')
                ->assertSee('Printed discrepancy retained')->assertSee('Not reported');
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(2, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_numeric_values']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $this->assertDatabaseCount('census_catalogue_rows', 2);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_recovery_preserves_age_rows_and_refuses_a_changed_pointer(): void
    {
        [$service, $package, $verified, $directory] = $this->storageFixture();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $recovered = $service->recoverPublication($result['receipt'], $result['receipt_sha256']);
            $this->assertSame(0, $recovered['deleted_rows']);
            $this->assertNull($recovered['restored_publication_id']);
            $this->assertDatabaseCount('census_catalogue_rows', 2);
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            DB::table('census_publications')->where('source_key', $verified['manifest']['source_key'])->update(['edition_id' => null]);
            try {
                $service->recoverPublication($retry['receipt'], $retry['receipt_sha256']);
                $this->fail('Recovery overwrote a changed pointer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 2);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_reviewed_national_package_import_has_exact_counts_and_duplicate_protection(): void
    {
        $package = $this->reviewedPackage();
        $service = app(OriginalNationalEducationPackage::class);
        $verified = $service->verify($package, hash_file('sha256', $package));
        $directory = sys_get_temp_dir().'/national-education-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $this->assertSame(0, $result['before_rows']);
            $this->assertSame(12, $result['after_rows']);
            $this->assertSame(12, $result['added_rows']);
            $this->assertSame(124, $result['publication']['new_numeric_values']);
            $this->assertSame(8, $result['verified_statistics']['original_dot_null_cells']);
            $this->assertSame(4, $result['verified_statistics']['arithmetic_discrepancies']);
            $this->assertCount(12, array_unique(DB::table('census_catalogue_rows')->pluck('record_key')->all()));
            $female = DB::table('census_catalogue_rows')->where('name', 'INDIA* — 20-24')->first();
            $this->assertSame(19133698, json_decode($female->values, true)['TOT_F']);
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'field' => 'TOT_F']))
                ->assertOk()->assertSee('INDIA* — 20-24')->assertSee('difference -5000')
                ->assertSee('Physical page 115, printed page 108')->assertSee('NEFA');
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true);
            $this->assertSame(12, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertDatabaseCount('census_catalogue_rows', 12);
            $this->artisan('census:import-national-original-education', ['package' => $package, 'sha256' => hash_file('sha256', $package), '--check' => true])->assertExitCode(0);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_tripura_adapter_rejects_national_package_and_national_adapter_rejects_tripura(): void
    {
        $national = $this->reviewedPackage();
        $tripura = base_path('../exports/historical-1961-20261001/tripura-original-education-population-1961-v2.zip');
        if (! is_file($tripura)) {
            $this->markTestSkipped('Reviewed Tripura original package absent.');
        }
        foreach ([[new OriginalHistoricalEducationPackage, $national], [new OriginalNationalEducationPackage, $tripura]] as [$service, $package]) {
            try {
                $service->verify($package, hash_file('sha256', $package));
                $this->fail('Another source was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('census_editions', 0);
    }

    public function test_altered_original_counts_notes_or_manifest_and_extra_members_are_rejected(): void
    {
        $original = $this->reviewedPackage();
        foreach (['value', 'notes', 'manifest', 'extra'] as $change) {
            $path = tempnam(sys_get_temp_dir(), 'national-education-');
            copy($original, $path);
            try {
                $zip = new ZipArchive;
                $zip->open($path);
                if ($change === 'extra') {
                    $zip->addFromString('../extra.txt', 'extra');
                } elseif ($change === 'manifest') {
                    $manifest = json_decode($zip->getFromName('manifest.json'), true);
                    $manifest['scope'] = 'All India complete';
                    $zip->addFromString('manifest.json', json_encode($manifest));
                } else {
                    $payload = json_decode($zip->getFromName('education-population.json'), true);
                    if ($change === 'value') {
                        $payload['rows'][0]['values']['TOT_P'] += 297853;
                    } else {
                        $payload['rows'][5]['flags'] = [];
                    }
                    $zip->addFromString('education-population.json', json_encode($payload));
                }
                $zip->close();
                try {
                    app(OriginalNationalEducationPackage::class)->verify($path, hash_file('sha256', $path));
                    $this->fail('Altered evidence was accepted.');
                } catch (HttpException $error) {
                    $this->assertSame(422, $error->getStatusCode());
                }
            } finally {
                unlink($path);
            }
        }
    }

    /** Portable evidence isolates age/storage semantics; original verification is tested separately. */
    private function storageFixture(): array
    {
        $directory = sys_get_temp_dir().'/national-education-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $pdf = '%PDF-1.4 portable national fixture';
        $retrieved = '2026-10-02T07:25:32+00:00';
        $manifest = ['source_key' => 'census-original-education-32022-1961-c3a', 'year' => 1961, 'catalogue' => '32022',
            'original_sha256' => hash('sha256', $pdf), 'original' => 'original/test.pdf',
            'source_url' => 'https://censusindia.gov.in/nada/index.php/catalog/32022/download/35203/22949_1961_SCT.pdf',
            'boundary_basis' => 'Original INDIA* geography', 'scope' => 'Original All Areas age subset'];
        $rows = [];
        foreach (['All ages', '0-4'] as $index => $age) {
            $rows[] = ['record_key' => hash('sha256', $age), 'original_name' => 'INDIA*', 'original_level' => 'NATIONAL AGGREGATE',
                'original_age_group' => $age, 'original_row_position_within_geography' => $index + 1,
                'parent_original_name' => null, 'table' => 'C-III Part A', 'source_record_identity' => 'fixture:'.$age,
                'residence' => 'All Areas', 'values' => ['MATRIC_AND_ABOVE_F' => $index ? null : 0],
                'flags' => ['Printed discrepancy retained'],
                'value_evidence' => ['MATRIC_AND_ABOVE_F' => ['original_table' => 'C-III Part A',
                    'original_age_group' => $age, 'original_row_position_within_geography' => $index + 1,
                    'physical_page' => 116, 'printed_page' => 109, 'source_column' => 12, 'missing_cell_notation' => $index ? '..' : null]]];
        }
        $verified = ['manifest' => $manifest, 'rows' => $rows, 'retrieved_at' => $retrieved,
            'fields' => ['MATRIC_AND_ABOVE_F' => ['original_label' => 'Matriculation and above', 'sex' => 'F',
                'residence' => 'All Areas', 'age_group' => 'Original age label', 'universe' => 'Original source population']],
            'notes' => ['Original age categories retained']];
        $package = $directory.'/portable.zip';
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE);
        $zip->addFromString($manifest['original'], $pdf);
        $zip->close();
        $service = Mockery::mock(OriginalNationalEducationPackage::class)->makePartial();
        $service->shouldReceive('verify')->andReturn($verified);

        return [$service, $package, $verified, $directory];
    }

    private function reviewedPackage(): string
    {
        $path = base_path('../exports/historical-1961-20261001/national-original-education-india-1961-v1.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Reviewed national original absent. Portable storage tests still run.');
        }

        return $path;
    }

    private function cleanup(string $directory): void
    {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
