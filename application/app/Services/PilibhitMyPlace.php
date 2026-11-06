<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PilibhitMyPlace
{
    public function villages(): array
    {
        $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
        if (! is_file($path)) {
            return [];
        }
        $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        $query = $db->prepare('SELECT DISTINCT d.lgd AS code,d.name,d.census2011,d.subdistrict,a.subdistrict AS subdistrict_code FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? ORDER BY d.name,d.lgd');
        $query->execute(['9', '173']);
        $groups = collect($query->fetchAll(PDO::FETCH_ASSOC))->groupBy('code');

        return $groups->filter(fn ($rows) => $rows->count() === 1)->map(fn ($rows) => $rows->first())->values()->all();
    }

    public function services(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/my-place-pilibhit.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function facts(?array $village): array
    {
        $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->first(['r.payload', 'r.url']);
        $data = $release?->payload ? json_decode($release->payload, true, flags: JSON_THROW_ON_ERROR) : [];
        $row = null;
        if ($village && ! empty($village['census2011'])) {
            $matches = array_values(array_filter($data['villages'] ?? [], fn ($row) => (string) $row['code'] === (string) $village['census2011']));
            $row = count($matches) === 1 ? $matches[0] : null;
        }
        $population = $village ? ($row['population'] ?? null) : ($data['district_population'] ?? null);
        $denominator = isset($row['population'], $row['population_0_6']) ? $row['population'] - $row['population_0_6'] : null;
        $water = $waterSource = null;
        try {
            $snapshot = app(JjmWaterSnapshot::class)->pilibhit();
            $waterSource = ['url' => $snapshot['source_url'], 'retrieved_at' => $snapshot['retrieved_at']];
            if ($village) {
                $path = database_path('fixtures/jjm-pilibhit-lgd-b37a-20261013.json');
                if (is_file($path)) {
                    $mapping = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                    $water = JjmWaterSnapshot::linkRows($snapshot, $mapping)[$village['code']] ?? null;
                }
            } else {
                $water = $snapshot['summary'];
                $water['coverage_pct'] = $water['households'] > 0 ? round(100 * $water['tap_connections'] / $water['households'], 2) : null;
            }
        } catch (HttpExceptionInterface|\UnexpectedValueException) {
            $water = null;
        }

        $facts = ['population' => $population, 'households' => $row['households'] ?? null,
            'literacy' => $denominator > 0 && isset($row['literate']) ? round(100 * $row['literate'] / $denominator, 2) : null,
            'census_source' => $release?->url, 'census_name' => $row['name'] ?? null, 'water' => $water, 'water_source' => $waterSource];
        $published = $village
            ? app(MyPlaceFacts::class)->village($village, '9')
            : app(MyPlaceFacts::class)->district(['state' => '09', 'district' => '151']);
        if (in_array($published['census_status'], ['available', 'conflicting_values'], true)) {
            foreach (['population', 'households', 'literacy', 'census_source', 'census_name', 'census_status', 'scope'] as $key) {
                if (array_key_exists($key, $published)) {
                    $facts[$key] = $published[$key];
                }
            }
        }

        return $facts;
    }
}
