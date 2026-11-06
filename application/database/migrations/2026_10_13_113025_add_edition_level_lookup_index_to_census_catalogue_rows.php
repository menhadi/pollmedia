<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $concurrent = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
            DB::statement('CREATE INDEX '.$concurrent.'IF NOT EXISTS census_edition_level_lookup ON census_catalogue_rows (edition_id, level, residence)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS census_edition_level_lookup');
        }
    }
};
