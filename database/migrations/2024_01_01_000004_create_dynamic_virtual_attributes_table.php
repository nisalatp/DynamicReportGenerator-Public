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
        Schema::create('dynamic_virtual_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_model');
            $table->text('sql_fragment');
            $table->json('dependencies')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_virtual_attributes');
    }
};
