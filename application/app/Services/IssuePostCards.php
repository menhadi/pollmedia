<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssuePostCards
{
    public function prepare(iterable $posts): void
    {
        $items = collect($posts);
        $ids = $items->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $media = DB::table('citizen_issue_media')->whereIn('issue_id', $ids)->orderBy('id')->get()->groupBy('issue_id');
        $scopes = DB::table('citizen_issue_scopes')->whereIn('issue_id', $ids)->get()->groupBy('issue_id');
        $counts = DB::table('citizen_issue_supports')->whereIn('issue_id', $ids)->selectRaw('issue_id, direction, COUNT(*) as total')->groupBy('issue_id', 'direction')->get()->groupBy('issue_id');
        $authors = DB::table('citizen_issue_locations')->whereIn('issue_id', $ids)->pluck('snapshot', 'issue_id');
        $participant = session('issue_participant');
        $mine = $participant ? DB::table('citizen_issue_supports')->whereIn('issue_id', $ids)->where('participant_hash', hash_hmac('sha256', $participant, config('app.key')))->pluck('direction', 'issue_id') : collect();
        foreach ($items as $post) {
            $snapshot = json_decode($authors->get($post->id, '{}'), true);
            $post->displayName = trim($snapshot['public_display_name'] ?? '') ?: 'Anonymous resident';
            $post->media = $media->get($post->id, collect());
            $votes = $counts->get($post->id, collect());
            $post->upvotes = (int) ($votes->firstWhere('direction', 1)->total ?? 0);
            $post->downvotes = (int) ($votes->firstWhere('direction', -1)->total ?? 0);
            $post->reaction = (int) $mine->get($post->id, 0);
            $areas = $scopes->get($post->id, collect());
            $state = $areas->firstWhere('kind', 'state')->name ?? null;
            $post->placeLinks = [];
            foreach ($areas->sortBy(fn ($area) => array_search($area->kind, ['village', 'district', 'state', 'ac', 'pc'])) as $area) {
                $url = null;
                if ($area->kind === 'village' && preg_match('/^village:([0-9]{1,8})$/', $area->area_key, $match)) {
                    $url = route('issues.index', ['area' => $area->area_key, 'reports' => 1, 'lang' => app()->getLocale()]);
                } elseif ($area->kind === 'state') {
                    $url = route('states.show', Str::slug($area->name));
                } elseif ($state && $area->kind === 'district') {
                    $url = route('district-dashboard.named', ['state' => Str::slug($state), 'district' => Str::slug($area->name)]);
                }
                if ($url) {
                    $post->placeLinks[] = ['kind' => $area->kind, 'name' => $area->name, 'url' => $url];
                }
            }
        }
    }
}
