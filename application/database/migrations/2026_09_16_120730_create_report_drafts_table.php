<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('edition');
            $table->string('period');
            $table->timestamp('generated_at');
            $table->string('path')->unique();
            $table->string('sha256', 64);
            $table->json('source_release_ids');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_drafts');
    }
};
