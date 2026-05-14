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
        Schema::table('dynamic_virtual_attributes', function (Blueprint $table) {
            $table->string('return_type')->default('string')->after('base_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dynamic_virtual_attributes', function (Blueprint $table) {
            $table->dropColumn('return_type');
        });
    }
};
