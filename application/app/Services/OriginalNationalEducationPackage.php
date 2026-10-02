<?php

namespace App\Services;

class OriginalNationalEducationPackage extends OriginalHistoricalEducationPackage
{
    protected const ReviewedManifestSha256 = 'b78694d307084e6e4d78076066c25ebe598baa88811e3f3126763f675808dd82';

    protected function editionName(): string
    {
        return 'Original Census 1961 — INDIA* age and education (C-III-A All Areas subset)';
    }

    protected function storedName(array $row): string
    {
        return $row['original_name'].' — '.$row['original_age_group'];
    }

    protected function storedGeography(array $row, array $manifest): array
    {
        return parent::storedGeography($row, $manifest) + [
            'original_age_group' => $row['original_age_group'],
            'original_row_position_within_geography' => $row['original_row_position_within_geography'],
            'modern_LGD_mapping' => 'Not mapped',
        ];
    }
}
