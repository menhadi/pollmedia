<?php

namespace Tests\Feature;

use App\Services\CommunityAreaIssues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityAreaIssuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_report_is_shared_at_every_linked_level_without_duplicates(): void
    {
        $id = (string) Str::ulid();
        DB::table('citizen_issues')->insert(['id' => $id, 'category' => 'roads', 'title' => 'Jatipur road needs repair', 'description' => 'The same issue shared across places.', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $keys = ['village:131191', 'district:9:173', 'state:9', 'ac:9:127', 'pc:9:26'];
        foreach ($keys as $key) {
            DB::table('citizen_issue_scopes')->insert(['issue_id' => $id, 'area_key' => $key, 'kind' => explode(':', $key)[0], 'name' => 'Test area']);
        }
        DB::table('citizen_issue_supports')->insert(['issue_id' => $id, 'participant_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $service = app(CommunityAreaIssues::class);
        foreach ([...$keys, 'india'] as $key) {
            $c = ['kind' => $key === 'india' ? 'india' : explode(':', $key)[0], 'name' => 'Test area', 'keys' => [$key], 'place_id' => null, 'available' => true];
            $f = $service->feed($c);
            $this->assertSame(1, $f['total']);
            $this->assertSame($id, $f['issues']->first()->id);
            $this->assertSame(1, (int) $f['issues']->first()->supports);
            $html = view('community-area-issues', ['issueArea' => $c])->render();
            $this->assertSame(1, substr_count($html, 'data-issue-id="'.$id.'"'));
            $this->assertStringContainsString('Concerned authorities', $html);
        }
        $c['kind'] = 'district';
        $c['keys'] = ['district:9:999'];
        $this->assertSame(0, $service->feed($c)['total']);
        DB::table('citizen_issues')->where('id', $id)->update(['status' => 'rejected']);
        $c['kind'] = 'india';
        $this->assertSame(0, $service->feed($c)['total']);
        $this->assertDatabaseCount('citizen_issues', 1);
    }

    public function test_current_area_navigation_resolves_exact_parent_codes(): void
    {
        $s = app(CommunityAreaIssues::class);
        $this->assertSame(['state:9'], $s->context('state', 'Uttar Pradesh', 'uttar-pradesh')['keys']);
        $this->assertSame(['district:9:173'], $s->context('district', 'Pilibhit', '9')['keys']);
        $this->assertSame([], $s->context('district', 'Pilibhit', 'unknown-state')['keys']);
        $this->assertSame(['village:131191'], $s->context('village','Village','','131191')['keys']);
    }
}
