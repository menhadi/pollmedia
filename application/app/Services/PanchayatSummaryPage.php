<?php

namespace App\Services;

use Illuminate\Contracts\View\View;
use PDO;

final class PanchayatSummaryPage
{
    public function show(array $profile): View
    {
        $record = $profile['record'];
        $members = $profile['villages'];
        $own = app(MyPlacePanchayat::class)->profileRecords($record, array_column($members, 'district'));
        $hi = app()->getLocale() === 'hi' || request('lang') === 'hi';
        $rows = [];
        $map = app(ElectoralVillageMap::class);
        $lookup = $map->database()->prepare('SELECT d.*,s.name AS state_name FROM directory d JOIN states s ON s.code=d.state JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=? AND d.state=? AND a.district=? AND a.subdistrict=?');
        foreach (array_slice($members, 0, 5) as $member) {
            $lookup->execute(array_map('strval', [$member['village'], $record['state'], $member['district'], $member['subdistrict']]));
            $village = $lookup->fetch(PDO::FETCH_ASSOC);
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

        return view('gram-panchayat-summary', compact('record', 'members', 'rows', 'own', 'hi'));
    }
}
