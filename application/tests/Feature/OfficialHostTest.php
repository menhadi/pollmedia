<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OfficialDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OfficialHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_approve_hosts_and_exact_host_matching_is_enforced(): void
    {
        $this->post('/admin/official-hosts')->assertRedirect(route('admin.login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/official-hosts')->assertForbidden();
        $user->is_admin = true;
        $user->save();
        $input = ['host' => 'data.example.gov', 'country_code' => 'US', 'publisher' => 'Test public institution',
            'verification_note' => 'Synthetic test evidence confirming the exact publisher hostname.', 'confirmed' => 1];
        $this->post('/admin/official-hosts', $input)->assertRedirect(route('official-hosts.index'));
        $this->get('/admin/official-hosts')->assertOk()->assertSee('Test public institution');
        $download = app(OfficialDownload::class);
        $this->assertSame('data.example.gov', $download->validateUrl('https://data.example.gov/export.csv'));
        foreach (['https://data.example.gov.evil.test/a', 'https://other.data.example.gov/a', 'http://data.example.gov/a',
            'https://name:password@data.example.gov/a', 'https://127.0.0.1/a', 'https://data.example.gov/a?token=secret'] as $url) {
            try {
                $download->validateUrl($url);
                $this->fail('Unsafe or unapproved URL accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $host = DB::table('official_source_hosts')->first();
        $this->assertSame($user->id, $host->reviewed_by);
        $this->post('/admin/official-hosts/'.$host->id, ['enabled' => 0])->assertRedirect();
        $this->expectException(RuntimeException::class);
        $download->validateUrl('https://data.example.gov/export.csv');
    }
}
