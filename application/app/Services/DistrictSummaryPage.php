<?php

namespace App\Services;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DistrictSummaryPage
{
    public static function enabled(array $identity): bool
    {
        return in_array($identity['state'].':'.$identity['district'], ['09:151', '27:522', '19:342', '07:094', '09:146'], true);
    }

    public function show(array $identity, string $topic, callable $support): View
    {
        $state = $identity['state'];
        $district = $identity['district'];
        $pilot = $state === '09' && $district === '151';
        $input = request()->validate(['q' => 'nullable|string|max:120', 'vp' => 'nullable|integer|min:1|max:100000']);
        $search = trim($input['q'] ?? '');
        $hi = app()->getLocale() === 'hi' || request('lang') === 'hi';
        $directory = app(DistrictDashboardData::class);
        $villages = $directory->villagePage($district, $state, 2011, $search, $topic === 'overview' ? 1 : (int) ($input['vp'] ?? 1), $topic === 'overview' ? 10 : 20);
        $villages->appends(array_filter(['topic' => $topic === 'places' ? $topic : null, 'lang' => $hi ? 'hi' : null, 'q' => $search], fn ($v) => $v !== null && $v !== ''));
        if (request()->query('part') === 'village-list') {
            return view('pilibhit-summary-villages', compact('identity', 'villages', 'hi', 'search', 'topic'));
        }
        $data = $directory->published($district, $state, 2011, true, true, $topic === 'overview');
        abort_unless($data, 404);
        $previews = [];
        $water = null;
        if ($topic === 'overview' && $pilot) {
            $service = app(PilibhitDistrictPreviews::class);
            $previews = $service->summaries() + $service->anchorTopics() + ['hasMap' => true];
            try {
                $water = app(JjmWaterSnapshot::class)->pilibhit();
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 503) {
                    throw $error;
                }
            }
        } elseif ($topic === 'overview') {
            $records = $support($data);
            $previews = $this->previews($identity, $records);
            $water = $previews['water'];
        }

        return view('pilibhit-summary-dashboard', compact('identity', 'data', 'villages', 'hi', 'search', 'topic', 'pilot', 'previews', 'water'));
    }

    private function previews(array $identity, array $records): array
    {
        $key = $identity['state'].':'.$identity['district'];
        $profiles = json_decode(file_get_contents(database_path('fixtures/district-summary-observations.json')), true, flags: JSON_THROW_ON_ERROR);
        $observations = $profiles[$key] ?? [];
        $layers = json_decode(file_get_contents(database_path('fixtures/district-summary-satellite.json')), true, flags: JSON_THROW_ON_ERROR);
        $satellite = $layers[$records['mapFile'] ?? ''] ?? null;
        $water = isset($observations['water']) ? ['summary' => $observations['water']] : null;
        $soil = $observations['soil'] ?? null;
        $hasMap = $records['sourceMap'] !== null || $satellite !== null;
        $elections = null;
        $seats = $records['constituencyLinks'];
        if (! empty($seats['available']) && ! empty($seats['assembly'])) {
            $rows = [];
            foreach (['assembly' => 'AC', 'parliamentary' => 'PC'] as $type => $label) {
                foreach ($seats[$type] ?? [] as $seat) {
                    if (isset($seat['name'], $seat['code'], $seat['url'])) {
                        $rows[] = ['type' => $label] + $seat;
                    }
                }
            }
            if ($rows) {
                $elections = ['assembly' => count($seats['assembly']), 'parliamentary' => count($seats['parliamentary'] ?? []), 'rows' => array_slice($rows, 0, 5)];
            }
        }
        $parent = $observations['market_parent'] ?? null;
        $directory = $observations['directory'] ?? $this->panchayats($identity, $parent);
        $prices = collect();
        $offices = [];
        if ($parent) {
            [$state, $district] = $parent;
            $places = app(MyPlaceDirectory::class);
            $st = collect($places->states())->firstWhere('code', $state);
            $dt = collect($places->districts($state))->firstWhere('code', $district);
            if ($st && $dt) {
                $scope = ['state' => $st, 'district' => $dt];
                $prices = collect(app(OfficialMarketPrices::class)->overviewRecords($scope))
                    ->filter(fn ($row) => filled($row['commodity'] ?? null) && filled($row['unit'] ?? null) && is_numeric($row['modal'] ?? null))
                    ->sortBy([['date', 'desc'], ['commodity', 'asc'], ['market', 'asc']])
                    ->unique(fn ($row) => strtolower(trim($row['commodity'])))->take(10)->values();
                $offices = Cache::remember("district-summary-offices:v1:$state:$district", 3600, function () use ($dt, $state, $district) {
                    $service = app(CommunityAreaIssues::class);
                    $context = $service->context('district', $dt['name'], $state, $district);
                    if (! $context['available']) {
                        return [];
                    }
                    $feed = $service->feed($context);
                    $people = app(IssueAuthorities::class)->holders($feed['authorities']->take(2)->pluck('id')->all());
                    $offices = $feed['authorities']->take(2)->map(function ($office) use ($people) {
                        $holder = $people->get($office->id)?->sortByDesc('verified_at')->first();

                        return ['title' => $office->title, 'url' => $office->url, 'holder' => $holder?->display_name, 'recorded' => $holder?->verified_at ? substr($holder->verified_at, 0, 10) : null];
                    });

                    return $offices->concat($feed['contacts']->filter(fn ($contact) => filled($contact['office'] ?? null) && filled($contact['source_url'] ?? null))->map(fn ($contact) => ['title' => $contact['office'], 'url' => $contact['source_url'], 'holder' => null, 'recorded' => null]))->unique('title')->take(2)->values()->all();
                });
            }
        }

        return compact('water', 'soil', 'satellite', 'elections', 'directory', 'prices', 'offices', 'hasMap') + ['rain' => []];
    }

    private function panchayats(array $identity, ?array $parent): ?array
    {
        if ($identity['state'] === '09' && ($parent[0] ?? null) === '9') {
            $path = database_path('fixtures/up-panchayat-directory-9-'.$parent[1].'.json');
            if (! is_file($path)) {
                return null;
            }

            return Cache::remember('district-gp-preview:'.$parent[1].':'.filemtime($path), 86400, function () use ($path, $parent) {
                $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $gps = $linked = [];
                foreach ($fixture['villages'] as $village) {
                    if ((string) $village['district_code'] !== $parent[1]) {
                        continue;
                    }
                    foreach ($village['panchayats'] ?? [] as $gp) {
                        $gps[$gp['code']]['name'] = $gp['name'];
                        $gps[$gp['code']]['codes'][$village['code']] = true;
                        $linked[$village['code']] = true;
                    }
                }

                return $gps ? ['panchayats' => count($gps), 'villages' => count($linked), 'parent' => $parent, 'rows' => collect($gps)->sortBy('name')->take(5)->map(fn ($gp) => ['name' => $gp['name'], 'villages' => count($gp['codes'])])->values()->all()] : null;
            });
        }
        if (! is_file(database_path('fixtures/gram-panchayat-directory.sqlite'))) {
            return null;
        }
        $list = app(GramPanchayatDirectory::class)->listing('census', (int) $identity['state'], (int) $identity['district'], null, '', 1, 5);

        return $list['items'] ? ['rows' => $list['items']] : null;
    }
}
