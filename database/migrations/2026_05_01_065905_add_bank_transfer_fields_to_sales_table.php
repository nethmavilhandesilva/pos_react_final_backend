<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBankTransferFieldsToSalesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Check if columns don't exist before adding them
            if (!Schema::hasColumn('sales', 'transfer_reference_no')) {
                $table->string('transfer_reference_no')->nullable()->after('bank_account_id')->comment('Bank transfer reference/transaction ID');
            }
            
            if (!Schema::hasColumn('sales', 'transfer_date')) {
                $table->date('transfer_date')->nullable()->after('transfer_reference_no')->comment('Date of bank transfer');
            }
            
            if (!Schema::hasColumn('sales', 'transfer_notes')) {
                $table->text('transfer_notes')->nullable()->after('transfer_date')->comment('Additional notes about the bank transfer');
            }
            
            // Optional: Add index for better query performance
            if (!Schema::hasIndex('sales', 'sales_transfer_reference_no_index')) {
                $table->index('transfer_reference_no', 'sales_transfer_reference_no_index');
            }
            
            if (!Schema::hasIndex('sales', 'transfer_date')) {
                $table->index('transfer_date', 'sales_transfer_date_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Drop indexes first
            if (Schema::hasIndex('sales', 'sales_transfer_reference_no_index')) {
                $table->dropIndex('sales_transfer_reference_no_index');
            }
            
            if (Schema::hasIndex('sales', 'sales_transfer_date_index')) {
                $table->dropIndex('sales_transfer_date_index');
            }
            
            // Drop columns
            if (Schema::hasColumn('sales', 'transfer_reference_no')) {
                $table->dropColumn('transfer_reference_no');
            }
            
            if (Schema::hasColumn('sales', 'transfer_date')) {
                $table->dropColumn('transfer_date');
            }
            
            if (Schema::hasColumn('sales', 'transfer_notes')) {
                $table->dropColumn('transfer_notes');
            }
        });
    }
}