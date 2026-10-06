<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class SirCorrectionStatus
{
    public static function filter(Builder $query): void
    {
        $query->where(function (Builder $row): void {
            $row->where('extraction_status', 'ocr_uncertain')->orWhere(fn (Builder $notes) => $notes->whereNotNull('field_notes')->where('field_notes', '!=', 'Age, gender, house number and voter ID are OCR text; verify the original PDF.'))->orWhere('serial_verified', false)
                ->orWhere(function (Builder $row): void {
                    $row->where('extraction_status', '!=', 'reviewed')->where(fn (Builder $fields) => $fields->whereNull('age')->orWhere('age', '<', 18)->orWhereNull('gender'));
                });
        });
    }
}
