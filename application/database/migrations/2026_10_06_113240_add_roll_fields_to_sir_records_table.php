<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->string('section_number', 50)->nullable();
            $table->string('section_name')->nullable();
            $table->string('ward_number', 50)->nullable();
            $table->string('house_number')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('age_text')->nullable();
            $table->string('gender', 100)->nullable();
            $table->string('elector_id', 100)->nullable();
            $table->boolean('serial_verified')->default(true);
            $table->text('field_notes')->nullable();
            $table->text('extraction_note')->nullable();
            $table->string('name_latin', 1000)->nullable();
            $table->string('relative_name_latin', 1000)->nullable();
            $table->string('name_latin_key', 1000)->nullable();
            $table->string('relative_name_latin_key', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sir_records', function (Blueprint $table) {
            $table->dropColumn(['section_number', 'section_name', 'ward_number', 'house_number', 'age', 'age_text', 'gender', 'elector_id', 'serial_verified', 'field_notes', 'extraction_note', 'name_latin', 'relative_name_latin', 'name_latin_key', 'relative_name_latin_key']);
        });
    }
};
