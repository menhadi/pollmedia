<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('election_import_batches', function (Blueprint $table): void {
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
        });
        Schema::table('election_import_batch_rows', function (Blueprint $table): void {
            $table->foreignId('place_id')->nullable()->constrained('places');
            $table->foreignId('published_contest_id')->nullable()->constrained('election_contests');
        });
    }

    public function down(): void
    {
        Schema::table('election_import_batch_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_contest_id');
            $table->dropConstrainedForeignId('place_id');
        });
        Schema::table('election_import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
        });
    }
};
