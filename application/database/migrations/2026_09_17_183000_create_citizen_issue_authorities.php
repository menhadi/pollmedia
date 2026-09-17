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
        Schema::create('citizen_issue_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained('offices');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->text('reason');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['issue_id', 'office_id']);
        });
        Schema::create('citizen_issue_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authority_id')->constrained('citizen_issue_authorities');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->text('summary');
            $table->text('source_url');
            $table->date('responded_on');
            $table->boolean('visible')->default(true);
            $table->foreignId('hidden_by')->nullable()->constrained('users');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citizen_issue_responses');
        Schema::dropIfExists('citizen_issue_authorities');
    }
};
