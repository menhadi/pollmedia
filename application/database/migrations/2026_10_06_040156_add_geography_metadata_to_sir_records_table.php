<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->string('state_name')->nullable();
            $table->string('pc_code', 10)->nullable();
            $table->string('pc_name')->nullable();
            $table->text('pc_source_url')->nullable();
            $table->index(['state_code', 'pc_code', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->dropIndex(['state_code', 'pc_code', 'year']);
            $table->dropColumn(['state_name', 'pc_code', 'pc_name', 'pc_source_url']);
        });
    }
};
