<?php

namespace App\Services;

use PDO;
use PDOException;

class NationalPanchayatDetails
{
    private function root(): string
    {
        return config('services.egramswaraj.national_details_path', storage_path('app/egramswaraj-national-details'));
    }

    public function coverage(): array
    {
        $path = $this->root().'/index.json';
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1024 * 1024) {
            return [];
        }
        $index = json_decode(file_get_contents($path), true);

        return is_array($index) && ($index['schema'] ?? null) === 1 && ($index['publication_enabled'] ?? false) === true ? $index : [];
    }

    private function database(string $state): ?PDO
    {
        if (! preg_match('/^[1-9][0-9]?$/D', $state) || $this->coverage() === []) {
            return null;
        }
        $path = $this->root().'/state-'.$state.'.sqlite';
        if (! is_file($path) || ! is_readable($path) || dirname(realpath($path)) !== realpath($this->root())) {
            return null;
        }
        try {
            $db = new PDO('sqlite:file:'.$path.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $meta = $db->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);

            return ($meta['schema'] ?? null) === '1' && ($meta['state'] ?? null) === $state && ($meta['financial_year'] ?? null) === '2026-2027' && ($meta['encoding'] ?? null) === 'zlib-json' ? $db : null;
        } catch (PDOException) {
            return null;
        }
    }

    private function decode(array $row, string $state, string $gp): ?array
    {
        $json = @gzuncompress($row['payload'], 2 * 1024 * 1024);
        $value = is_string($json) ? json_decode($json, true) : null;
        $url = parse_url($row['source_url']);
        parse_str($url['query'] ?? '', $query);
        if (! is_array($value) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'egramswaraj.gov.in'
            || ! preg_match('/^[a-f0-9]{64}$/D', $row['source_sha256'])) {
            return null;
        }
        if ($row['kind'] === 'activities') {
            $valid = $row['level'] === 'complete'
                ? ($url['path'] ?? null) === '/activitySectionData.do' && ($query['activityCd'] ?? null) === $row['record_key']
                : ($url['path'] ?? null) === '/getActivityList.do' && ($query['planCd'] ?? null) === ($value['plan'] ?? null);
            if (! $valid) {
                return null;
            }
        } elseif (($url['path'] ?? null) !== '/voucherWiseReport.do' || ($query['state'] ?? null) !== $state || ($query['village'] ?? null) !== $gp || ($query['finYear'] ?? null) !== '2026-2027') {
            return null;
        }

        return $value + ['record_key' => $row['record_key'], 'kind' => $row['kind'], 'level' => $row['level'], 'source_url' => $row['source_url'], 'source_sha256' => $row['source_sha256'], 'captured_on' => $row['captured_on']];
    }

    public function tables(string $state, string $gp, int $voucherPage = 1, int $activityPage = 1): array
    {
        if (! preg_match('/^[1-9][0-9]{0,8}$/D', $gp) || ! ($db = $this->database($state))) {
            return [];
        }
        $result = ['vouchers' => [], 'activities' => [], 'notes' => []];
        foreach (['vouchers' => $voucherPage, 'activities' => $activityPage] as $kind => $page) {
            $clause = $kind === 'activities' ? "kind='activities'" : "kind IN ('payments','receipts','contra','journal')";
            $query = $db->prepare('SELECT * FROM records WHERE gp=? AND '.$clause.' ORDER BY date DESC,record_key LIMIT 51 OFFSET ?');
            $query->bindValue(1, $gp);
            $query->bindValue(2, (max(1, min(100000, $page)) - 1) * 50, PDO::PARAM_INT);
            $query->execute();
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $result[$kind] = ['items' => array_values(array_filter(array_map(fn ($row) => $this->decode($row, $state, $gp), array_slice($rows, 0, 50)))), 'more' => count($rows) > 50, 'page' => max(1, min(100000, $page))];
            $count = $db->prepare('SELECT COUNT(*) FROM records WHERE gp=? AND '.$clause);
            $count->execute([$gp]);
            $result[$kind]['total'] = (int) $count->fetchColumn();
        }
        $query = $db->prepare("SELECT kind,note,MIN(url) AS url,COUNT(*) AS count FROM requests WHERE gp=? AND status='note' GROUP BY kind,note ORDER BY kind LIMIT 20");
        $query->execute([$gp]);
        $result['notes'] = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($result['notes'] as &$note) {
            if (parse_url($note['url'], PHP_URL_SCHEME) !== 'https' || parse_url($note['url'], PHP_URL_HOST) !== 'egramswaraj.gov.in') {
                $note['url'] = null;
            }
        }
        unset($note);

        return $result;
    }

    public function listingCounts(string $state, array $panchayats): array
    {
        $panchayats = array_values(array_unique(array_filter(array_map('strval', $panchayats), fn ($gp) => preg_match('/^[1-9][0-9]{0,8}$/D', $gp))));
        if ($panchayats === [] || count($panchayats) > 50 || ! ($db = $this->database($state))) {
            return [];
        }
        $query = $db->prepare("SELECT gp,SUM(kind!='activities') AS vouchers,SUM(kind='activities') AS activities FROM records WHERE gp IN (".implode(',', array_fill(0, count($panchayats), '?')).') GROUP BY gp');
        $query->execute($panchayats);

        return array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'gp');
    }

    public function activity(string $state, string $gp, string $activity): ?array
    {
        if (! preg_match('/^[1-9][0-9]{0,8}$/D', $gp) || ! preg_match('/^[1-9][0-9]{0,11}$/D', $activity) || ! ($db = $this->database($state))) {
            return null;
        }
        $query = $db->prepare("SELECT * FROM records WHERE gp=? AND kind='activities' AND record_key=?");
        $query->execute([$gp, $activity]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decode($row, $state, $gp) : null;
    }
}
