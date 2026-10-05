<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sir_records', function (Blueprint $table) {
            $table->id();
            $table->string('edition_key', 64);
            $table->string('state_code', 10);
            $table->string('ac_code', 10);
            $table->string('ac_name');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('edition');
            $table->date('document_date');
            $table->unsignedInteger('part');
            $table->string('station');
            $table->unsignedInteger('serial');
            $table->string('name');
            $table->string('relative_name');
            $table->string('relationship', 20);
            $table->unsignedInteger('pdf_page');
            $table->text('source_url');
            $table->unique(['edition_key', 'part', 'serial']);
            $table->index(['state_code', 'ac_code', 'year', 'part']);
            $table->index('name');
            $table->index('relative_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sir_records');
    }
};
