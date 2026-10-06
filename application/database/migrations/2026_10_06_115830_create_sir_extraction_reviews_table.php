<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sir_extraction_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_id')->index();
            $table->string('record_hash', 64);
            $table->string('pdf_sha256', 64);
            $table->text('before_snapshot');
            $table->text('suggestion');
            $table->text('accepted_values')->nullable();
            $table->string('model', 120);
            $table->string('response_id', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sir_extraction_reviews');
    }
};
