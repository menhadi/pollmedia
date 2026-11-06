<?php

namespace Tests\Feature;

use App\Services\PlacePostalCodes;
use App\Services\UrbanPostalParents;
use PDO;
use Tests\TestCase;

class UrbanPostalParentsTest extends TestCase
{
    public function test_body_membership_retains_multiple_scopes_and_never_crosses_state(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'urban-parents-');
        try {
            $db = new PDO('sqlite:'.$path);
            $db->exec("CREATE TABLE parents(body TEXT,state TEXT,level TEXT,code TEXT,district TEXT,name TEXT,coverage TEXT);
                INSERT INTO parents VALUES ('1','29','district','525','525','Bengaluru Urban','Partial'),
                ('1','29','subdistrict','5543','525','Bengaluru South','Partial'),
                ('1','29','subdistrict','5542','525','Bengaluru North','Partial');");
            $service = new UrbanPostalParents($path);
            $rows = $service->forBody('1', '29');
            $this->assertCount(3, $rows);
            $this->assertSame(['district', 'subdistrict', 'subdistrict'], array_column($rows, 'level'));
            $this->assertSame(['Partial', 'Partial', 'Partial'], array_column($rows, 'coverage'));
            $this->assertSame([], $service->forBody('1', '9'));
            $this->assertSame([], $service->forBody('2', '29'));
            unset($service, $db);
        } finally {
            unlink($path);
        }
    }

    public function test_missing_source_does_not_invent_membership(): void
    {
        $this->assertSame([], (new UrbanPostalParents('/missing/source.sqlite'))->forBody('1', '29'));
    }

    public function test_kengeri_reference_is_not_attached_to_gollahalli_or_an_urban_code_namespace(): void
    {
        $service = new PlacePostalCodes;
        $this->assertNull($service->historicalReference(['kind' => 'village', 'code' => '613025', 'state' => '29', 'district' => '525', 'subdistrict' => '5543']));
        $this->assertNull($service->historicalReference(['kind' => 'local-body', 'code' => '938441', 'state' => '29', 'district' => '525', 'subdistrict' => '5543']));
        $this->assertNull($service->historicalReference(['kind' => 'village', 'code' => '938441', 'state' => '9', 'district' => '525', 'subdistrict' => '5543']));
    }
}
