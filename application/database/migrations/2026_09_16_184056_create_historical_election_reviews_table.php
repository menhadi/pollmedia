<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_election_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('archive', 24);
            $table->unsignedInteger('code');
            $table->string('fingerprint', 64);
            $table->string('action');
            $table->text('reason');
            $table->text('reference_url')->nullable();
            $table->json('record');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->timestamp('created_at');
            $table->index(['archive', 'code', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_election_reviews');
    }
};
