<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_batches', function (Blueprint $table): void {
            $table->json('ai_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seo_batches', function (Blueprint $table): void {
            $table->dropColumn('ai_generation');
        });
    }
};
