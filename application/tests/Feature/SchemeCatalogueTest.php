<?php

namespace Tests\Feature;

use App\Services\SchemeCatalogue;
use Tests\TestCase;

class SchemeCatalogueTest extends TestCase
{
    private function catalogue(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/my-place-schemes.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_reviewed_records_keep_stable_ids_source_dates_and_explicit_feed_status(): void
    {
        $data = app(SchemeCatalogue::class)->all();
        $this->assertCount(4, $data['schemes']);
        $this->assertSame(0, $data['quality']['rejected']);
        $this->assertFalse($data['quality']['government_feed_connected']);
        foreach ($data['schemes'] as $record) {
            $this->assertSame($record['source_system'].':'.$record['scope'].':'.$record['id'], $record['catalogue_key']);
            $this->assertTrue($record['rules_verified']);
            $this->assertSame('2026-10-28', $record['checked_on']);
        }
    }

    public function test_identical_duplicates_are_deduplicated_and_conflicting_ids_are_quarantined(): void
    {
        $data = $this->catalogue();
        $data['schemes'][] = $data['schemes'][0];
        $this->assertCount(4, app(SchemeCatalogue::class)->normalise($data)['schemes']);
        $data['schemes'][4]['benefit']['en'] = 'Conflicting test description';
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertCount(3, $result['schemes']);
        $this->assertCount(1, $result['quality']['conflicting_ids']);
        $this->assertNotContains('pmjjby', array_column($result['schemes'], 'id'));
    }

    public function test_same_ids_in_different_jurisdictions_are_not_conflated(): void
    {
        $data = $this->catalogue();
        $copy = $data['schemes'][0];
        $copy['scope'] = '9';
        $copy['scope_name'] = ['en' => 'Uttar Pradesh', 'hi' => 'उत्तर प्रदेश'];
        $data['schemes'][] = $copy;
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertCount(5, $result['schemes']);
        $this->assertSame([], $result['quality']['conflicting_ids']);
    }

    public function test_invalid_dates_and_unsafe_links_are_excluded(): void
    {
        $data = $this->catalogue();
        $data['schemes'][0]['apply'] = 'javascript:alert(1)';
        $data['schemes'][1]['valid_until'] = '2026-02-31';
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertCount(2, $result['schemes']);
        $this->assertSame(2, $result['quality']['rejected']);
    }

    public function test_missing_and_unsupported_rules_remain_discovery_only(): void
    {
        $data = $this->catalogue();
        $data['schemes'][0]['rules'] = [];
        $data['schemes'][1]['rules'][] = ['field' => 'unverified_identity', 'op' => 'eq', 'value' => 'yes'];
        $data['schemes'][2]['rules'][] = ['field' => 'age', 'op' => 'unrecognised', 'value' => 18];
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertCount(4, $result['schemes']);
        foreach (array_slice($result['schemes'], 0, 3) as $record) {
            $this->assertFalse($record['rules_verified']);
        }
    }

    public function test_future_review_dates_and_malformed_payloads_cannot_enable_matching(): void
    {
        $data = $this->catalogue();
        $data['schemes'][0]['checked_on'] = '2099-01-01';
        $data['schemes'][1]['id'] = ['invalid'];
        $data['schemes'][2]['rules'][] = ['field' => ['bad'], 'op' => 'eq', 'value' => 'yes'];
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertSame(1, $result['quality']['rejected']);
        $this->assertFalse($result['schemes'][0]['rules_verified']);
        $this->assertFalse($result['schemes'][1]['rules_verified']);
    }

    public function test_broader_question_definitions_do_not_add_unreviewed_live_rules(): void
    {
        $data = $this->catalogue();
        foreach (['occupation', 'education', 'student'] as $field) {
            $this->assertSame('choice', $data['question_fields'][$field]['type']);
            foreach ($data['schemes'] as $scheme) {
                $this->assertNotContains($field, array_column($scheme['rules'], 'field'));
            }
        }
    }

    public function test_malformed_metadata_and_reserved_fields_are_not_rendered(): void
    {
        $data = $this->catalogue();
        $data['checked_on'] = ['bad'];
        $data['questions']['age'] = ['en' => 'Duplicate age', 'hi' => 'आयु'];
        $data['question_fields']['age'] = ['type' => 'number', 'min' => 0, 'max' => 120];
        $data['questions']['broken'] = ['en' => ['bad']];
        $data['schemes'][0]['category'] = ['bad'];
        $result = app(SchemeCatalogue::class)->normalise($data);
        $this->assertNull($result['checked_on']);
        $this->assertArrayNotHasKey('age', $result['questions']);
        $this->assertArrayNotHasKey('broken', $result['questions']);
        $this->assertSame(1, $result['quality']['rejected']);
    }
}
