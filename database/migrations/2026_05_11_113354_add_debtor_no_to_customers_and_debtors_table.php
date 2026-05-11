<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add Debtor_no to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->string('Debtor_no')->nullable()->unique()->after('Debtor');
        });

        // Add Debtor_no to debtors table
        Schema::table('debtors', function (Blueprint $table) {
            $table->string('Debtor_no')->nullable()->after('id');
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('Debtor_no');
        });

        Schema::table('debtors', function (Blueprint $table) {
            $table->dropColumn('Debtor_no');
        });
    }
};