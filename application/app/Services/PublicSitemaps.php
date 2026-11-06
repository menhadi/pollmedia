<?php

namespace App\Services;

use App\Http\Controllers\PlaceController;
use App\Http\Controllers\SitemapController;
use PDO;
use RuntimeException;
use XMLWriter;

class PublicSitemaps
{
    public function build(): array
    {
        $lock = fopen(storage_path('app/public-sitemap-build.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open sitemap build lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another sitemap build is already running.');
        }
        try {
            return $this->buildLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function buildLocked(): array
    {
        $counts = $this->publish([
            'pages' => $this->pages(),
            'villages' => $this->villages(),
        ], public_path('sitemaps'));
        $temporary = public_path('sitemap-'.bin2hex(random_bytes(6)).'.tmp');
        if (! copy(public_path('sitemaps/index.xml'), $temporary) || ! rename($temporary, public_path('sitemap.xml'))) {
            throw new RuntimeException('Cannot publish the static sitemap entry point.');
        }

        return $counts;
    }

    private function pages(): iterable
    {
        $xml = simplexml_load_string(app(SitemapController::class)->buildXml(app(PlaceController::class)));
        if ($xml === false) {
            throw new RuntimeException('Legacy sitemap could not be parsed.');
        }
        $urls = ['https://pollmedia.org/'];
        foreach ($xml->url as $row) {
            $url = (string) $row->loc;
            if ($url === 'https://pollmedia.org') {
                $url .= '/';
            }
            if (preg_match('~/india/(?:district|pc|ac)/~', $url)) {
                continue;
            }
            // Historical seat identities are represented by the named overview URL.
            if (str_contains($url, '/india/constituencies/')) {
                $url = strtok($url, '?');
            }
            $urls[] = $url;
        }
        foreach (app(DistrictDashboardUrls::class)->all() as $district) {
            $urls[] = 'https://pollmedia.org'.$district['path'];
        }
        yield from array_unique($urls);
    }

    private function villages(): iterable
    {
        $path = storage_path('app/electoral-villages/links.sqlite');
        if (! is_file($path)) {
            throw new RuntimeException('Village directory is missing.');
        }
        $db = new PDO('sqlite:file:'.$path.'?mode=ro');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA query_only = ON');
        $rows = $db->query('SELECT d.lgd, d.name, d.district, s.name AS state_name FROM directory d JOIN states s ON s.code = d.state ORDER BY d.lgd');
        foreach ($rows as $row) {
            yield 'https://pollmedia.org'.NamedPlaceUrls::path('lgd-village', $row['state_name'], $row['district'], $row['name'], $row['lgd']);
        }
    }

    public function publish(array $sections, string $directory, int $chunkSize = 10000): array
    {
        if ($chunkSize < 1 || $chunkSize > 10000) {
            throw new RuntimeException('Sitemap chunk size must be between 1 and 10000.');
        }
        $generation = 'seo-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        $target = $directory.'/'.$generation;
        if (! mkdir($target, 0755, true)) {
            throw new RuntimeException('Cannot create sitemap directory.');
        }
        $files = [];
        $counts = [];
        foreach ($sections as $section => $urls) {
            if (! preg_match('/^[a-z]+$/', $section)) {
                throw new RuntimeException('Invalid sitemap section.');
            }
            $writer = null;
            $count = 0;
            foreach ($urls as $url) {
                if (! str_starts_with($url, 'https://pollmedia.org/') || strlen($url) > 2048) {
                    throw new RuntimeException('Invalid public sitemap URL.');
                }
                if ($count % $chunkSize === 0) {
                    if ($writer !== null) {
                        $writer->endElement();
                        $writer->endDocument();
                        $writer->flush();
                    }
                    $file = $section.'-'.str_pad((string) (intdiv($count, $chunkSize) + 1), 3, '0', STR_PAD_LEFT).'.xml';
                    $writer = new XMLWriter;
                    if (! $writer->openUri($target.'/'.$file)) {
                        throw new RuntimeException('Cannot write sitemap.');
                    }
                    $writer->startDocument('1.0', 'UTF-8');
                    $writer->startElementNS(null, 'urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
                    $files[] = 'https://pollmedia.org/sitemaps/'.$file;
                }
                $writer->startElement('url');
                $writer->writeElement('loc', $url);
                $writer->endElement();
                $count++;
                if ($count % 1000 === 0) {
                    usleep(100000);
                }
            }
            if ($writer !== null) {
                $writer->endElement();
                $writer->endDocument();
                $writer->flush();
            }
            $counts[$section] = $count;
        }
        if (array_sum($counts) === 0) {
            throw new RuntimeException('Refusing to publish an empty sitemap.');
        }
        // Keep child URLs stable across daily refreshes so Google can reuse its discovery history.
        foreach ($files as $url) {
            $file = basename($url);
            $temporaryChild = $directory.'/'.$file.'.'.$generation.'.tmp';
            if (! copy($target.'/'.$file, $temporaryChild) || ! rename($temporaryChild, $directory.'/'.$file)) {
                throw new RuntimeException('Cannot publish sitemap child.');
            }
        }
        $index = new XMLWriter;
        $temporary = $directory.'/index-'.$generation.'.tmp';
        $index->openUri($temporary);
        $index->startDocument('1.0', 'UTF-8');
        $index->startElementNS(null, 'sitemapindex', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($files as $file) {
            $index->startElement('sitemap');
            $index->writeElement('loc', $file);
            $index->endElement();
        }
        $index->endElement();
        $index->endDocument();
        $index->flush();
        if (! rename($temporary, $directory.'/index.xml')) {
            throw new RuntimeException('Cannot publish sitemap index.');
        }
        foreach (glob($directory.'/seo-*', GLOB_ONLYDIR) as $old) {
            if (! preg_match('/^seo-[0-9]{14}-[a-f0-9]{6}$/', basename($old)) || is_link($old) || filemtime($old) > time() - 7 * 86400) {
                continue;
            }
            foreach (glob($old.'/*.xml') as $file) {
                if (is_file($file) && ! is_link($file)) {
                    unlink($file);
                }
            }
            if (count(scandir($old)) === 2) {
                rmdir($old);
            }
        }

        return $counts + ['files' => count($files)];
    }
}
