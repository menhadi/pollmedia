<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommunityData;
use App\Services\CommunityGoogleIdentity;
use App\Services\ElectoralVillageMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommunityDataTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Official fixture', 'url' => 'https://censusindia.gov.in/test.xls', 'format' => 'xls', 'record_key' => 'code', 'options' => '{}']);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => 'https://censusindia.gov.in/test.xls', 'origin' => 'url', 'status' => 'accepted', 'sha256' => str_repeat('a', 64), 'extracted' => '{}', 'created_at' => now()]);
        $edition = DB::table('census_editions')->insertGetId(['import_run_id' => $run, 'source_key' => 'india-basic-2011-total', 'name' => 'Official Census fixture', 'year' => 2011, 'status' => 'published', 'sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/test.xls', 'landing_url' => 'https://censusindia.gov.in', 'scope' => 'Test source scope', 'fields' => json_encode(['TOT_P', 'P_06', 'P_LIT', 'No_HH', 'WATER']), 'row_count' => 3, 'retrieved_at' => now()]);
        DB::table('census_publications')->insert(['source_key' => 'india-basic-2011-total', 'edition_id' => $edition]);
        $ids = [];
        foreach ([['Alpha', 100, 20, 40], ['Beta', 200, 20, 144], ['Missing', null, null, null]] as $i => [$name, $population, $children, $literate]) {
            $ids[] = DB::table('census_catalogue_rows')->insertGetId(['edition_id' => $edition, 'record_key' => hash('sha256', $name), 'state_code' => sprintf('%02d', $i + 1), 'district_code' => '000', 'level' => 'STATE', 'residence' => 'Total', 'name' => $name, 'geography' => '{}', 'values' => json_encode(['TOT_P' => $population, 'P_06' => $children, 'P_LIT' => $literate, 'No_HH' => 0, 'WATER' => '01']), 'flags' => json_encode(['Original source note']), 'source_row' => $i + 2]);
        }

        return $ids;
    }

    private function proposal(int $id, User $user, string $field = 'P_LIT', string $value = '60'): int
    {
        $row = app(CommunityData::class)->record($id);
        $this->actingAs($user)->post(route('community.propose', $id), ['field' => $field, 'value_type' => 'number', 'value' => $value, 'reason' => 'The official source cell shows this value.', 'reference_url' => 'https://censusindia.gov.in/test.xls', 'expected' => app(CommunityData::class)->fingerprint($row)])->assertRedirect(route('community.record', $id));

        return DB::table('community_data_proposals')->latest('id')->value('id');
    }

    public function test_listing_renders_source_scoped_quick_facts_and_preserves_missing_and_zero_values(): void
    {
        $ids = $this->records();
        $this->get(route('community.data'))->assertOk()->assertSee('80.00%')->assertSee('50.00%')->assertSee('Beta')->assertSee('Alpha')->assertSee('not a claim of complete nationwide coverage')->assertSee('Unavailable');
        $this->get(route('community.record', $ids[0]))->assertOk()->assertSee('Original source note')->assertSee('All published fields')->assertSee('01')->assertSee('Households')->assertSee('Published change history');
    }

    public function test_guest_cannot_submit_and_non_admin_cannot_review(): void
    {
        $id = $this->records()[0];
        $this->post(route('community.propose', $id), [])->assertRedirect();
        $this->assertDatabaseCount('community_data_proposals', 0);
        $this->actingAs(User::factory()->create())->get(route('community.review'))->assertForbidden();
    }

    public function test_pending_suggestion_does_not_change_data_and_admin_approval_preserves_audit(): void
    {
        $id = $this->records()[0];
        $proposal = $this->proposal($id, User::factory()->create());
        $this->assertSame(40, json_decode(DB::table('census_catalogue_rows')->find($id)->values, true)['P_LIT']);
        $this->get(route('community.record', $id))->assertSee('Pending')->assertDontSee('Approved 20');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('community.review'))->assertOk()->assertSee('Approve and publish')->assertSee('The official source cell shows this value.');
        $this->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Checked against the official source.'])->assertRedirect();
        $this->assertSame(60, json_decode(DB::table('census_catalogue_rows')->find($id)->values, true)['P_LIT']);
        $this->assertDatabaseHas('community_data_proposals', ['id' => $proposal, 'status' => 'approved']);
        $this->assertDatabaseHas('site_changes', ['target' => 'census_catalogue_rows:'.$id]);
        $this->get(route('community.record', $id))->assertSee('Checked against the official source.')->assertSee('40 → 60');
    }

    public function test_stale_and_replayed_approvals_do_not_overwrite_data(): void
    {
        $id = $this->records()[0];
        $proposal = $this->proposal($id, User::factory()->create());
        DB::table('census_catalogue_rows')->where('id', $id)->update(['values' => json_encode(['P_LIT' => 70])]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Checked against official source.'])->assertConflict();
        $this->assertDatabaseHas('community_data_proposals', ['id' => $proposal, 'status' => 'pending']);
        $this->post(route('community.decide', $proposal), ['decision' => 'rejected', 'review_note' => 'Stale suggestion; newer data exists.'])->assertRedirect();
        $this->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Checked against official source.'])->assertConflict();
        $this->assertSame(70, json_decode(DB::table('census_catalogue_rows')->find($id)->values, true)['P_LIT']);
    }

    public function test_identity_fields_derived_values_and_unverified_users_cannot_edit(): void
    {
        $id = $this->records()[0];
        $this->actingAs(User::factory()->unverified()->create())->post(route('community.propose', $id))->assertForbidden();
        $row = app(CommunityData::class)->record($id);
        $this->actingAs(User::factory()->create());
        foreach (['state_code', 'edition_id', 'literacy', 'unknown'] as $field) {
            $this->post(route('community.propose', $id), ['field' => $field, 'value_type' => 'text', 'value' => '01', 'reason' => 'A test correction reason.', 'expected' => app(CommunityData::class)->fingerprint($row)])->assertUnprocessable();
        }
        $this->assertDatabaseCount('community_data_proposals', 0);
    }

    public function test_admin_cannot_approve_own_suggestion_and_duplicate_pending_is_rejected(): void
    {
        $id = $this->records()[0];
        $admin = User::factory()->create(['is_admin' => true]);
        $proposal = $this->proposal($id, $admin);
        $this->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Checked against official source.'])->assertForbidden();
        $this->post(route('community.propose', $id), ['field' => 'P_LIT', 'value_type' => 'number', 'value' => '61', 'reason' => 'A different correction reason.', 'expected' => app(CommunityData::class)->fingerprint(app(CommunityData::class)->record($id))])->assertConflict();
    }

    public function test_water_codes_are_not_ranked_as_counts_and_unpublished_rows_are_private(): void
    {
        $id = $this->records()[0];
        $this->get(route('community.data', ['topic' => 'water', 'measure' => 'WATER']))->assertOk()->assertSee('Rankings are unavailable')->assertDontSee('Highest available');
        DB::table('census_editions')->update(['status' => 'draft']);
        $this->get(route('community.record', $id))->assertNotFound();
    }

    public function test_email_code_is_hashed_session_bound_one_use_and_authenticates_verified_contributor(): void
    {
        $code = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body, callable $callback) use (&$code): void {
            preg_match('/code is ([0-9]{6})/', $body, $matches);
            $code = $matches[1];
        });
        $this->post(route('community.email'), ['email' => 'contributor@example.org'])->assertRedirect();
        $challenge = session('community.challenge');
        $this->assertNotSame($code, DB::table('community_login_codes')->find($challenge)->code_hash);
        $this->post(route('community.verify'), ['code' => $code])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertFalse((bool) auth()->user()->is_admin);
        $this->assertNotNull(auth()->user()->email_verified_at);
        $this->post(route('community.logout'))->assertRedirect();
        $this->withSession(['community.challenge' => $challenge])->post(route('community.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_codes_expire_and_lock_after_five_failures_and_cannot_login_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('community_login_codes')->insert(['id' => 'challenge', 'email' => $admin->email, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
        $this->withSession(['community.challenge' => 'challenge'])->post(route('community.verify'), ['code' => '123456'])->assertForbidden();
        $this->assertGuest();
        DB::table('community_login_codes')->where('id', 'challenge')->update(['used_at' => null, 'expires_at' => now()->subSecond()]);
        $this->withSession(['community.challenge' => 'challenge'])->post(route('community.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        DB::table('community_login_codes')->where('id', 'challenge')->update(['expires_at' => now()->addMinutes(10), 'email' => 'new@example.org']);
        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['community.challenge' => 'challenge'])->post(route('community.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->withSession(['community.challenge' => 'challenge'])->post(route('community.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_google_rejects_invalid_state_without_contacting_provider(): void
    {
        config(['community-auth.google_client_id' => 'client', 'community-auth.google_client_secret' => 'secret']);
        Http::fake();
        $this->get(route('community.google'))->assertRedirectContains('accounts.google.com');
        $this->get(route('community.google.callback', ['state' => 'wrong', 'code' => 'test']))->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_google_checks_signature_audience_nonce_and_verified_email(): void
    {
        config(['community-auth.google_client_id' => 'client']);
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $configuration = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($configuration)) {
            $options['config'] = $configuration;
        }
        $key = openssl_pkey_new($options);
        $publicKey = openssl_pkey_get_details($key)['key'];
        Cache::put('community.google.certificates', ['key1' => $publicKey], 300);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'client', 'sub' => 'subject', 'exp' => time() + 600, 'iat' => time(), 'nonce' => 'nonce', 'email' => 'test@example.org', 'email_verified' => true];
        $token = $encode(json_encode(['alg' => 'RS256', 'kid' => 'key1'])).'.'.$encode(json_encode($claims));
        openssl_sign($token, $signature, $key, OPENSSL_ALGO_SHA256);
        $token .= '.'.$encode($signature);
        $this->assertSame('test@example.org', app(CommunityGoogleIdentity::class)->verify($token, 'nonce')['email']);
        $this->expectException(HttpException::class);
        app(CommunityGoogleIdentity::class)->verify($token, 'different nonce');
    }

    public function test_rejection_keeps_published_values_and_private_submission_out_of_public_history(): void
    {
        $id = $this->records()[0];
        $proposal = $this->proposal($id, User::factory()->create());
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', $proposal), ['decision' => 'rejected', 'review_note' => 'The source does not support this suggestion.'])->assertRedirect();
        $this->post(route('community.logout'));
        $this->get(route('community.record', $id))->assertOk()->assertDontSee('The source does not support this suggestion.')->assertDontSee('The official source cell shows this value.');
        $this->assertSame(40, json_decode(DB::table('census_catalogue_rows')->find($id)->values, true)['P_LIT']);
    }

    public function test_identity_columns_inside_original_directory_values_are_read_only(): void
    {
        $id = $this->records()[0];
        DB::table('census_editions')->update(['fields' => json_encode(['ST_CODE', 'V_CT_CODE', 'VILL_NAME', 'WATER'])]);
        $row = app(CommunityData::class)->record($id);
        $this->actingAs(User::factory()->create());
        foreach (['ST_CODE', 'V_CT_CODE', 'VILL_NAME'] as $field) {
            $this->post(route('community.propose', $id), ['field' => $field, 'value_type' => 'text', 'value' => 'New identity', 'reason' => 'An identity correction is not a value correction.', 'expected' => app(CommunityData::class)->fingerprint($row)])->assertUnprocessable();
        }
        $this->assertDatabaseCount('community_data_proposals', 0);
    }

    public function test_requested_missing_topic_remains_unavailable_instead_of_switching_to_literacy(): void
    {
        $this->records();
        $this->get(route('community.data', ['topic' => 'health']))->assertOk()->assertSee('Unavailable in this source')->assertDontSee('Highest available');
    }

    public function test_cached_navigation_uses_plain_arrays_and_survives_a_second_request(): void
    {
        $this->records();
        $this->get(route('community.data'))->assertOk();
        $cached = Cache::get('community.rows-v2:states:2011');
        $this->assertIsArray($cached);
        $this->assertIsArray($cached[0]);
        $this->get(route('community.data', ['state' => '01']))->assertOk()->assertSee('Alpha data tables');
        $this->get(route('community.data'))->assertOk()->assertSee('Literacy (%)');
    }

    public function test_constituency_uses_existing_lgd_membership_without_treating_it_as_a_census_state_code(): void
    {
        $this->records();
        $this->mock(ElectoralVillageMap::class, function ($mock): void {
            $mock->shouldReceive('membership')->once()->with('9', 'pc', '137')->andReturn(['villages' => [], 'name' => 'Pilibhit', 'note' => 'Existing official membership; no linked records in this fixture.']);
        });
        $this->get(route('community.data', ['year' => 2001, 'state' => '09', 'kind' => 'pc', 'seat' => '137']))->assertOk()->assertSee('Pilibhit constituency data tables')->assertSee('Existing official membership')->assertSee('No published source');
    }

    public function test_source_only_records_keep_original_headers_codes_and_identity_caveats(): void
    {
        $id = $this->records()[0];
        DB::table('census_editions')->update(['source_key' => 'census-2001-source-only-test', 'fields' => json_encode(['COLUMN_1', 'COLUMN_2'])]);
        DB::table('census_publications')->update(['source_key' => 'census-2001-source-only-test']);
        DB::table('import_runs')->update(['extracted' => json_encode(['original_headers_by_sheet' => ['Native' => ['ST_CODE', 'Original population']]])]);
        DB::table('census_catalogue_rows')->update(['level' => 'SOURCE RECORD', 'residence' => 'Unresolved', 'state_code' => '', 'district_code' => '', 'geography' => json_encode(['SOURCE_SHEET' => 'Native', 'CURRENT_VILLAGE_ASSIGNMENT' => 'No current village assigned']), 'values' => json_encode(['COLUMN_1' => '09', 'COLUMN_2' => '007']), 'flags' => json_encode(['Unresolved original identity; no current village assigned.'])]);
        $this->get(route('community.data', ['dataset' => 'originals', 'state' => '09']))->assertOk()->assertSee('3 records');
        $this->get(route('community.record', $id))->assertOk()->assertSee('Original population')->assertSee('007')->assertSee('Unresolved original identity');
        $this->actingAs(User::factory()->create())->post(route('community.propose', $id), ['field' => 'COLUMN_1', 'value_type' => 'text', 'value' => '10', 'reason' => 'An identity code cannot be changed here.', 'expected' => app(CommunityData::class)->fingerprint(app(CommunityData::class)->record($id))])->assertUnprocessable();
    }

    public function test_contributor_account_requires_login_and_does_not_allow_caching(): void
    {
        $this->get(route('community.account'))->assertRedirect(route('community.login'));
        $user = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($user)->get(route('community.account'));
        $response->assertOk()->assertSee('My contributions')->assertSee('Your contributions start here')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('community.login'))->assertRedirect(route('community.account'));
    }

    public function test_account_lists_only_own_contributions_and_filters_their_review_status(): void
    {
        [$record] = $this->records();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $pending = $this->proposal($record, $owner);
        DB::table('community_data_proposals')->where('id', $pending)->update(['reason' => 'My pending evidence']);
        $approved = $this->proposal($record, $owner, 'TOT_P', '101');
        DB::table('community_data_proposals')->where('id', $approved)->update(['status' => 'approved', 'reason' => 'My approved evidence', 'review_note' => 'Confirmed against the source document.', 'reviewed_at' => now()]);
        $rejected = $this->proposal($record, $owner, 'P_06', '22');
        DB::table('community_data_proposals')->where('id', $rejected)->update(['status' => 'rejected', 'reason' => 'My rejected evidence', 'review_note' => 'Please supply a clearer source reference.', 'reviewed_at' => now()]);
        $foreign = $this->proposal($record, $other);
        DB::table('community_data_proposals')->where('id', $foreign)->update(['reason' => 'PRIVATE OTHER CONTRIBUTOR EVIDENCE']);
        $this->actingAs($owner)->get(route('community.account'))->assertOk()->assertSee('My pending evidence')->assertSee('My approved evidence')->assertSee('My rejected evidence')->assertSee('Confirmed against the source document.')->assertSee('Please supply a clearer source reference.')->assertDontSee('PRIVATE OTHER CONTRIBUTOR EVIDENCE')->assertDontSee($other->email);
        $this->get(route('community.account', ['status' => 'approved']))->assertOk()->assertSee('My approved evidence')->assertDontSee('My pending evidence')->assertDontSee('My rejected evidence');
        $this->getJson(route('community.account', ['status' => 'invalid']))->assertUnprocessable();
    }

    public function test_listing_name_and_admin_amended_value_preserve_original_source_identity(): void
    {
        $id = $this->records()[0];
        $row = app(CommunityData::class)->record($id);
        $this->actingAs(User::factory()->create())->post(route('community.propose', $id), ['field' => 'listing_name', 'value_type' => 'text', 'value' => 'Suggested name', 'reason' => 'The official source has a corrected spelling.', 'expected' => app(CommunityData::class)->fingerprint($row)])->assertRedirect();
        $proposal = DB::table('community_data_proposals')->value('id');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Amended to the verified spelling.', 'amend' => 1, 'approved_type' => 'text', 'approved_value' => 'Verified name'])->assertRedirect();
        $after = DB::table('census_catalogue_rows')->find($id);
        $this->assertSame('Verified name', $after->name);
        $this->assertSame($row->name, app(CommunityData::class)->sourceName($after));
        $this->assertSame($row->geography, $after->geography);
        $this->assertSame($row->record_key, $after->record_key);
        $this->assertSame($row->values, $after->values);
        $this->get(route('community.record', $id))->assertOk()->assertSee('Verified name')->assertSee('Amended to the verified spelling.');
        $this->assertDatabaseHas('community_data_proposals', ['id' => $proposal, 'proposed_value' => json_encode('Suggested name'), 'approved_value' => json_encode('Verified name')]);
    }

    public function test_approved_corrections_preserve_original_hash_verification_without_accepting_tampering(): void
    {
        $id = $this->records()[0];
        $original = json_decode(DB::table('census_catalogue_rows')->find($id)->values, true);
        $sha = hash('sha256', json_encode($original));
        $proposal = $this->proposal($id, User::factory()->create());
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', $proposal), ['decision' => 'approved', 'review_note' => 'Verified the source correction.'])->assertRedirect();
        $values = json_decode(DB::table('census_catalogue_rows')->find($id)->values, true);
        $this->assertTrue(app(CommunityData::class)->matchesOriginalValues($id, $values, $sha));
        $values['P_LIT'] = 99;
        $this->assertFalse(app(CommunityData::class)->matchesOriginalValues($id, $values, $sha));
    }
    public function test_signed_in_contributor_edits_in_the_existing_table_value_cell(): void
    {
        $ids = $this->records();
        $response = $this->actingAs(User::factory()->create())->get(route('community.data', ['topic' => 'population', 'measure' => 'TOT_P']));
        $response->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $cells = $xpath->query('//table[contains(@class,"data-table")]//td[@data-correction-record="'.$ids[0].'" and @data-correction-field="TOT_P"]');
        $this->assertSame(1, $cells->length);
        $this->assertSame(1, $xpath->query('//table[contains(@class,"data-table")]//td[@data-correction-record="'.$ids[0].'" and @data-correction-field="listing_name"]//form')->length);
        $this->assertStringNotContainsString("@include('community-table-cell-edit'", $response->getContent());
        $forms = $xpath->query('.//form', $cells->item(0));
        $this->assertSame(1, $forms->length);
        $this->assertStringContainsString('/records/'.$ids[0].'/suggest', $forms->item(0)->getAttribute('action'));
        $this->assertSame('100', $xpath->query('.//input[@name="value"]', $forms->item(0))->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//td[@data-correction-field="literacy"]//form')->length);
        $this->assertDatabaseCount('community_data_proposals', 0);
    }

    public function test_guest_cell_edit_returns_to_the_same_table_after_sign_in(): void
    {
        $this->records();
        $html = $this->get(route('community.data', ['topic' => 'population', 'measure' => 'TOT_P']))->assertOk()->getContent();
        $this->assertStringContainsString('class="cell-sign-in"', $html);
        $this->assertStringContainsString('next=%2Fdata%2Fcensus%3F', $html);
        $this->assertStringNotContainsString('All fields / suggest correction', $html);
        $this->assertStringNotContainsString('Other source values', $html);
    }
}
