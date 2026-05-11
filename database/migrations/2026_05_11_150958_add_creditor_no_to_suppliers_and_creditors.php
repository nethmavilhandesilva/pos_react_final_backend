<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add Creditor_no to suppliers table
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('Creditor_no')->nullable()->unique()->after('Creditor');
        });

        // Add Creditor_no to creditors table
        Schema::table('creditors', function (Blueprint $table) {
            $table->string('Creditor_no')->nullable()->after('id');
        });
    }

    public function down()
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('Creditor_no');
        });

        Schema::table('creditors', function (Blueprint $table) {
            $table->dropColumn('Creditor_no');
        });
    }
};