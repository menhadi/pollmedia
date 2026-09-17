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
        Schema::create('citizen_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('category', 40);
            $table->string('title', 160);
            $table->text('description');
            $table->string('evidence_url', 2048)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('citizen_issue_places', function (Blueprint $table) {
            $table->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $table->foreignId('place_id')->constrained('places');
            $table->primary(['issue_id', 'place_id']);
        });
        Schema::create('citizen_issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('note');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citizen_issue_events');
        Schema::dropIfExists('citizen_issue_places');
        Schema::dropIfExists('citizen_issues');
    }
};
