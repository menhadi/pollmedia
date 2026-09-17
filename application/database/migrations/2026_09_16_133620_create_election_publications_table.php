<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_publications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('base_contest_id')->constrained('election_contests')->restrictOnDelete();
            $table->foreignId('published_contest_id')->nullable()->constrained('election_contests')->restrictOnDelete();
            $table->foreignId('restored_contest_id')->nullable()->constrained('election_contests')->restrictOnDelete();
            $table->string('detail_path');
            $table->string('totals_path')->nullable();
            $table->json('payload');
            $table->string('status')->default('needs_review');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_publications');
    }
};
