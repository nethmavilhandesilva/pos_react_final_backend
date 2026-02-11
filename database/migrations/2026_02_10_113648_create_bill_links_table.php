<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('bill_links', function (Blueprint $table) {
        $table->id();
        $table->string('token')->unique();
        $table->string('bill_no');
        $table->json('sales_data');
        $table->decimal('loan_amount', 15, 2)->default(0);
        $table->string('customer_name');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_links');
    }
};
