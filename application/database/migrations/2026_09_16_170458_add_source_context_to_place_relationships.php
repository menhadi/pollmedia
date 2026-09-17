<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_relationships', function (Blueprint $table): void {
            $table->string('source_locator')->nullable();
            $table->date('reference_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('place_relationships', function (Blueprint $table): void {
            $table->dropColumn(['source_locator', 'reference_date']);
        });
    }
};
