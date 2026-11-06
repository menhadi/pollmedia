<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

class UrbanCensusLinks
{
    public function __construct(private ?string $path = null) {}

    public function forPlace(string $kind, string $code, string $state): array
    {
        $path = $this->path ?? storage_path('app/postal-places/urban-census.sqlite');
        if (! in_array($kind, ['village', 'local-body'], true) || ! ctype_digit($code) || ! ctype_digit($state)
            || ! is_file($path) || ! Schema::hasTable('census_catalogue_rows')) {
            return [];
        }
        $db = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $db->prepare('SELECT record,basis,label,source FROM links WHERE kind=? AND code=? AND state=? ORDER BY record');
        $q->execute([$kind, $code, $state]);
        $links = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($links === []) {
            return [];
        }
        $records = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.year', 2011)->where('e.status', 'published')->whereIn('c.level', ['TOWN', 'WARD'])->whereIn('c.id', array_column($links, 'record'))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('census_publications as p')->whereColumn('p.edition_id', 'e.id')->whereColumn('p.source_key', 'e.source_key');
            })->select('c.id', 'c.name', 'c.level', 'c.values', 'c.geography', 'c.state_code', 'e.source_url')->get()->keyBy('id');
        $result = [];
        foreach ($links as $link) {
            $record = $records[$link['record']] ?? null;
            if (! $record) {
                continue;
            }
            $values = json_decode($record->values, true);
            $geography = json_decode($record->geography ?? '{}', true);
            $population = $values['TOT_P'] ?? null;
            $children = $values['P_06'] ?? null;
            $literate = $values['P_LIT'] ?? null;
            $result[] = [...$link, 'record_name' => $record->name, 'level' => $record->level, 'url' => route('civic.place', ['record' => $record->id]),
                'source_url' => $record->source_url, 'geography' => $geography, 'historical_state_note' => match (true) {
                    $state === '36' && $record->state_code === '28' => 'Recorded under undivided Andhra Pradesh in Census 2011; Telangana was formed in 2014.',
                    $state === '37' && $record->state_code === '01' => 'Recorded under Jammu and Kashmir in Census 2011, before Ladakh became a Union Territory in 2019.',
                    $state === '38' && in_array($record->state_code, ['25', '26'], true) => 'Recorded under the separate 2011 Union Territories, before Dadra and Nagar Haveli and Daman and Diu were merged in 2020.',
                    default => null,
                }, 'historical_state_source' => match ($state) {
                    '36' => 'https://www.telangana.gov.in/departments/legislative-affairs/',
                    '37' => 'https://ldma.ladakh.gov.in/aboutleh.html',
                    '38' => 'https://ddd.gov.in/introduction/',
                    default => null,
                }, 'figures' => ['population' => $population, 'households' => $values['No_HH'] ?? null, 'literate' => $literate,
                    'literacy_rate' => is_numeric($population) && is_numeric($children) && is_numeric($literate) && $population > $children ? round(100 * $literate / ($population - $children), 2) : null]];
        }

        return $result;
    }

    public function overview(array $village): ?array
    {
        if (isset($village['census']['population'])) {
            return null;
        }
        $links = array_values(array_filter($this->forPlace('village', (string) ($village['lgd'] ?? ''), (string) ($village['state'] ?? '')), fn (array $link): bool => $link['basis'] !== 'coverage-only'));

        return count($links) === 1 ? $links[0] : null;
    }
}
