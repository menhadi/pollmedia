<?php

namespace App\Services;

class OriginalKeralaEducationPackage extends OriginalHistoricalEducationPackage
{
    protected const ReviewedManifestSha256 = '715077f0b88e9c0332991433daa70a30e744dfc91f0a4ea9dace25f1d58c9bd4';

    protected function editionName(): string
    {
        return 'Original Census 1961 — Kerala age and education (C-III A/B/C)';
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
            'original_block_ordinal' => $row['original_block_ordinal'],
            'modern_LGD_mapping' => 'Not mapped',
        ];
    }
}
