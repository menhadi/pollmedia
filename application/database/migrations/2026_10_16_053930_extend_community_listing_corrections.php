<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('contributor_password_set_at')->nullable();
        });
        Schema::table('community_data_proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('record_id')->nullable()->change();
            $table->string('target_table', 50)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('listing_name')->nullable();
            $table->json('approved_value')->nullable();
            $table->index(['target_table', 'target_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('community_data_proposals', function (Blueprint $table): void {
            $table->dropIndex(['target_table', 'target_id', 'status']);
            $table->dropColumn(['target_table', 'target_id', 'listing_name', 'approved_value']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('contributor_password_set_at');
        });
    }
};
