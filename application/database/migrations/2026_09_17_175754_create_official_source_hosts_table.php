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
        Schema::create('official_source_hosts', function (Blueprint $table) {
            $table->id();
            $table->string('host')->unique();
            $table->string('country_code', 2);
            $table->string('publisher');
            $table->text('verification_note');
            $table->boolean('enabled')->default(true);
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('official_source_hosts');
    }
};
