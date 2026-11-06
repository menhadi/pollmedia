<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS historical_seat_name_lookup_v1 ON public.historical_constituency_index (kind, (LOWER(TRIM(constituency_name))))');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS census_parent_name_lookup_v1 ON public.census_catalogue_rows (state_code,level,district_code,edition_id,id) INCLUDE (name) WHERE level IN ('STATE','DISTRICT')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.census_parent_name_lookup_v1');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.historical_seat_name_lookup_v1');
    }
};
