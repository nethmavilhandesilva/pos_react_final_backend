<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSettledWayToDebtorsTable extends Migration
{
    public function up()
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->enum('settled_way', ['cash', 'cheque', 'bank_transfer', 'credit', 'adjustment'])->nullable()->after('status');
        });
    }

    public function down()
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropColumn('settled_way');
        });
    }
}