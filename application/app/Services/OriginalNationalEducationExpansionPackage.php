<?php

namespace App\Services;

class OriginalNationalEducationExpansionPackage extends OriginalNationalEducationPackage
{
    protected const ReviewedManifestSha256 = 'bdf136df774f131d053549863adc06d94d051882687d8d2049137d89f4470179';

    protected function editionName(): string
    {
        return 'Original Census 1961 — INDIA* ages and five state All ages rows (C-III-A All Areas subset)';
    }

    /** Keep the twelve published INDIA* storage identities exact across the additive revision. */
    protected function storedGeography(array $row, array $manifest): array
    {
        if ($row['original_level'] === 'NATIONAL AGGREGATE') {
            $manifest['boundary_basis'] = 'Original INDIA* source population with documented NEFA portion exclusion';

            return parent::storedGeography($row, $manifest);
        }

        $manifest['boundary_basis'] = $row['boundary_basis'];

        return parent::storedGeography($row, $manifest) + [
            'original_block_ordinal' => $row['original_block_ordinal'],
            'definition_context' => $row['definition_context'],
        ];
    }
}
