<?php

namespace App\Services;

use PDO;
use PDOStatement;

class HindiPlaceNames
{
    private ?PDO $database = null;

    private ?PDOStatement $statement = null;

    private array $cache = [];

    private bool $opened = false;

    private ?array $edited = null;

    private bool $allowAmbiguous = false;

    public static function ambiguousUiToken(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), ['male', 'female', 'total', 'saline', 'name', 'soil', 'ha', 'min', 'max', 'mm', 'km', 'ph', 'ec', 'np', 'npp', 'ct', 'og'], true);
    }

    public function translated(string $text, bool $placeName = false): ?string
    {
        $this->allowAmbiguous = $placeName;
        $name = trim($text);
        if ($name === '' || mb_strlen($name) > 255 || ! preg_match('/[a-z]/i', $name)) {
            return null;
        }
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $name));
        $cacheKey = ($placeName ? 'place:' : 'text:').$key;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey] === null ? null : str_replace($name, $this->cache[$cacheKey], $text);
        }
        $translation = $this->lookup($key);
        if ($translation === null) {
            foreach (['/^(.+?)(\s+(?:district|subdistrict|village|town|at a glance|population share|district dashboard))$/iu', '/^((?:Explore|View|Open|देखें)\s+)(.+)$/u'] as $index => $pattern) {
                if (preg_match($pattern, $name, $parts)) {
                    $part = $index === 0 ? $parts[1] : $parts[2];
                    $found = $this->lookup(mb_strtolower($part));
                    if ($found !== null) {
                        $translation = $index === 0 ? $found.$parts[2] : $parts[1].$found;
                        break;
                    }
                }
            }
        }
        if ($translation === null) {
            $parts = preg_split('/(\s*[·›→|:：]\s*)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
            $changed = false;
            foreach ($parts as $index => $part) {
                if ($index % 2 === 0 && ($found = $this->lookup(mb_strtolower(trim($part)))) !== null) {
                    $parts[$index] = str_replace(trim($part), $found, $part);
                    $changed = true;
                }
            }
            if ($changed) {
                $translation = implode('', $parts);
            } elseif (preg_match('/^(.+?)(\s*[\(\[][^\)\]]+[\)\]])$/u', $name, $parts)) {
                $found = $this->lookup(mb_strtolower(trim($parts[1])));
                if ($found !== null) {
                    $translation = $found.$parts[2];
                }
            }
        }
        $this->cache[$cacheKey] = $translation;

        return $translation === null ? null : str_replace($name, $translation, $text);
    }

    private function lookup(string $key): ?string
    {
        if ((! $this->allowAmbiguous && self::ambiguousUiToken($key)) || ! preg_match('/[a-z]/i', $key)) {
            return null;
        }
        if ($this->edited === null) {
            $this->edited = [];
            foreach (config('public_translations', []) as $name => $value) {
                if (is_string($value) && trim($value) !== '' && ! self::ambiguousUiToken($name)) {
                    $this->edited[mb_strtolower(trim($name))] = $value;
                }
            }
        }
        if (isset($this->edited[$key])) {
            return $this->edited[$key];
        }
        if (! $this->opened) {
            $this->opened = true;
            $path = config('hindi-place-names.path', storage_path('app/private/hindi-place-names-20261016.sqlite'));
            if (is_file($path)) {
                $this->database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $this->database->exec('PRAGMA query_only=ON');
                $this->statement = $this->database->prepare('SELECT hi FROM display_names WHERE name = ?');
            }
        }
        if ($this->database === null) {
            return null;
        }
        $this->statement->execute([$key]);
        $value = $this->statement->fetchColumn();
        $this->statement->closeCursor();
        if ($value === false && str_contains($key, ' and ')) {
            $this->statement->execute([str_replace(' and ', ' & ', $key)]);
            $value = $this->statement->fetchColumn();
            $this->statement->closeCursor();
        }

        return $value === false ? null : $value;
    }
}
