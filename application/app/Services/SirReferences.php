<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SirReferences
{
    public function documents(): Collection
    {
        $documents = collect();
        $releases = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'like', 'sir-%')->where('r.status', 'accepted')->orderByDesc('r.id')->select('r.payload')->get();
        foreach ($releases as $release) {
            $data = json_decode($release->payload, true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['parts'] ?? [] as $part) {
                $url = $part['source_url'] ?? $data['source_url'] ?? '';
                $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'eci.gov.in' || str_ends_with($host, '.eci.gov.in') || str_ends_with($host, '.gov.in') || str_ends_with($host, '.nic.in'))) {
                    continue;
                }
                $pages = array_values(array_filter($part['pages'] ?? [], fn ($page): bool => is_int($page) && $page > 0));
                $pdf = explode('#', $url)[0];
                $documents->push([
                    'label' => $part['name'], 'type' => 'SIR reference', 'rank' => 7,
                    'period' => $data['edition'] ?? 'SIR', 'year' => $data['revision_year'] ?? null,
                    'published' => $data['generated_date'] ?? null,
                    'title' => $data['title'] ?? 'SIR document', 'ac' => $part['ac'] ?? $data['ac'] ?? null,
                    'ac_name' => $part['ac_name'] ?? $data['ac_name'] ?? '', 'part' => $part['part'],
                    'pages' => $pages, 'pdf_url' => $pdf, 'url' => $pdf.($pages ? '#page='.$pages[0] : ''),
                    'source_host' => $host, 'eci_hosted' => $host === 'eci.gov.in' || str_ends_with($host, '.eci.gov.in'),
                ]);
            }
        }

        return $documents->unique(fn (array $row): string => $row['pdf_url'].'|'.$row['year'].'|'.$row['period'].'|'.$row['ac'].'|'.$row['part'])->values();
    }

    public function search(Collection $documents, string $query, ?int $year): Collection
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return $documents->filter(function (array $row) use ($terms, $year): bool {
            if ($year !== null && (int) $row['year'] !== $year) {
                return false;
            }
            $text = mb_strtolower(implode(' ', ['SIR', $row['label'], $row['title'], $row['period'], $row['year'], $row['ac'], $row['ac_name'], 'part', $row['part']]));
            foreach ($terms as $term) {
                if (! str_contains($text, $term)) {
                    return false;
                }
            }

            return true;
        })->values();
    }
}
