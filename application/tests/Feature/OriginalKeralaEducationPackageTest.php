<?php

namespace Tests\Feature;

use App\Services\OriginalHistoricalEducationPackage;
use App\Services\OriginalKeralaEducationPackage;
use App\Services\OriginalNationalEducationPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OriginalKeralaEducationPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewed_import_exact_counts_errata_and_other_source_preservation_on_retry_and_recovery(): void
    {
        $package = $this->reviewedPackage();
        $service = app(OriginalKeralaEducationPackage::class);
        $verified = $service->verify($package, hash_file('sha256', $package));
        $directory = $this->temporaryDirectory();
        try {
            $national = base_path('../exports/historical-1961-20261001/national-original-education-india-1961-v1.zip');
            $other = app(OriginalNationalEducationPackage::class)->importDraftPackage($national, hash_file('sha256', $national), $directory, '2026-10-02T07:25:32.066512+00:00', true, 0);
            $priorRows = DB::table('census_catalogue_rows')->where('edition_id', $other['edition_id'])->get()->all();
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $this->assertSame(0, $result['before_rows']);
            $this->assertSame(359, $result['after_rows']);
            $this->assertSame(359, $result['added_rows']);
            $this->assertSame(0, $result['corrected_rows']);
            $this->assertSame(4769, $result['publication']['new_numeric_values']);
            $this->assertSame(1580, $result['verified_statistics']['original_dot_null_cells']);
            $this->assertSame(33, $result['verified_statistics']['field_definitions']);
            $this->assertSame(2, $result['verified_statistics']['official_errata_effective_cells']);
            $this->assertSame(3, $result['verified_statistics']['printed_source_discrepancies']);
            $this->assertSame(1, $result['verified_statistics']['excluded_damaged_partial_rows']);
            $rows = DB::table('census_catalogue_rows')->where('edition_id', $result['edition_id'])->get();
            $this->assertCount(359, $rows->pluck('record_key')->unique());
            $this->assertSame(['All Areas' => 119, 'Urban' => 120, 'Rural' => 120], $rows->countBy('residence')->all());
            $urban = $rows->first(fn ($row) => $row->name === 'KERALA STATE — All ages' && $row->residence === 'Urban');
            $values = json_decode($urban->values, true);
            $this->assertSame(1282759, $values['TOT_M']);
            $this->assertSame(133228, $values['PRIMARY_F']);
            $this->assertArrayHasKey('MATRIC_HIGHER_SECONDARY_F', $values);
            $this->assertArrayNotHasKey('MATRIC_AND_ABOVE_F', $values);
            $this->assertSame(1, json_decode($urban->geography, true)['original_block_ordinal']);
            $this->assertSame('Not mapped', json_decode($urban->geography, true)['modern_LGD_mapping']);
            $this->assertNull($rows->first(fn ($row) => $row->name === 'KERALA — 30-34' && $row->residence === 'All Areas'));
            $quilon = $rows->first(fn ($row) => $row->name === 'QUILON DISTRICT — Age not stated' && $row->residence === 'Rural');
            $this->assertSame(8, json_decode($quilon->values, true)['MATRIC_AND_ABOVE_F']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(359, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['publication']['new_numeric_values']);
            $this->assertEquals($priorRows, DB::table('census_catalogue_rows')->where('edition_id', $other['edition_id'])->get()->all());
            $recovery = $service->recoverPublication($result['receipt'], $result['receipt_sha256']);
            $this->assertSame(0, $recovery['deleted_rows']);
            $this->assertNull($recovery['restored_publication_id']);
            $this->assertDatabaseCount('census_catalogue_rows', 371);
            $this->assertDatabaseHas('census_publications', ['edition_id' => $other['edition_id']]);
            $republished = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            DB::table('census_publications')->where('source_key', $verified['manifest']['source_key'])->update(['edition_id' => null]);
            try {
                $service->recoverPublication($republished['receipt'], $republished['receipt_sha256']);
                $this->fail('Recovery overwrote a changed pointer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertDatabaseCount('census_catalogue_rows', 371);
            $this->artisan('census:import-kerala-original-education', ['package' => $package, 'sha256' => hash_file('sha256', $package), '--check' => true])->assertExitCode(0);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_public_source_filters_show_raw_and_corrected_cells_nulls_and_residence_classifications(): void
    {
        $package = $this->reviewedPackage();
        $service = app(OriginalKeralaEducationPackage::class);
        $verified = $service->verify($package, hash_file('sha256', $package));
        $directory = $this->temporaryDirectory();
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $verified['retrieved_at'], true, 0);
            $query = ['edition' => $result['edition_id'], 'original_area' => 'KERALA STATE', 'residence' => 'Urban'];
            $this->get(route('census-catalogue.index', $query + ['field' => 'TOT_M']))->assertOk()
                ->assertSee('12 matching source records')->assertSee('Original source geography')
                ->assertSee('original printed value 1,282,579; value after official correction 1,282,759')
                ->assertSee('Official errata physical page 2, target printed page 32, column 3')
                ->assertSee('Physical page 48, printed page 32')->assertSee('Not mapped');
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'QUILON DISTRICT', 'residence' => 'Rural', 'field' => 'MATRIC_AND_ABOVE_F']))
                ->assertOk()->assertSee('Matriculation and above')
                ->assertSee('original printed value 3; value after official correction 8')
                ->assertSee('Official errata physical page 2, target printed page 42, column 12');
            $this->get(route('census-catalogue.index', $query + ['field' => 'TECHNOLOGY_F']))->assertOk()
                ->assertSee('Not reported')->assertSee('Original dot notation: ..')->assertSee('Physical page 49, printed page 33');
            $this->get(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'KERALA', 'residence' => 'All Areas', 'field' => 'MATRIC_HIGHER_SECONDARY_F']))
                ->assertOk()->assertSee('11 matching source records')->assertSee('Matriculation or Higher Secondary')
                ->assertSee('Not applicable to this source table')->assertSee('Damaged All Areas state30-34');
            $this->getJson(route('census-catalogue.index', ['edition' => $result['edition_id'], 'original_area' => 'Modern Kerala']))->assertStatus(422);
        } finally {
            $this->cleanup($directory);
        }
    }

    public function test_wrong_source_manifest_altered_cells_errata_and_extra_members_are_rejected(): void
    {
        $original = $this->reviewedPackage();
        foreach ([new OriginalHistoricalEducationPackage, new OriginalNationalEducationPackage] as $adapter) {
            $this->assertRejected($adapter, $original);
        }
        foreach (['value', 'errata', 'manifest', 'extra'] as $change) {
            $path = tempnam(sys_get_temp_dir(), 'kerala-education-');
            copy($original, $path);
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
                    $index = array_search('28296:1961:C-III-B:48:1:KERALA STATE:Urban:All ages', array_column($payload['rows'], 'source_record_identity'), true);
                    if ($change === 'value') {
                        $payload['rows'][$index]['values']['PRIMARY_F'] = 183228;
                    } else {
                        $payload['rows'][$index]['value_evidence']['TOT_M']['official_correction'] = null;
                    }
                    $zip->addFromString('education-population.json', json_encode($payload));
                }
                $zip->close();
                $this->assertRejected(new OriginalKeralaEducationPackage, $path);
            } finally {
                unlink($path);
            }
        }
        $this->assertDatabaseCount('census_editions', 0);
    }

    public function test_portable_storage_keeps_age_and_block_identity_without_modern_geography_mapping(): void
    {
        $directory = $this->temporaryDirectory();
        $pdf = '%PDF-1.4 portable education fixture';
        $retrieved = '2026-10-02T07:26:24+00:00';
        $manifest = ['source_key' => 'census-original-education-28296-1961-c3', 'year' => 1961, 'catalogue' => '28296',
            'original_sha256' => hash('sha256', $pdf), 'original' => 'original/test.pdf',
            'source_url' => 'https://censusindia.gov.in/nada/index.php/catalog/28296/download/31478/23004_1961_CUI.pdf',
            'boundary_basis' => 'Original source geography', 'scope' => 'Portable test'];
        $row = ['record_key' => hash('sha256', 'source:page:block:age'), 'original_name' => 'QUILON DISTRICT', 'original_level' => 'DISTRICT',
            'original_age_group' => 'Age not stated', 'original_row_position_within_geography' => 12, 'original_block_ordinal' => 3,
            'parent_original_name' => 'KERALA STATE', 'table' => 'C-III Part C', 'source_record_identity' => 'source:page:block:age',
            'residence' => 'Rural', 'values' => ['MATRIC_AND_ABOVE_F' => null], 'flags' => ['Original dots remain NULL']];
        $verified = ['manifest' => $manifest, 'rows' => [$row], 'retrieved_at' => $retrieved,
            'fields' => ['MATRIC_AND_ABOVE_F' => ['original_label' => 'Matriculation and above', 'sex' => 'F', 'residence' => 'Rural', 'universe' => 'Original age']], 'notes' => []];
        $package = $directory.'/portable.zip';
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE);
        $zip->addFromString($manifest['original'], $pdf);
        $zip->close();
        $service = Mockery::mock(OriginalKeralaEducationPackage::class)->makePartial();
        $service->shouldReceive('verify')->andReturn($verified);
        try {
            $result = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $retrieved, true, 0);
            $stored = DB::table('census_catalogue_rows')->first();
            $this->assertSame('QUILON DISTRICT — Age not stated', $stored->name);
            $this->assertSame(3, json_decode($stored->geography, true)['original_block_ordinal']);
            $this->assertSame('Not mapped', json_decode($stored->geography, true)['modern_LGD_mapping']);
            $this->assertNull(json_decode($stored->values, true)['MATRIC_AND_ABOVE_F']);
            $retry = $service->importDraftPackage($package, hash_file('sha256', $package), $directory, $retrieved, true);
            $this->assertSame(1, $retry['unchanged_rows']);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(0, $result['publication']['new_numeric_values']);
        } finally {
            $this->cleanup($directory);
        }
    }

    private function assertRejected(OriginalHistoricalEducationPackage $adapter, string $path): void
    {
        try {
            $adapter->verify($path, hash_file('sha256', $path));
            $this->fail('Unreviewed evidence was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    private function reviewedPackage(): string
    {
        $path = base_path('../exports/historical-1961-20261001/kerala-original-education-1961-v1.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Reviewed Kerala package absent; portable storage test still runs.');
        }

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/kerala-education-'.bin2hex(random_bytes(8));
        mkdir($directory);

        return $directory;
    }

    private function cleanup(string $directory): void
    {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
