<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
            $t->timestamps();
        });
        Schema::create('site_changes', function (Blueprint $t) {
            $t->id();
            $t->string('target')->index();
            $t->text('before_value')->nullable();
            $t->text('after_value');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('reason');
            $t->timestamp('created_at');
        });
        Schema::create('data_feedback', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('category');
            $t->text('path');
            $t->text('details');
            $t->string('status')->default('open');
            $t->text('admin_note')->nullable();
            $t->timestamps();
        });
        Schema::create('task_health', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('status');
            $t->text('details')->nullable();
            $t->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        foreach (['task_health', 'data_feedback', 'site_changes', 'site_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
