<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sir_extraction_reviews', function (Blueprint $table) {
            $table->string('provider', 20)->default('openai');
            $table->string('image_sha256', 64)->nullable();
            $table->string('image_mime', 30)->nullable();
            $table->string('image_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sir_extraction_reviews', function (Blueprint $table) {
            $table->dropColumn(['provider', 'image_sha256', 'image_mime', 'image_source']);
        });
    }
};
