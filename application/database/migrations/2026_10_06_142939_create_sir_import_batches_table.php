<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sir_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('json_sha256', 64)->unique();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('edition_key', 64)->nullable();
            $table->string('state_name')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('ac_code', 10)->nullable();
            $table->unsignedInteger('part')->nullable();
            $table->unsignedInteger('records_count')->default(0);
            $table->string('status', 20)->default('importing');
            $table->text('message')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sir_import_batches');
    }
};
