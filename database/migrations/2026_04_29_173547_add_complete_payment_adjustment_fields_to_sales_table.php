<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompletePaymentAdjustmentFieldsToSalesTable extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Payment adjustment type
            if (!Schema::hasColumn('sales', 'payment_adjustment_type')) {
                $table->enum('payment_adjustment_type', ['none', 'bag_to_box', 'bill_to_bill', 'bad_debt'])
                      ->default('none')
                      ->after('credit_transaction');
            }
            
            // Bag to box fields
            if (!Schema::hasColumn('sales', 'bag_count')) {
                $table->integer('bag_count')->nullable()->after('payment_adjustment_type');
            }
            if (!Schema::hasColumn('sales', 'box_count')) {
                $table->integer('box_count')->nullable()->after('bag_count');
            }
            if (!Schema::hasColumn('sales', 'bag_value')) {
                $table->decimal('bag_value', 10, 2)->nullable()->after('box_count');
            }
            if (!Schema::hasColumn('sales', 'box_value')) {
                $table->decimal('box_value', 10, 2)->nullable()->after('bag_value');
            }
            
            // Bill to bill fields
            if (!Schema::hasColumn('sales', 'target_customer_code')) {
                $table->string('target_customer_code', 255)->nullable()->after('box_value');
            }
            if (!Schema::hasColumn('sales', 'target_bill_no')) {
                $table->string('target_bill_no', 255)->nullable()->after('target_customer_code');
            }
            if (!Schema::hasColumn('sales', 'target_bill_value')) {
                $table->decimal('target_bill_value', 10, 2)->nullable()->after('target_bill_no');
            }
            if (!Schema::hasColumn('sales', 'target_supplier_code')) {
                $table->string('target_supplier_code', 255)->nullable()->after('target_bill_value');
            }
            if (!Schema::hasColumn('sales', 'target_supplier_bill_no')) {
                $table->string('target_supplier_bill_no', 255)->nullable()->after('target_supplier_code');
            }
            if (!Schema::hasColumn('sales', 'target_supplier_bill_value')) {
                $table->decimal('target_supplier_bill_value', 10, 2)->nullable()->after('target_supplier_bill_no');
            }
            
            // Bad debt fields
            if (!Schema::hasColumn('sales', 'bad_debt_name')) {
                $table->string('bad_debt_name', 255)->nullable()->after('target_supplier_bill_value');
            }
            if (!Schema::hasColumn('sales', 'bad_debt_amount')) {
                $table->decimal('bad_debt_amount', 10, 2)->nullable()->after('bad_debt_name');
            }
            
            // Supplier payment tracking
            if (!Schema::hasColumn('sales', 'supplier_paid_amount')) {
                $table->decimal('supplier_paid_amount', 10, 2)->default(0)->after('supplier_bill_no');
            }
            if (!Schema::hasColumn('sales', 'supplier_paid_status')) {
                $table->enum('supplier_paid_status', ['N', 'Y'])->default('N')->after('supplier_paid_amount');
            }
            
            // Adjustment amount
            if (!Schema::hasColumn('sales', 'adjustment_amount')) {
                $table->decimal('adjustment_amount', 10, 2)->nullable()->after('bad_debt_amount');
            }
            
            // Bank account relationship
            if (!Schema::hasColumn('sales', 'bank_account_id')) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('adjustment_amount');
                $table->foreign('bank_account_id')
                      ->references('id')
                      ->on('banks')
                      ->onDelete('set null');
            }
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'bank_account_id')) {
                $table->dropForeign(['bank_account_id']);
            }
            
            $columns = [
                'payment_adjustment_type',
                'bag_count',
                'box_count',
                'bag_value',
                'box_value',
                'target_customer_code',
                'target_bill_no',
                'target_bill_value',
                'target_supplier_code',
                'target_supplier_bill_no',
                'target_supplier_bill_value',
                'bad_debt_name',
                'bad_debt_amount',
                'supplier_paid_amount',
                'supplier_paid_status',
                'adjustment_amount',
                'bank_account_id'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}