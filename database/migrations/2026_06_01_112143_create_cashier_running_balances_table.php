<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cashier_running_balances', function (Blueprint $table) {
            $table->id();
            $table->string('cashier_name', 50)->unique(); // UniqueCode from Sales table
            $table->decimal('allocated_funds', 15, 2)->default(0); // Total allocated from button
            $table->decimal('current_balance', 15, 2)->default(0); // Running balance after deductions
            $table->decimal('total_cash_collected', 15, 2)->default(0);
            $table->decimal('total_cheque_collected', 15, 2)->default(0);
            $table->decimal('total_bank_transfer_collected', 15, 2)->default(0);
            $table->decimal('total_bag_to_box', 15, 2)->default(0);
            $table->decimal('total_bill_to_bill', 15, 2)->default(0);
            $table->decimal('total_bad_debt', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cashier_running_balances');
    }
};