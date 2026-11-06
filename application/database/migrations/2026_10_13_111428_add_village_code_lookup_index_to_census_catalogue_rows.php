<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $expression = match (DB::getDriverName()) {
            'pgsql' => "(geography ->> 'Town/Village')",
            'sqlite' => "json_extract(geography, '$.\"Town/Village\"')",
            default => null,
        };
        if ($expression !== null) {
            $concurrent = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
            DB::statement('CREATE INDEX '.$concurrent.'IF NOT EXISTS census_village_code_lookup ON census_catalogue_rows (state_code, level, residence, '.$expression.')');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS census_village_code_lookup');
        }
    }
};
