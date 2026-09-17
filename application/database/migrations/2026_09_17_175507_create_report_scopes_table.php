<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_scopes', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('country_code', 2);
            $table->string('timezone');
            $table->string('selection');
            $table->json('namespaces')->nullable();
            $table->string('adapter')->default('coverage');
            $table->boolean('automatic')->default(false);
            $table->timestamps();
        });
        DB::table('report_scopes')->insert([
            ['key' => 'india', 'label' => 'India', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'selection' => 'country', 'namespaces' => null, 'adapter' => 'coverage', 'automatic' => true],
            ['key' => 'uttar-pradesh', 'label' => 'Uttar Pradesh', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'selection' => 'identifiers', 'namespaces' => json_encode(['electoral:IN:UP:pc', 'electoral:IN:UP:ac', 'census:district:IN:UP']), 'adapter' => 'coverage', 'automatic' => true],
            ['key' => 'pilibhit', 'label' => 'Pilibhit district', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'selection' => 'legacy', 'namespaces' => null, 'adapter' => 'pilibhit-snapshot', 'automatic' => true],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_scopes');
    }
};
