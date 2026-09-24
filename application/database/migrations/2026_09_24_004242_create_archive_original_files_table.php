<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('archive_original_files', function (Blueprint $table) {
            $table->string('path_hash', 64)->primary();
            $table->string('path', 1024);
            $table->string('category', 40);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('profile_id')->constrained('pdf_storage_profiles')->restrictOnDelete();
            $table->string('object_key', 1024);
            $table->text('source_url');
            $table->string('url_scope', 20);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_original_files');
    }
};
