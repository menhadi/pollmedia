<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_issue_supports', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $t->string('participant_hash', 64);
            $t->timestamps();
            $t->unique(['issue_id', 'participant_hash']);
        });
        Schema::create('citizen_issue_contributions', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $t->string('kind', 30);
            $t->text('body');
            $t->string('evidence_url', 2048)->nullable();
            $t->string('portal', 30)->nullable();
            $t->text('private_reference')->nullable();
            $t->string('official_status', 120)->nullable();
            $t->date('observed_on')->nullable();
            $t->string('status', 20)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizen_issue_contributions');
        Schema::dropIfExists('citizen_issue_supports');
    }
};
