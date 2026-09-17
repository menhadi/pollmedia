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
        Schema::create('census_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->unique()->constrained('import_runs');
            $table->string('source_key')->index();
            $table->string('name');
            $table->unsignedSmallInteger('year')->index();
            $table->string('status')->default('draft')->index();
            $table->string('sha256', 64);
            $table->text('source_url');
            $table->text('landing_url');
            $table->text('scope');
            $table->json('fields');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('flag_count')->default(0);
            $table->timestamp('retrieved_at');
            $table->timestamps();
        });
        Schema::create('census_publications', function (Blueprint $table) {
            $table->string('source_key')->primary();
            $table->foreignId('edition_id')->nullable()->constrained('census_editions');
        });
        Schema::create('census_catalogue_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained('census_editions')->cascadeOnDelete();
            $table->string('record_key', 64);
            $table->string('state_code', 10);
            $table->string('district_code', 10);
            $table->string('level', 30);
            $table->string('residence', 10);
            $table->string('name');
            $table->json('geography');
            $table->json('values');
            $table->json('flags');
            $table->unsignedInteger('source_row');
            $table->unique(['edition_id', 'record_key']);
            $table->index(['edition_id', 'state_code', 'district_code']);
        });
        Schema::create('census_catalogue_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained('census_editions');
            $table->foreignId('user_id')->constrained('users');
            $table->string('action');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('census_catalogue_reviews');
        Schema::dropIfExists('census_catalogue_rows');
        Schema::dropIfExists('census_publications');
        Schema::dropIfExists('census_editions');
    }
};
