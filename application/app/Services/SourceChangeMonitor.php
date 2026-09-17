<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SourceChangeMonitor
{
    public function check(string $key, string $url): string
    {
        $source = DB::table('data_sources')->where('key', $key)->first();
        if (! $source || (config('source-monitor.sources')[$key] ?? null) !== $url || $source->url !== $url) {
            throw new RuntimeException('Source is not configured for monitoring.');
        }
        $values = ['data_source_id' => $source->id, 'checked_at' => now()];
        try {
            $bundle = config('source-monitor.ca_bundle');
            $options = ['allow_redirects' => false, 'verify' => $bundle && is_file($bundle) ? $bundle : true];
            $response = Http::connectTimeout(10)->timeout(30)->withOptions($options)->get($url);
            if (! $response->successful() || strlen($response->body()) > 2000000) {
                throw new RuntimeException('Source response could not be checked.');
            }
            $content = $this->tableContent($response->body());
            $hash = hash('sha256', $content);
            $baseline = DB::table('source_checks')->where('data_source_id', $source->id)->where('status', 'baseline')->orderBy('id')->first();
            $status = ! $baseline ? 'baseline' : ($baseline->sha256 === $hash ? 'unchanged' : 'changed');
            $values += ['status' => $status, 'sha256' => $hash, 'content' => $content];
        } catch (Throwable $error) {
            $status = 'failed';
            $values += ['status' => $status, 'error' => 'Official page unavailable or expected directory tables missing.'];
        }
        DB::table('source_checks')->insert($values);

        return $status;
    }

    private function tableContent(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
            $tables = [];
            foreach ($document->getElementsByTagName('table') as $table) {
                $rows = [];
                foreach ($table->getElementsByTagName('tr') as $row) {
                    $cells = [];
                    foreach ($row->childNodes as $cell) {
                        if (in_array($cell->nodeName, ['th', 'td'])) {
                            $cells[] = trim(preg_replace('/\\s+/u', ' ', $cell->textContent));
                        }
                    }
                    if ($cells !== []) {
                        $rows[] = $cells;
                    }
                }
                if ($rows !== []) {
                    $tables[] = $rows;
                }
            }
            if ($tables === []) {
                throw new RuntimeException('No directory tables found.');
            }

            return json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
