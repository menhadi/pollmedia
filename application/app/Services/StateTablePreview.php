<?php

namespace App\Services;

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StateTablePreview
{
    private const INITIAL_ROWS = 20;

    public static function initial(Collection $records): Collection
    {
        return request()->hasAny(['table_rows', 'full_table']) ? $records : $records->take(self::INITIAL_ROWS);
    }

    public function render(string $html, Request $request): string|JsonResponse
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $requested = $request->query('table_rows');
        $hi = app()->getLocale() === 'hi';

        foreach ($document->querySelectorAll('main table') as $index => $table) {
            $head = $table->querySelector('thead > tr:last-child');
            if (! $head) {
                continue;
            }
            $bodies = [];
            $rows = [];
            foreach ($this->childElements($table) as $child) {
                if ($child->localName === 'tbody') {
                    $bodies[] = $child;
                    foreach ($this->childElements($child) as $row) {
                        if ($row->localName === 'tr') {
                            $rows[] = $row;
                        }
                    }
                }
            }
            $count = max(count($rows), (int) $table->getAttribute('data-total-rows'));
            $key = 'records-'.$index.'-'.substr(hash('sha256', $head->textContent), 0, 10);
            if ($requested !== null && $requested !== $key) {
                continue;
            }

            $numericColumns = $this->alignColumns($head, $rows);
            $table->setAttribute('data-state-table', $key);
            $table->setAttribute('data-local-data-browser', '');
            if ($requested === $key) {
                $sections = [];
                foreach ($bodies as $body) {
                    $sections[] = $body->innerHTML;
                }

                return response()->json(['table' => $key, 'bodies' => $sections, 'count' => $count, 'numericColumns' => $numericColumns])
                    ->header('Cache-Control', 'private, no-store');
            }
            if ($count <= self::INITIAL_ROWS || $request->query('full_table') === $key) {
                continue;
            }

            foreach ($rows as $position => $row) {
                if ($position >= self::INITIAL_ROWS) {
                    $row->remove();
                }
            }

            $table->setAttribute('data-deferred', 'true');
            $id = $table->getAttribute('id') ?: 'state-'.$key;
            $table->setAttribute('id', $id);
            $control = $document->createElement('div');
            $control->setAttribute('class', 'state-table-more');
            $control->setAttribute('data-table-control', $key);
            $status = $document->createElement('span');
            $status->setAttribute('role', 'status');
            $status->textContent = $hi ? "20 / {$count} पंक्तियाँ" : "20 of {$count} rows";
            $link = $document->createElement('a');
            $link->setAttribute('aria-controls', $id);
            $link->setAttribute('href', $request->fullUrlWithQuery(['full_table' => $key]).'#'.$id);
            $link->setAttribute('data-rows-url', $request->fullUrlWithQuery(['table_rows' => $key]));
            $link->textContent = $hi ? 'बाकी पंक्तियाँ दिखाएँ' : 'Load more';
            $control->append($status, $link);
            $table->after($control);
        }

        if ($requested !== null) {
            return response()->json(['message' => 'This table has changed. Reload the page.'], 404);
        }

        return $document->saveHtml();
    }

    private function childElements(Element $element): array
    {
        return array_values(array_filter(iterator_to_array($element->childNodes), fn ($node) => $node instanceof Element));
    }

    private function alignColumns(Element $head, array $rows): array
    {
        $headers = $this->childElements($head);
        $numbers = [];
        $text = [];
        foreach ($rows as $row) {
            $cells = $this->childElements($row);
            if (count($cells) !== count($headers)) {
                continue;
            }
            foreach ($cells as $i => $cell) {
                $value = trim($cell->textContent);
                if ($value === '' || preg_match('/^(?:—|–|Unavailable|Not available)$/iu', $value)) {
                    continue;
                }
                if (preg_match('/^[−+-]?[\d,.]+(?:\s*%)?(?:\s*[†*]+)?$/u', $value)) {
                    $numbers[$i] = true;
                } else {
                    $text[$i] = true;
                }
            }
        }
        $numericColumns = array_keys(array_diff_key($numbers, $text));
        foreach ($numericColumns as $index) {
            $headers[$index]->setAttribute('data-number', '');
        }
        foreach ($rows as $row) {
            $cells = $this->childElements($row);
            if (count($cells) === count($headers)) {
                foreach ($numericColumns as $index) {
                    $cells[$index]->setAttribute('data-number', '');
                }
            }
        }

        return $numericColumns;
    }
}
