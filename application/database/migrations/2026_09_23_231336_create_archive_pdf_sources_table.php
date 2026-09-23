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
        Schema::create('archive_pdf_sources', function (Blueprint $table) {
            $table->string('path_hash', 64)->primary();
            $table->foreign('path_hash')->references('path_hash')->on('pdf_storage_files')->restrictOnDelete();
            $table->string('category', 40);
            $table->text('source_url');
            $table->json('recovery_evidence')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_pdf_sources');
    }
};
