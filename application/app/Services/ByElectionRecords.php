<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ByElectionRecords
{
    private const ROOT = 'election-by-elections/structured/';

    public function __construct(private ArchiveFiles $files) {}

    public function index(): Collection
    {
        $path = self::ROOT.'index.json';
        if (! $this->files->exists($path)) {
            return collect();
        }
        $index = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);

        return collect($index['records']);
    }

    /** Figures calculated only from the selected by-election's published candidate rows. */
    public static function figures(array $record): array
    {
        $rows = collect($record['candidates'] ?? []);
        $people = $rows->reject(fn ($row) => ($row['nota'] ?? false) || strtoupper($row['party'] ?? '') === 'NOTA');
        $declared = $people->where('role', 'reported_winner');
        $ranked = $people->filter(fn ($row) => is_numeric($row['votes'] ?? null) && $row['votes'] >= 0)
            ->sortByDesc('votes')->values();
        $complete = $people->count() >= 2 && $rows->every(fn ($row) => is_numeric($row['votes'] ?? null) && $row['votes'] >= 0)
            && ($record['status'] ?? '') !== 'needs_review'
            && (! isset($record['reported_contested']) || $people->count() === (int) $record['reported_contested']);
        $total = $complete ? $rows->sum('votes') : null;
        $leading = $complete && $ranked->count() >= 2 && $ranked[0]['votes'] > $ranked[1]['votes'] ? $ranked[0] : null;
        $nota = $rows->diffKeys($people);
        $groups = $total > 0 ? $rows->groupBy(fn ($row) => ($row['nota'] ?? false) ? 'NOTA' : (filled($row['party'] ?? null) ? $row['party'] : '—'))
            ->map->sum('votes')->sortDesc() : collect();

        return [
            'declared' => $declared->count() === 1 ? $declared->first() : null,
            'leading' => $leading,
            'leaders' => $ranked->take(3),
            'candidates' => $people->count(),
            'total' => $total,
            'nota_votes' => $nota->count() === 1 ? ($nota->first()['votes'] ?? null) : null,
            'groups' => $groups,
        ];
    }

    public function record(array $entry): array
    {
        abort_unless(preg_match('/^[a-f0-9]{24}-[a-f0-9]{16}\.json$/', $entry['file']), 503);
        $path = $this->files->path(self::ROOT.$entry['file']);
        abort_unless(is_file($path) && filesize($path) <= 4000000 && hash_equals($entry['sha256'], hash_file('sha256', $path)), 503, 'Result source integrity check failed.');
        $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless($record['id'] === $entry['id'], 503, 'Result identifier differs.');

        return $record;
    }
}
