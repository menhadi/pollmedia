<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_board_listings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 20);
            $table->string('category', 30);
            $table->string('title', 140);
            $table->text('description');
            $table->string('organisation', 120);
            $table->string('pay_text', 120)->nullable();
            $table->string('primary_area_key', 100);
            $table->string('area_name');
            $table->string('area_kind', 20);
            $table->string('contact_name', 80);
            $table->string('contact_phone', 24)->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamp('contact_consent_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('expires_at');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_message')->nullable();
            $table->string('verification_method', 30)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['primary_area_key', 'status']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('local_board_scopes', function (Blueprint $table) {
            $table->foreignUlid('listing_id')->constrained('local_board_listings')->cascadeOnDelete();
            $table->string('area_key', 100);
            $table->string('name');
            $table->string('kind', 20);
            $table->primary(['listing_id', 'area_key']);
            $table->index(['area_key', 'listing_id']);
        });
        Schema::create('local_board_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('listing_id')->constrained('local_board_listings')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->string('verification_method', 30)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_board_events');
        Schema::dropIfExists('local_board_scopes');
        Schema::dropIfExists('local_board_listings');
    }
};
