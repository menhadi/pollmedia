<?php

namespace App\Services;

class SchemeCatalogue
{
    public function all(): array
    {
        return $this->normalise(json_decode(file_get_contents(database_path('fixtures/my-place-schemes.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    public function normalise(array $catalogue): array
    {
        $catalogue['checked_on'] = $this->date($catalogue['checked_on'] ?? null) ? $catalogue['checked_on'] : null;
        $questions = [];
        $definitions = [];
        foreach (is_array($catalogue['questions'] ?? null) ? $catalogue['questions'] : [] as $field => $label) {
            if (! is_string($field) || ! preg_match('/^[a-z][a-z0-9_]{0,60}$/', $field) || in_array($field, ['name', 'dob', 'age', 'pincode', 'state', 'district', 'interest'], true) || ! $this->bilingual($label) || ! $this->question($catalogue['question_fields'][$field] ?? null)) {
                continue;
            }
            $questions[$field] = $label;
            $definitions[$field] = $catalogue['question_fields'][$field];
        }
        $catalogue['questions'] = $questions;
        $catalogue['question_fields'] = $definitions;
        $catalogue['category_labels'] = array_filter(is_array($catalogue['category_labels'] ?? null) ? $catalogue['category_labels'] : [], fn ($label) => $this->bilingual($label));
        if (! $this->bilingual($catalogue['exclusion_help'] ?? null)) {
            $catalogue['exclusion_help'] = ['en' => 'Check the official exclusion conditions before answering.', 'hi' => 'उत्तर देने से पहले आधिकारिक अपवर्जन शर्तें जाँचें।'];
        }
        $records = [];
        $conflicts = [];
        $rejected = 0;
        foreach (is_array($catalogue['schemes'] ?? null) ? $catalogue['schemes'] : [] as $scheme) {
            if (! is_array($scheme) || ! is_string($scheme['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $scheme['id'] ?? '') || ! $this->bilingual($scheme['name'] ?? null) || ! $this->bilingual($scheme['benefit'] ?? null) || ! $this->bilingual($scheme['documents'] ?? null) || ! $this->bilingual($scheme['scope_name'] ?? null) || ! $this->url($scheme['source'] ?? null) || ! $this->url($scheme['apply'] ?? null)) {
                $rejected++;

                continue;
            }
            if ((! is_string($scheme['scope'] ?? null) && ! is_int($scheme['scope'] ?? null)) || ! is_string($scheme['category'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]{0,60}$/', $scheme['category'])) {
                $rejected++;

                continue;
            }
            $scope = (string) $scheme['scope'];
            $system = $scheme['source_system'] ?? '';
            if (! preg_match('/^(IN|[0-9]{1,3})$/', $scope) || ! is_string($system) || ! preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $system)) {
                $rejected++;

                continue;
            }
            $invalidDate = false;
            foreach (['checked_on', 'source_updated_at', 'valid_from', 'valid_until'] as $date) {
                if (isset($scheme[$date]) && ! $this->date($scheme[$date])) {
                    $invalidDate = true;
                }
            }
            if ($invalidDate || (! empty($scheme['valid_from']) && ! empty($scheme['valid_until']) && $scheme['valid_from'] > $scheme['valid_until'])) {
                $rejected++;

                continue;
            }
            $scheme['scope'] = $scope;
            $scheme['catalogue_key'] = $system.':'.$scope.':'.$scheme['id'];
            $scheme['checked_on'] = $scheme['checked_on'] ?? ($catalogue['checked_on'] ?? null);
            $scheme['status'] = in_array($scheme['status'] ?? '', ['listed', 'active', 'closed', 'inactive', 'unknown'], true) ? $scheme['status'] : 'unknown';
            $scheme['rules'] = is_array($scheme['rules'] ?? null) ? $scheme['rules'] : [];
            $verified = $scheme['rules'] !== [] && $this->date($scheme['checked_on']) && $scheme['checked_on'] <= now()->toDateString();
            foreach ($scheme['rules'] as $rule) {
                if (! is_array($rule)) {
                    $verified = false;

                    continue;
                }
                if (! is_string($rule['field'] ?? null)) {
                    $verified = false;

                    continue;
                }
                $field = $rule['field'];
                $definition = $catalogue['question_fields'][$field] ?? null;
                $knownField = in_array($field, ['age', 'state'], true) || ($this->bilingual($catalogue['questions'][$field] ?? null) && $this->question($definition));
                if (! $knownField || ! in_array($rule['op'] ?? '', ['eq', 'gte', 'lte', 'income_limit', 'in', 'not_in'], true) || ! array_key_exists('value', $rule) || $rule['value'] === null) {
                    $verified = false;
                }
                if (in_array($rule['op'] ?? '', ['gte', 'lte'], true) && ! is_numeric($rule['value'] ?? null)) {
                    $verified = false;
                }
                if (in_array($rule['op'] ?? '', ['in', 'not_in'], true) && (! is_array($rule['value'] ?? null) || $rule['value'] === [])) {
                    $verified = false;
                }
                if (($rule['op'] ?? '') === 'income_limit' && (! is_array($rule['value'] ?? null) || ! is_numeric($rule['value']['rural'] ?? null) || ! is_numeric($rule['value']['urban'] ?? null))) {
                    $verified = false;
                }
            }
            $scheme['rules_verified'] = $verified;
            $key = $scheme['catalogue_key'];
            if (isset($records[$key]) && $this->canonical($records[$key]) !== $this->canonical($scheme)) {
                $conflicts[$key] = true;
            } else {
                $records[$key] = $scheme;
            }
        }
        foreach ($conflicts as $key => $_) {
            unset($records[$key]);
        }
        $catalogue['schemes'] = array_values($records);
        $catalogue['quality'] = ['schema_version' => 1, 'records' => count($records), 'rejected' => $rejected, 'conflicting_ids' => array_keys($conflicts), 'source_mode' => 'reviewed_local', 'government_feed_connected' => false];

        return $catalogue;
    }

    private function bilingual(mixed $text): bool
    {
        return is_array($text) && is_string($text['en'] ?? null) && trim($text['en']) !== '' && is_string($text['hi'] ?? null) && trim($text['hi']) !== '';
    }

    private function question(mixed $definition): bool
    {
        if (! is_array($definition)) {
            return false;
        }
        if (($definition['type'] ?? '') === 'number') {
            return is_numeric($definition['min'] ?? null) && is_numeric($definition['max'] ?? null) && $definition['min'] <= $definition['max'];
        }
        if (($definition['type'] ?? '') !== 'choice' || empty($definition['options']) || ! is_array($definition['options'])) {
            return false;
        }
        foreach ($definition['options'] as $value => $label) {
            if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9_-]{1,60}$/', $value) || ! $this->bilingual($label)) {
                return false;
            }
        }

        return true;
    }

    private function date(mixed $date): bool
    {
        if (! is_string($date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function url(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }

    private function canonical(array $record): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return json_encode($sort($record), JSON_THROW_ON_ERROR);
    }
}
