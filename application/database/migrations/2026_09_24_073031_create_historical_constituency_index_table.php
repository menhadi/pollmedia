<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_constituency_index', function (Blueprint $table): void {
            $table->id();
            $table->char('edition_id', 24);
            $table->unsignedInteger('record_code');
            $table->char('kind', 2);
            $table->unsignedSmallInteger('year');
            $table->string('edition_label', 160);
            $table->string('state_label', 100)->nullable();
            $table->string('constituency_name', 160);
            $table->string('status', 32);
            $table->boolean('has_warning');
            $table->unsignedSmallInteger('candidate_count');
            $table->char('extraction_sha256', 64);
            $table->timestamps();
            $table->unique(['edition_id', 'record_code']);
            $table->index(['kind', 'year']);
            $table->index(['state_label', 'constituency_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_constituency_index');
    }
};
