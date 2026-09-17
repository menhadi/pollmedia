<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('before_release_id')->constrained('source_releases')->restrictOnDelete();
            $table->foreignId('after_release_id')->unique()->constrained('source_releases')->restrictOnDelete();
            $table->string('action');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_publications');
    }
};
