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
        Schema::create('dynamic_restricted_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_class')->unique();
            $table->unsignedBigInteger('restricted_by')->nullable()->comment('User ID who restricted this model');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_restricted_models');
    }
};
