<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use PDO;

class NationalPanchayatSummaries
{
    public function coverage(): array
    {
        $path = config('services.egramswaraj.national_index_path', database_path('fixtures/egramswaraj-national/index.json'));
        if (! is_string($path) || ! is_file($path) || filesize($path) > 1024 * 1024) {
            return [];
        }
        $index = json_decode(file_get_contents($path), true);

        return is_array($index) && ($index['schema'] ?? null) === 1 && ($index['publication_enabled'] ?? false) === true && is_array($index['states'] ?? null) ? $index : [];
    }

    private function database(string $state): ?PDO
    {
        if (! preg_match('/^[1-9][0-9]?$/D', $state)) {
            return null;
        }
        $index = $this->coverage();
        $entry = $index['states'][$state] ?? null;
        if (! is_array($entry) || ($entry['file'] ?? null) !== 'state-'.$state.'.sqlite'
            || ! is_string($entry['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])) {
            return null;
        }
        $root = dirname(config('services.egramswaraj.national_index_path', database_path('fixtures/egramswaraj-national/index.json')));
        $path = $root.DIRECTORY_SEPARATOR.$entry['file'];
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) > 100 * 1024 * 1024 || filesize($path) !== ($entry['bytes'] ?? null)
            || dirname(realpath($path)) !== realpath($root)) {
            return null;
        }
        $key = 'national-panchayat:sha:'.hash('sha256', $path.':'.$entry['sha256'].':'.filemtime($path).':'.filesize($path));
        if (! Cache::remember($key, 86400, fn () => hash_equals($entry['sha256'], hash_file('sha256', $path)))) {
            return null;
        }
        $db = new PDO('sqlite:file:'.$path.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $meta = $db->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($meta['schema'] ?? null) !== '1' || ($meta['state'] ?? null) !== $state || ($meta['encoding'] ?? null) !== 'zlib-json') {
            return null;
        }

        return $db;
    }

    public function record(string $state, string $gp): ?array
    {
        if (! preg_match('/^[1-9][0-9]{0,8}$/D', $gp) || ! ($db = $this->database($state))) {
            return null;
        }
        $query = $db->prepare('SELECT * FROM panchayats WHERE gp=?');
        $query->execute([$gp]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            return null;
        }
        $report = null;
        if (is_string($row['report_blob'])) {
            $json = @gzuncompress($row['report_blob'], 2 * 1024 * 1024);
            $report = is_string($json) ? json_decode($json, true) : null;
            if (! is_array($report) || ! is_string($report['source_url'] ?? null)
                || $report['source_url'] !== 'https://egramswaraj.gov.in/FileRedirect.jsp?FD=FinancialYear2026-2027/'.$state.'&name='.$gp.'.html'
                || ! is_array($report['monthly'] ?? null) || ! is_array($report['notes'] ?? null)) {
                return null;
            }
        }

        return ['state' => $state, 'gp' => $gp, 'name' => $row['name'], 'native_name' => $row['native_name'], 'status' => $row['status'],
            'native_districts' => json_decode($row['native_districts'], true), 'source_contexts' => json_decode($row['source_contexts'], true),
            'identity_match' => (bool) $row['identity_match'], 'summary' => $report];
    }

    public function listing(string $state, string $search, int $page): array
    {
        $db = $this->database($state);
        if (! $db) {
            return ['items' => [], 'more' => false];
        }
        $query = $db->prepare(<<<'SQL'
            SELECT gp,name,status,native_districts,source_contexts,captured_on FROM panchayats
            WHERE (?='' OR instr(lower(name),lower(?))>0 OR gp=?
                OR EXISTS(SELECT 1 FROM json_each(native_districts) d WHERE instr(lower(d.value),lower(?))>0)
                OR EXISTS(SELECT 1 FROM json_each(source_contexts) c WHERE instr(lower(json_extract(c.value,'$.gp_nam')),lower(?))>0 OR instr(lower(json_extract(c.value,'$.zp_name')),lower(?))>0))
            ORDER BY name COLLATE NOCASE,gp LIMIT 51 OFFSET ?
            SQL);
        for ($position = 1; $position <= 6; $position++) {
            $query->bindValue($position, $search);
        }
        $query->bindValue(7, max(0, $page - 1) * 50, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $districts = array_values(json_decode($row['native_districts'], true) ?: []);
            foreach (json_decode($row['source_contexts'], true) ?: [] as $source) {
                if (! empty($source['zp_name'])) {
                    $districts[] = $source['zp_name'];
                }
            }
            $row['district'] = implode(' · ', array_unique($districts));
        }
        unset($row);

        return ['items' => array_slice($rows, 0, 50), 'more' => count($rows) > 50];
    }

    public function forSelection(array $context, ?string $gp): ?array
    {
        if ($gp === null) {
            return null;
        }
        $record = $this->record((string) ($context['state']['code'] ?? ''), $gp);
        if (! $record || ! array_key_exists((string) ($context['district']['code'] ?? ''), $record['native_districts'] ?? []) || ! $record['summary']) {
            return null;
        }
        if (! empty($context['village'])) {
            $membership = app(GramPanchayatDirectory::class)->forVillage((int) $context['state']['code'], (int) $context['village']['code']);
            if ((string) ($membership['gp'] ?? '') !== $gp || ($record['status'] === 'available' && ! $record['identity_match'])) {
                return null;
            }
        }
        $record['url'] = route('panchayat-accounts.show', ['state' => $record['state'], 'gp' => $gp]);

        return $record;
    }
}
