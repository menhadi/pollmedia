<?php

namespace Tests\Unit;

use App\Services\JjmWaterSnapshot;
use PHPUnit\Framework\TestCase;

class JjmWaterSnapshotTest extends TestCase
{
    private function snapshot(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../database/fixtures/jjm-pilibhit-j1-20261013.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_source_totals_reconcile_without_inventing_village_crosswalks(): void
    {
        $snapshot = JjmWaterSnapshot::validate($this->snapshot());
        $this->assertSame(1291, count($snapshot['rows']));
        $this->assertSame(306759, $snapshot['summary']['households']);
        $this->assertSame(301197, $snapshot['summary']['tap_connections']);
        $this->assertNull($snapshot['observation_date']);
        $this->assertCount(0, array_filter(array_column($snapshot['rows'], 'lgd_village_code')));
    }

    public function test_duplicate_jjm_ids_are_rejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['rows'][1]['jjm_village_id'] = $snapshot['rows'][0]['jjm_village_id'];
        $this->expectException(\UnexpectedValueException::class);
        JjmWaterSnapshot::validate($snapshot);
    }

    public function test_connections_cannot_exceed_source_households(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['rows'][0]['tap_connections'] = $snapshot['rows'][0]['households'] + 1;
        $this->expectException(\UnexpectedValueException::class);
        JjmWaterSnapshot::validate($snapshot);
    }

    public function test_unverified_identifier_cannot_be_published_as_linked(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['rows'][0]['lgd_village_code'] = '132128';
        $this->expectException(\UnexpectedValueException::class);
        JjmWaterSnapshot::validate($snapshot);
    }

    public function test_official_crosswalk_links_all_water_rows_without_mutating_source(): void
    {
        $source = $this->snapshot();
        $mapping = json_decode(file_get_contents(__DIR__.'/../../database/fixtures/jjm-pilibhit-lgd-b37a-20261013.json'), true);
        $linked = JjmWaterSnapshot::linkRows($source, $mapping);
        $this->assertCount(1291, $linked);
        $this->assertSame('405230', $linked['132128']['jjm_village_id']);
        $this->assertNull($source['rows'][0]['lgd_village_code']);
        $this->assertSame('official_live_mapping', $linked['132128']['link_status']);
    }

    public function test_duplicate_target_lgd_is_rejected(): void
    {
        $mapping = json_decode(file_get_contents(__DIR__.'/../../database/fixtures/jjm-pilibhit-lgd-b37a-20261013.json'), true);
        $mapping['rows'][1]['lgd_village_id'] = $mapping['rows'][0]['lgd_village_id'];
        $this->expectException(\UnexpectedValueException::class);
        JjmWaterSnapshot::linkRows($this->snapshot(), $mapping);
    }

    public function test_unmatched_source_id_stays_unlinked(): void
    {
        $mapping = ['rows' => []];
        $this->assertSame([], JjmWaterSnapshot::linkRows($this->snapshot(), $mapping));
    }
}
