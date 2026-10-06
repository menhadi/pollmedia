<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class SirCorrectionStatus
{
    public static function filter(Builder $query): void
    {
        $query->where(function (Builder $row): void {
            $row->where('extraction_status', 'ocr_uncertain')->orWhereNotNull('field_notes')->orWhere('serial_verified', false)
                ->orWhere(function (Builder $row): void {
                    $row->where('extraction_status', '!=', 'reviewed')->where(fn (Builder $fields) => $fields->whereNull('age')->orWhere('age', '<', 18)->orWhereNull('gender'));
                });
        });
    }
}
