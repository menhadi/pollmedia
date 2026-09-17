<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_import_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('fingerprint', 64)->unique();
            $table->string('state')->default('Uttar Pradesh');
            $table->unsignedSmallInteger('year')->default(2022);
            $table->string('adapter')->default('eci-up-2022-v1');
            $table->string('status')->default('queued');
            $table->string('detail_path');
            $table->string('summary_path');
            $table->string('detail_sha256', 64);
            $table->string('summary_sha256', 64);
            $table->text('source_url');
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('election_import_batch_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('batch_id')->constrained('election_import_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('code');
            $table->string('name');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->unique(['batch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_import_batch_rows');
        Schema::dropIfExists('election_import_batches');
    }
};
