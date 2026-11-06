<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MyPlaceHub
{
    public function data(array $context): array
    {
        $localityKey = collect($context['locality']['scopes'] ?? [])->firstWhere('kind', 'locality')['key'] ?? null;
        $scope = $localityKey ?: ($context['village'] ? 'village:'.$context['village']['code'] : 'district:'.$context['state']['code'].':'.$context['district']['code']);
        $issues = DB::table('citizen_issues')->whereIn('status', ['open', 'in_progress', 'resolved'])
            ->whereIn('id', DB::table('citizen_issue_scopes')->where('area_key', $scope)->select('issue_id'))
            ->orderByDesc('created_at')->limit(10)->get(['id', 'title', 'category', 'status', 'created_at']);
        $services = $context['pilot'] ? app(PilibhitMyPlace::class)->services() : null;

        return ['scope' => $scope, 'issues' => $issues, 'services' => $services, 'service_contacts' => app(MyPlaceServices::class)->directory($context), 'panchayat_reports' => app(MyPlacePanchayat::class)->reports($context),
            'panchayat' => app(MyPlacePanchayat::class)->data($context), 'schools' => app(MyPlaceSchools::class)->data($context)];
    }
}
