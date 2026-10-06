<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SirRecordCorrection
{
    public static function rules(): array
    {
        return ['verified' => 'accepted', 'name' => 'required|string|max:255', 'relative_name' => 'required|string|max:255', 'relationship' => 'required|in:Father,Mother,Husband,Wife,Other', 'house_number' => 'nullable|string|max:255', 'age' => 'nullable|integer|between:0,120', 'gender' => 'nullable|string|max:100', 'section_number' => 'nullable|string|max:50', 'section_name' => 'nullable|string|max:255', 'ward_number' => 'nullable|string|max:50', 'elector_id' => 'nullable|string|max:100'];
    }

    // Caller locks the record and creates its audit in the same transaction.
    public function apply(object $record, array $values, ?string $note = null): void
    {
        $name = SirNameSearch::latin($values['name']);
        $relative = SirNameSearch::latin($values['relative_name']);
        unset($values['verified']);
        DB::table('sir_records')->where('id', $record->id)->update($values + ['name_latin' => $name, 'relative_name_latin' => $relative, 'name_latin_key' => SirNameSearch::key($name), 'relative_name_latin_key' => SirNameSearch::key($relative), 'extraction_status' => $note ? 'ocr_uncertain' : 'reviewed', 'serial_verified' => true, 'field_notes' => $note, 'extraction_note' => 'Admin compared details against original PDF.']);
        $key = 'sir-roll-meta:'.$record->edition_key;
        $meta = DB::table('site_settings')->where('key', $key)->lockForUpdate()->first();
        if ($meta) {
            $details = json_decode($meta->value, true, 512, JSON_THROW_ON_ERROR);
            $details['uncertain_records'] = DB::table('sir_records')->where('edition_key', $record->edition_key)->where('extraction_status', 'ocr_uncertain')->count();
            DB::table('site_settings')->where('key', $key)->update(['value' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }
}
