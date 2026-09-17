<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('publisher'); $t->text('url');
            $t->string('access_mode')->default('public'); $t->string('reuse_status')->default('review_required');
            $t->timestamp('last_checked_at')->nullable(); $t->timestamps();
        });
        Schema::create('source_releases', function (Blueprint $t) {
            $t->id(); $t->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $t->string('version_key'); $t->string('sha256',64)->nullable(); $t->text('url');
            $t->date('published_on')->nullable(); $t->timestamp('retrieved_at');
            $t->string('status')->default('accepted'); $t->json('payload')->nullable();
            $t->unique(['data_source_id','version_key']); $t->timestamps();
        });
        Schema::create('places', function (Blueprint $t) {
            $t->id(); $t->string('slug')->unique(); $t->string('name'); $t->string('type');
            $t->string('country_code',2); $t->timestamps();
        });
        Schema::create('place_identifiers', function (Blueprint $t) {
            $t->id(); $t->foreignId('place_id')->constrained()->restrictOnDelete();
            $t->string('namespace'); $t->string('code'); $t->string('version');
            $t->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $t->unique(['namespace','code','version']);
        });
        Schema::create('place_relationships', function (Blueprint $t) {
            $t->id(); $t->foreignId('from_place_id')->constrained('places')->restrictOnDelete();
            $t->foreignId('to_place_id')->constrained('places')->restrictOnDelete();
            $t->string('type'); $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
            $t->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $t->unique(['from_place_id','to_place_id','type','source_release_id'],'place_relation_evidence_unique');
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('name'); $t->text('official_url')->nullable();
        });
        Schema::create('offices', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('title'); $t->string('kind');
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
        });
        Schema::create('office_jurisdictions', function (Blueprint $t) {
            $t->id(); $t->foreignId('office_id')->constrained()->restrictOnDelete();
            $t->foreignId('place_id')->constrained()->restrictOnDelete();
            $t->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
            $t->unique(['office_id','place_id','source_release_id']);
        });
        Schema::create('people', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('display_name');
        });
        Schema::create('office_assignments', function (Blueprint $t) {
            $t->id(); $t->foreignId('office_id')->constrained()->restrictOnDelete();
            $t->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $t->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $t->date('effective_from')->nullable(); $t->date('effective_to')->nullable();
            $t->string('status')->default('last_verified'); $t->timestamp('verified_at');
            $t->timestamp('superseded_at')->nullable();
            $t->unique(['office_id','person_id','source_release_id']);
            $t->index(['office_id','superseded_at']);
        });
        Schema::create('public_profiles', function (Blueprint $t) {
            $t->id(); $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('kind'); $t->text('url'); $t->timestamp('verified_at');
            $t->text('identity_evidence'); $t->unique(['person_id','kind']);
        });
        Schema::create('indicators', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('label'); $t->string('unit');
            $t->string('evidence_class'); $t->text('definition');
        });
        Schema::create('observations', function (Blueprint $t) {
            $t->id(); $t->foreignId('place_id')->constrained()->restrictOnDelete();
            $t->foreignId('indicator_id')->constrained()->restrictOnDelete();
            $t->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $t->string('period'); $t->decimal('value',20,6)->nullable();
            $t->string('status')->default('reported'); $t->text('source_locator')->nullable();
            $t->unique(['place_id','indicator_id','source_release_id','period'],'observation_evidence_unique');
        });
    }

    public function down(): void
    {
        foreach (['observations','indicators','public_profiles','office_assignments','people','office_jurisdictions','offices','organizations','place_relationships','place_identifiers','places','source_releases','data_sources'] as $table) Schema::dropIfExists($table);
    }
};
