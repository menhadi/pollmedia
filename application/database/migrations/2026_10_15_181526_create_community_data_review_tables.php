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
        Schema::create('community_data_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('census_catalogue_rows');
            $table->foreignId('user_id')->constrained('users');
            $table->string('field', 100);
            $table->json('before_value');
            $table->json('proposed_value');
            $table->string('expected', 64);
            $table->text('reason');
            $table->text('reference_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['record_id', 'status']);
        });
        Schema::create('community_login_codes', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('email');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
            $table->index('expires_at');
        });
        Schema::create('community_google_accounts', function (Blueprint $table) {
            $table->string('subject')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_google_accounts');
        Schema::dropIfExists('community_login_codes');
        Schema::dropIfExists('community_data_proposals');
    }
};
