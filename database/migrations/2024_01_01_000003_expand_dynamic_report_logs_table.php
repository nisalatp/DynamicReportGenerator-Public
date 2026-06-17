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
        Schema::table('dynamic_report_logs', function (Blueprint $table) {
            $table->string('action')->after('user_id');
            $table->json('details')->nullable()->after('action');

            // Drop existing foreign key and constraint to change to set null
            $table->dropForeign(['saved_report_id']);
        });

        Schema::table('dynamic_report_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable()->change();
            $table->foreign('saved_report_id')->references('id')->on('dynamic_saved_reports')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dynamic_report_logs', function (Blueprint $table) {
            $table->dropForeign(['saved_report_id']);
        });

        Schema::table('dynamic_report_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable(false)->change();
            $table->foreign('saved_report_id')->references('id')->on('dynamic_saved_reports')->onDelete('cascade');
            
            $table->dropColumn('action');
            $table->dropColumn('details');
        });
    }
};
