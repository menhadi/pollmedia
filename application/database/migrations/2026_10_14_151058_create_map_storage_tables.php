<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_storage_files', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 1024);
            $table->string('path_hash', 64)->unique();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('profile_id')->nullable()->constrained('pdf_storage_profiles')->restrictOnDelete();
            $table->string('object_key', 1024)->nullable();
            $table->timestamps();
        });
        Schema::create('map_storage_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained('map_storage_files')->restrictOnDelete();
            $table->foreignId('target_profile_id')->nullable()->constrained('pdf_storage_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('source_profile_id')->nullable();
            $table->string('source_key', 1024)->nullable();
            $table->string('target_key', 1024)->nullable();
            $table->string('status')->default('queued');
            $table->string('message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_storage_transfers');
        Schema::dropIfExists('map_storage_files');
    }
};
