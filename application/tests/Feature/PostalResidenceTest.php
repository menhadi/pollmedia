<?php

namespace Tests\Feature;

use App\Services\MyPlaceDirectory;
use App\Services\PostalResidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostalResidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'my_place.agmarknet.api_key' => 'test-key']);
        Cache::flush();
        $directory = $this->mock(MyPlaceDirectory::class);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $directory->shouldReceive('districts')->with('9')->andReturn([['code' => '173', 'name' => 'Pilibhit'], ['code' => '150', 'name' => 'Bareilly']]);
    }

    private function row(string $district = 'PILIBHIT', string $pin = '262001'): array
    {
        return ['pincode' => $pin, 'statename' => 'UTTAR PRADESH', 'district' => $district];
    }

    public function test_only_a_unique_official_residence_is_filled_and_no_profile_is_forwarded(): void
    {
        Http::fake(['api.data.gov.in/*' => Http::response(['total' => 2, 'records' => [$this->row(), $this->row()]], 200)]);
        $this->getJson('/my-place/schemes/pincode?pincode=262001')->assertOk()->assertJsonPath('reason', null)->assertJsonPath('matches.0.state', '9')->assertJsonPath('matches.0.district', '173')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => $request['filters[pincode]'] === '262001' && ! isset($request['dob']) && ! isset($request['name']));
    }

    public function test_conflicting_districts_are_returned_for_manual_choice(): void
    {
        Http::fake(['*' => Http::response(['total' => 2, 'records' => [$this->row(), $this->row('BAREILLY')]])]);
        $result = app(PostalResidence::class)->lookup('262001');
        $this->assertSame('ambiguous', $result['reason']);
        $this->assertCount(2, $result['matches']);
    }

    public function test_old_or_unknown_district_names_do_not_get_assigned_to_another_district(): void
    {
        Http::fake(['*' => Http::response(['total' => 1, 'records' => [$this->row('OLD DISTRICT')]])]);
        $result = app(PostalResidence::class)->lookup('262001');
        $this->assertSame('manual_confirmation', $result['reason']);
        $this->assertSame([], $result['matches']);
    }

    public function test_an_ignored_pin_filter_cannot_fill_a_different_place(): void
    {
        Http::fake(['*' => Http::response(['total' => 1, 'records' => [$this->row('PILIBHIT', '243001')]])]);
        $result = app(PostalResidence::class)->lookup('262001');
        $this->assertSame('unverified_source', $result['reason']);
        $this->assertSame([], $result['matches']);
    }

    public function test_truncated_source_results_never_appear_unique(): void
    {
        Http::fake(['*' => Http::response(['total' => 1001, 'records' => [$this->row()]])]);
        $this->assertSame('incomplete_source', app(PostalResidence::class)->lookup('262001')['reason']);
    }

    public function test_unavailable_and_missing_source_results_remain_explicit(): void
    {
        Http::fakeSequence()->push([], 503)->push(['total' => 0, 'records' => []]);
        $this->assertSame('source_unavailable', app(PostalResidence::class)->lookup('262001')['reason']);
        $this->assertSame('not_found', app(PostalResidence::class)->lookup('262001')['reason']);
    }

    public function test_invalid_pin_codes_are_rejected_before_the_government_request(): void
    {
        Http::fake();
        $this->getJson('/my-place/schemes/pincode?pincode=000000')->assertUnprocessable();
        $this->getJson('/my-place/schemes/pincode?pincode=26200a')->assertUnprocessable();
        Http::assertNothingSent();
    }
}
