<?php

namespace App\Services;

final class PlaceTopics
{
    public const GROUPS = [
        'source' => ['overview', 'map', 'places', 'elections', 'sources'],
        'district' => ['overview', 'people', 'places', 'panchayats', 'map', 'water', 'soil', 'rainfall', 'history', 'elections', 'authorities', 'markets', 'issues', 'sources'],
        'village' => ['overview', 'places', 'people', 'map', 'water', 'soil', 'rainfall', 'history', 'markets', 'issues', 'sources'],
        'census' => ['overview', 'places', 'people', 'map', 'water', 'soil', 'rainfall', 'history', 'elections', 'markets', 'issues', 'sources'],
        'gp' => ['overview', 'villages', 'records', 'village-data'],
        'member' => ['overview', 'places', 'people', 'map', 'water', 'soil', 'rainfall', 'history', 'markets', 'sources'],
        'my' => ['overview', 'places', 'water', 'gram-panchayat', 'schools-services', 'local-markets', 'government-schemes', 'agri-insight', 'local-issues', 'sources'],
        'pilot' => ['overview', 'places', 'water', 'schools-services', 'sources'],
    ];

    public const LABELS = [
        'overview' => ['Overview', 'सारांश'], 'places' => ['Places', 'स्थान'], 'villages' => ['Linked villages', 'संबंधित गाँव'],
        'records' => ['Panchayat records', 'पंचायत रिकॉर्ड'], 'village-data' => ['Village data', 'गाँव के आँकड़े'],
        'people' => ['Population', 'जनसंख्या'], 'panchayats' => ['Gram Panchayat', 'ग्राम पंचायत'],
        'map' => ['Map & satellite', 'मानचित्र और उपग्रह'], 'water' => ['Water', 'जल'], 'soil' => ['Soil', 'मिट्टी'],
        'rainfall' => ['Rainfall', 'वर्षा'], 'history' => ['History & charts', 'इतिहास और चार्ट'], 'elections' => ['Elections', 'चुनाव'],
        'authorities' => ['Authorities', 'अधिकारी'], 'markets' => ['Markets', 'मंडी'], 'issues' => ['Local issues', 'स्थानीय समस्याएँ'],
        'sources' => ['Sources', 'स्रोत'], 'gram-panchayat' => ['Gram Panchayat', 'ग्राम पंचायत'], 'schools-services' => ['Schools & services', 'विद्यालय एवं सेवाएँ'],
        'local-markets' => ['Markets', 'मंडी'], 'government-schemes' => ['Schemes', 'योजनाएँ'], 'agri-insight' => ['Crop insights', 'फसल जानकारी'],
        'local-issues' => ['Local issues', 'स्थानीय समस्याएँ'],
    ];

    public static function selected(string $group): string
    {
        $key = $group === 'member' ? 'vtopic' : 'topic';
        $topic = request()->query($key);
        if ($group === 'gp' && $topic === null && request()->has('village')) {
            return 'village-data';
        }

        return in_array($topic, self::GROUPS[$group], true) ? $topic : 'overview';
    }
}
