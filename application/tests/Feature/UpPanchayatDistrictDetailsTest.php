<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpPanchayatDistrictDetailsTest extends TestCase
{
    private string $root;

    private array $index;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 15:00:00');
        $this->root = sys_get_temp_dir().'/up-egs-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $base = ['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => []];
        file_put_contents($this->root.'/base.json', json_encode($base));
        $this->index = ['schema' => 1, 'publication_enabled' => true, 'districts' => []];
        foreach (['130' => ['69232', '133001'], '180' => ['86931', '134331']] as $district => [$gp, $village]) {
            $district = (string) $district;
            mkdir($this->root.'/9-'.$district);
            $report = ['name' => 'Verified GP', 'panchayat_code' => $gp, 'financial_year' => '2026-2027',
                'mapping' => ['verified' => true, 'evidence' => ['official' => true], 'state_lgd' => '9', 'district_lgd' => $district, 'village_lgd_codes' => [$village], 'village_evidence' => ['official' => true]],
                'sources' => ['monthly' => ['url' => 'https://egramswaraj.gov.in/FileRedirect.jsp?name='.$gp.'.html', 'report_date' => '2026-11-14']],
                'months' => [['month' => '2026-04', 'payments' => 3, 'receipts' => 1, 'contra' => 0, 'journal' => 0]]];
            $file = '9-'.$district.'-'.$gp.'-2026-2027.json';
            file_put_contents($this->root.'/9-'.$district.'/'.$file, json_encode(['schema' => 1, 'report' => $report]));
            $entry = ['panchayat_code' => $gp, 'financial_year' => '2026-2027', 'state_lgd' => '9', 'district_lgd' => $district, 'village_lgd_codes' => [$village], 'file' => $file, 'sha256' => hash_file('sha256', $this->root.'/9-'.$district.'/'.$file)];
            file_put_contents($this->root.'/9-'.$district.'/manifest.json', json_encode(['schema' => 1, 'publication_enabled' => true, 'reports' => [$entry]]));
            $this->index['districts'][$district] = ['file' => '9-'.$district.'/manifest.json', 'sha256' => hash_file('sha256', $this->root.'/9-'.$district.'/manifest.json')];
        }
        $this->save();
        config(['services.egramswaraj.reports_path' => $this->root.'/base.json', 'services.egramswaraj.shard_manifest_path' => null, 'services.egramswaraj.district_shards_index_path' => $this->root.'/up-districts.json']);
    }

    protected function tearDown(): void
    {
        foreach (['130', '180'] as $district) {
            foreach (glob($this->root.'/9-'.$district.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->root.'/9-'.$district);
        }
        foreach (glob($this->root.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function save(): void
    {
        file_put_contents($this->root.'/up-districts.json', json_encode($this->index));
    }

    private function rows(string $district, string $gp, ?string $village = null): array
    {
        return app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => $district], 'village' => $village ? ['code' => $village] : null], $gp);
    }

    public function test_each_district_loads_only_its_own_verified_gp_and_village_data(): void
    {
        $this->assertSame(3, $this->rows('130', '69232', '133001')[0]['months'][0]['payments']);
        $this->assertSame(3, $this->rows('180', '86931', '134331')[0]['months'][0]['payments']);
        $this->assertSame([], $this->rows('130', '86931'));
        $this->assertSame([], $this->rows('180', '69232'));
        $this->assertSame([], $this->rows('130', '69232', '134331'));
        $this->assertSame([], $this->rows('173', '69232'));
    }

    public function test_cross_district_or_unverified_manifest_reference_is_rejected(): void
    {
        $original = $this->index;
        $this->index['districts']['130'] = $this->index['districts']['180'];
        $this->save();
        $this->assertSame([], $this->rows('130', '69232'));
        $this->index = $original;
        $this->index['districts']['130']['sha256'] = str_repeat('0', 64);
        $this->save();
        $this->assertSame([], $this->rows('130', '69232'));
        $this->index = $original;
        $this->index['districts']['130']['file'] = '../outside.json';
        $this->save();
        $this->assertSame([], $this->rows('130', '69232'));
    }

    public function test_missing_district_index_leaves_existing_base_data_available(): void
    {
        $payload = json_decode(file_get_contents($this->root.'/9-130/9-130-69232-2026-2027.json'), true)['report'];
        $base = json_decode(file_get_contents($this->root.'/base.json'), true);
        $base['reports'][] = $payload;
        file_put_contents($this->root.'/base.json', json_encode($base));
        unlink($this->root.'/up-districts.json');
        $this->assertSame(3, $this->rows('130', '69232')[0]['months'][0]['payments']);
    }
}
