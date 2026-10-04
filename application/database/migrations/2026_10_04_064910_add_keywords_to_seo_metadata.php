<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->text('keywords')->nullable();
        });
        Schema::table('seo_revisions', function (Blueprint $table): void {
            $table->text('keywords')->nullable();
            $table->text('before_keywords')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seo_metadata', fn (Blueprint $table) => $table->dropColumn('keywords'));
        Schema::table('seo_revisions', fn (Blueprint $table) => $table->dropColumn(['keywords', 'before_keywords']));
    }
};
