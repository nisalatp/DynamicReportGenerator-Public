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
        Schema::create('dynamic_attribute_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('model_class');
            $table->string('attribute');
            $table->enum('restriction_type', ['masked', 'blocked']);
            $table->nullableMorphs('subject'); // creates subject_type and subject_id
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Prevent duplicate rules for the same attribute and subject
            $table->unique(['model_class', 'attribute', 'subject_type', 'subject_id'], 'dyn_attr_restr_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_attribute_restrictions');
    }
};
