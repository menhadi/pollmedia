<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use PDO;

final class PanchayatSummaryPage
{
    public function show(array $profile): View
    {
        $input = request()->validate([
            'section' => 'nullable|in:overview,issues',
            'village_page' => 'nullable|integer|min:1|max:100000',
            'village_q' => 'nullable|string|max:120',
        ]);
        $record = $profile['record'];
        $members = $profile['villages'];
        $own = app(MyPlacePanchayat::class)->profileRecords($record, array_column($members, 'district'));
        $hi = app()->getLocale() === 'hi' || request('lang') === 'hi';
        $section = $input['section'] ?? 'overview';
        $search = trim($input['village_q'] ?? '');
        $filtered = array_values(array_filter($members, fn (array $member): bool => $search === '' || mb_stripos($member['name'], $search) !== false));
        $page = min((int) ($input['village_page'] ?? 1), max(1, (int) ceil(count($filtered) / 50)));
        $rows = [];
        $map = app(ElectoralVillageMap::class);
        $lookup = $map->database()->prepare('SELECT d.*,s.name AS state_name FROM directory d JOIN states s ON s.code=d.state JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=? AND d.state=? AND a.district=? AND a.subdistrict=?');
        foreach (array_slice($filtered, ($page - 1) * 50, 50) as $member) {
            $lookup->execute(array_map('strval', [$member['village'], $record['state'], $member['district'], $member['subdistrict']]));
            $matches = $lookup->fetchAll(PDO::FETCH_ASSOC);
            $village = count($matches) === 1 ? $matches[0] : null;
            $row = ['name' => $member['name'], 'subdistrict' => $member['subdistrict_name'], 'url' => null, 'population' => null, 'households' => null];
            if ($village) {
                $row['url'] = app(NamedPlaceUrls::class)->village($village).($hi ? '?lang=hi' : '');
                $facts = app(MyPlaceFacts::class)->village(['code' => (string) $member['village'], 'census2011' => $village['census2011']], (string) $record['state']);
                if ($facts['census_status'] === 'available') {
                    $row['population'] = $facts['population'];
                    $row['households'] = $facts['households'];
                }
            }
            $rows[] = $row;
        }
        $villagesPage = new LengthAwarePaginator($rows, count($filtered), 50, $page, ['path' => url()->current(), 'pageName' => 'village_page']);
        $villagesPage->withQueryString()->fragment('member-villages');
        $related = [];
        $stateName = $members[0]['state_name'] ?? '';
        if ($stateName !== '') {
            $url = route('civic.state', ['state' => PlaceFactTables::stateSlug($stateName), 'lang' => $hi ? 'hi' : null]);
            $related[$url] = ['kind' => 'state', 'name' => $stateName, 'url' => $url];
        }
        foreach (collect($members)->unique(fn (array $member): string => $member['district'].':'.$member['subdistrict']) as $member) {
            $lookup->execute(array_map('strval', [$member['village'], $record['state'], $member['district'], $member['subdistrict']]));
            $matches = $lookup->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) !== 1) {
                continue;
            }
            foreach (app(PlaceBreadcrumbs::class)->village($matches[0]) as $position => $crumb) {
                if (! empty($crumb['url']) && $crumb['label'] !== 'India' && $crumb['label'] !== $stateName) {
                    $related[$crumb['url']] = ['kind' => $position === 2 ? 'district' : 'subdistrict', 'name' => $crumb['label'], 'url' => $crumb['url']];
                }
            }
        }
        $communityFeed = null;
        if ($section === 'issues') {
            $issueArea = ['kind' => 'gram-panchayat', 'name' => $record['name'], 'keys' => array_values(array_unique(array_map(fn (array $member): string => 'village:'.$member['village'], $members))), 'place_id' => null, 'available' => $members !== []];
            $communityFeed = app(CommunityAreaIssues::class)->feed($issueArea);
            app(IssuePostCards::class)->prepare($communityFeed['issues'] instanceof Paginator ? $communityFeed['issues']->items() : $communityFeed['issues']);
        }

        return view('gram-panchayat-summary', compact('record', 'members', 'rows', 'own', 'hi', 'villagesPage', 'search', 'section', 'related', 'communityFeed'));
    }
}
