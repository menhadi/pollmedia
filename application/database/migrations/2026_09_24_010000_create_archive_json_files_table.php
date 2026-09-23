<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_json_files', function (Blueprint $table): void {
            $table->string('path_hash', 64)->primary();
            $table->string('path', 1024);
            $table->string('category', 32)->index();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->text('source_url')->nullable();
            $table->longText('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_json_files');
    }
};
