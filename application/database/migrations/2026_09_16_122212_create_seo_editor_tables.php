<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metadata', function (Blueprint $table): void {
            $table->string('path')->primary();
            $table->string('title', 180)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('revision_id');
            $table->timestamp('updated_at');
        });
        Schema::create('seo_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->index();
            $table->string('title', 180)->nullable();
            $table->text('description')->nullable();
            $table->string('before_title', 180)->nullable();
            $table->text('before_description')->nullable();
            $table->string('action');
            $table->timestamp('created_at');
        });
        Schema::create('seo_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->json('items');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at');
            $table->timestamp('applied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_batches');
        Schema::dropIfExists('seo_revisions');
        Schema::dropIfExists('seo_metadata');
    }
};
