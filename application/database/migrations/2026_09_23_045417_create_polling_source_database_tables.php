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
        Schema::create('polling_source_states', function (Blueprint $table): void {
            $table->string('name', 120)->primary();
            $table->json('metadata');
            $table->timestamps();
        });
        Schema::create('polling_source_documents', function (Blueprint $table): void {
            $table->string('id', 24)->primary();
            $table->string('state', 120)->index();
            $table->string('sha256', 64);
            $table->json('metadata');
            $table->timestamps();
        });
        Schema::create('polling_source_pages', function (Blueprint $table): void {
            $table->string('source_id', 24);
            $table->unsignedInteger('page');
            $table->string('sha256', 64);
            $table->json('metadata');
            $table->json('payload');
            $table->string('ocr_sha256', 64)->nullable();
            $table->json('ocr_payload')->nullable();
            $table->timestamps();
            $table->primary(['source_id', 'page']);
            $table->foreign('source_id')->references('id')->on('polling_source_documents')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polling_source_pages');
        Schema::dropIfExists('polling_source_documents');
        Schema::dropIfExists('polling_source_states');
    }
};
