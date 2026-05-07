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
        Schema::create('dynamic_report_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saved_report_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('saved_report_id')->references('id')->on('dynamic_saved_reports')->onDelete('cascade');
            
            // Assuming the client app has a 'users' table, we don't strictly define a foreign key to it 
            // to keep the package decoupled, but we ensure uniqueness per report and user.
            $table->unique(['saved_report_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_report_user');
    }
};
