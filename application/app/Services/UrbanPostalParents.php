<?php

namespace App\Services;

use PDO;

class UrbanPostalParents
{
    private ?PDO $database = null;

    public function __construct(private ?string $path = null) {}

    public function forBody(string $code, string $state): array
    {
        $path = $this->path ?? storage_path('app/postal-places/urban-parents.sqlite');
        if (! is_file($path) || ! ctype_digit($code) || ! ctype_digit($state)) {
            return [];
        }
        $this->database ??= new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $query = $this->database->prepare('SELECT level,code,district,name,coverage FROM parents WHERE body=? AND state=? ORDER BY level,name,code');
        $query->execute([$code, $state]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function scope(string $state, string $level, string $code): ?array
    {
        $path = $this->path ?? storage_path('app/postal-places/urban-parents.sqlite');
        if (! is_file($path) || ! in_array($level, ['district', 'subdistrict'], true) || ! ctype_digit($state) || ! ctype_digit($code)) {
            return null;
        }
        $this->database ??= new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $query = $this->database->prepare('SELECT DISTINCT body,name,district FROM parents WHERE state=? AND level=? AND code=? ORDER BY body');
        $query->execute([$state, $level, $code]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] || count(array_unique(array_column($rows, 'name'))) !== 1 || count(array_unique(array_column($rows, 'district'))) !== 1) {
            return null;
        }

        return ['state' => $state, 'level' => $level, 'code' => $code, 'name' => $rows[0]['name'], 'district' => $rows[0]['district'], 'bodies' => array_column($rows, 'body')];
    }
}
