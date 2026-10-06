<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->string('document_type', 30)->default('uncollected_forms')->index();
            $table->text('source_landing_url')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('extraction_status', 30)->default('reviewed');
        });
    }

    public function down(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->dropIndex(['document_type']);
            $table->dropColumn(['document_type', 'source_landing_url', 'pdf_sha256', 'extraction_status']);
        });
    }
};
