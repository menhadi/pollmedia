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
        Schema::create('election_contests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('election_type');
            $table->string('source_locator');
            $table->unsignedInteger('electors');
            $table->unsignedInteger('votes_polled');
            $table->unsignedInteger('valid_candidate_votes');
            $table->boolean('active')->default(false);
            $table->unique(['place_id', 'year', 'election_type', 'source_release_id'], 'contest_release_unique');
            $table->timestamps();
        });
        Schema::create('election_candidate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_contest_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('source_row');
            $table->string('candidate_name');
            $table->string('party_at_election');
            $table->boolean('is_nota')->default(false);
            $table->unsignedInteger('general_votes');
            $table->unsignedInteger('postal_votes');
            $table->unsignedInteger('votes');
            $table->unique(['election_contest_id', 'source_row']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_candidate_results');
        Schema::dropIfExists('election_contests');
    }
};
