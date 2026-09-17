<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_connectors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('format');
            $table->string('record_key');
            $table->json('options');
            $table->boolean('automatic')->default(false);
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedBigInteger('accepted_run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_connector_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('origin');
            $table->text('source_url');
            $table->string('sha256', 64)->nullable();
            $table->string('raw_path')->nullable();
            $table->json('extracted')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('base_run_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('import_connectors');
    }
};
