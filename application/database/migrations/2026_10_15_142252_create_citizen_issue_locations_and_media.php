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
        Schema::create('citizen_issue_locations', function (Blueprint $table) {
            $table->foreignUlid('issue_id')->primary()->constrained('citizen_issues')->cascadeOnDelete();
            $table->text('private_coordinates');
            $table->json('snapshot');
        });
        Schema::create('citizen_issue_scopes', function (Blueprint $table) {
            $table->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $table->string('area_key', 100)->index();
            $table->string('name');
            $table->string('kind', 20);
            $table->unique(['issue_id', 'area_key']);
        });
        Schema::create('citizen_issue_media', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('issue_id')->constrained('citizen_issues')->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 100);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citizen_issue_media');
        Schema::dropIfExists('citizen_issue_scopes');
        Schema::dropIfExists('citizen_issue_locations');
    }
};
