<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MyPlaceHub
{
    public function data(array $context, ?string $topic = null): array
    {
        $localityKey = collect($context['locality']['scopes'] ?? [])->firstWhere('kind', 'locality')['key'] ?? null;
        $scope = $localityKey ?: ($context['village'] ? 'village:'.$context['village']['code'] : 'district:'.$context['state']['code'].':'.$context['district']['code']);
        $issues = $topic === null || $topic === 'local-issues' ? DB::table('citizen_issues')->whereIn('status', ['open', 'in_progress', 'resolved'])
            ->whereIn('id', DB::table('citizen_issue_scopes')->where('area_key', $scope)->select('issue_id'))
            ->orderByDesc('created_at')->limit(10)->get(['id', 'title', 'category', 'status', 'created_at']) : collect();
        $services = $context['pilot'] && in_array($topic, [null, 'schools-services', 'sources'], true) ? app(PilibhitMyPlace::class)->services() : null;

        return ['scope' => $scope, 'issues' => $issues, 'services' => $services, 'service_contacts' => in_array($topic, [null, 'schools-services', 'sources'], true) ? app(MyPlaceServices::class)->directory($context) : [], 'panchayat_reports' => in_array($topic, [null], true) ? app(MyPlacePanchayat::class)->reports($context) : [],
            'panchayat' => in_array($topic, [null, 'gram-panchayat', 'sources'], true) ? app(MyPlacePanchayat::class)->data($context) : ['directory' => [], 'reports' => []], 'schools' => in_array($topic, [null, 'schools-services', 'sources'], true) ? app(MyPlaceSchools::class)->data($context) : []];
    }
}
