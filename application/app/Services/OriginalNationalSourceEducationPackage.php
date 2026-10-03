<?php

namespace App\Services;

class OriginalNationalSourceEducationPackage extends OriginalNationalEducationExpansionPackage
{
    protected const ReviewedManifestSha256 = 'a1c7a798647d0f468156dc81d5b5b591f0f056e7f6ca050c12f99ebbca0b2fa0';

    protected function editionName(): string
    {
        return 'Original Census 1961 — INDIA* ages, source areas and study zones (C-III-A All Areas subset)';
    }

    /** Storage display types describe table scope without assigning an administrative level. */
    protected function storedLevel(array $row): string
    {
        if (isset($row['source_scope_kind'])) {
            return $row['source_scope_kind'] === 'INFORMAL_STUDY_ZONE' ? 'STUDY ZONE' : 'SOURCE AREA';
        }

        return parent::storedLevel($row);
    }

    /** Preserve the seventeen earlier geography objects exactly across either prior publication. */
    protected function storedGeography(array $row, array $manifest): array
    {
        if (! isset($row['source_scope_kind'])) {
            return parent::storedGeography($row, $manifest);
        }

        $row['definition_context'] = in_array('nefa-partial-exclusion', $row['warning_ids'], true)
            ? 'Printed covered portion only; the uncanvassed NEFA portion is excluded. No full-population total inferred.'
            : ($row['source_scope_kind'] === 'INFORMAL_STUDY_ZONE'
                ? 'Informal study-zone population as printed; this is not an administrative or politically constituted zone.'
                : 'Printed original source-area population; administrative level unassigned. INDIA* exclusion is not transferred.');

        return parent::storedGeography($row, $manifest) + [
            'source_scope' => $row['source_scope_kind'] === 'INFORMAL_STUDY_ZONE' ? 'Informal study zone' : 'Original source area',
            'administrative_classification' => 'Unassigned in source review',
            'raw_left_heading' => $row['raw_left_heading'], 'raw_right_heading' => $row['raw_right_heading'],
            'physical_page_left' => $row['physical_pages'][0], 'physical_page_right' => $row['physical_pages'][1],
            'publication_year' => $row['publication_year'],
            'enumeration_date' => $row['enumeration_date'] ?? 'Not individually specified in the reviewed source',
        ];
    }
}
