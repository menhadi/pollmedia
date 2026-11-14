<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

class NationalPanchayatCoverageTest extends TestCase
{
    public function test_published_snapshots_preserve_scope_provenance_and_source_statuses(): void
    {
        $root = getenv('NATIONAL_SUMMARY_FIXTURES') ?: database_path('fixtures/egramswaraj-national');
        if (! is_file($root.'/index.json')) {
            $this->markTestSkipped('National snapshots have not been published.');
        }
        $index = json_decode(file_get_contents($root.'/index.json'), true);
        $this->assertSame(1, $index['schema']);
        $this->assertTrue($index['publication_enabled']);
        $records = $captured = 0;
        foreach ($index['states'] as $state => $entry) {
            if (! isset($entry['file'])) {
                $this->assertSame(0, $entry['total']);

                continue;
            }
            $this->assertSame('state-'.$state.'.sqlite', $entry['file']);
            $this->assertSame($entry['sha256'], hash_file('sha256', $root.'/'.$entry['file']));
            $db = new PDO('sqlite:file:'.$root.'/'.$entry['file'].'?mode=ro');
            $meta = $db->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame((string) $state, $meta['state']);
            $actual = $db->query('SELECT status,COUNT(*) FROM panchayats GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertEquals($entry['counts'], $actual);
            $this->assertSame($entry['total'], array_sum($actual));
            $records += $entry['total'];
            foreach ($db->query('SELECT gp,status,report_blob,source_sha256,captured_on FROM panchayats') as $row) {
                if ($row['status'] === 'pending') {
                    $this->assertNull($row['report_blob']);

                    continue;
                }
                $report = json_decode(gzuncompress($row['report_blob']), true);
                $this->assertSame('https://egramswaraj.gov.in/FileRedirect.jsp?FD=FinancialYear2026-2027/'.$state.'&name='.$row['gp'].'.html', $report['source_url']);
                $this->assertSame($row['captured_on'], $report['captured_on']);
                $this->assertSame($row['status'], $report['status']);
                if ($row['status'] !== 'fetch_failed') {
                    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $report['source_sha256']);
                    $this->assertSame($row['source_sha256'], $report['source_sha256']);
                }
                if ($row['status'] === 'unavailable') {
                    $this->assertSame([], $report['monthly']);
                    $this->assertNotEmpty($report['notes']);
                }
                if ($row['status'] === 'available') {
                    $this->assertNotEmpty($report['monthly']);
                    $this->assertSame('2026-2027', $report['financial_year']);
                    $this->assertDoesNotMatchRegularExpression('/(?:Village|Gram)\\s+Panchayat\\s*(?:&\\s*Equivalent)?\\s*[:-]+/i', $report['name']);
                }
                $this->assertArrayNotHasKey('activities', $report);
                $this->assertArrayNotHasKey('vouchers', $report);
                $captured++;
            }
        }
        $this->assertGreaterThan(200000, $records);
        $this->assertGreaterThan(0, $captured);
    }
}
