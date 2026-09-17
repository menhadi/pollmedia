<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('sha256', 64)->nullable();
            $table->text('content')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('checked_at');
            $table->index(['data_source_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_checks');
    }
};
