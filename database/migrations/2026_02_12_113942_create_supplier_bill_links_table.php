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
        Schema::create('supplier_bill_links', function (Blueprint $table) {
    $table->id();
    $table->string('token')->unique(); // The random link ID
    $table->string('bill_no');
    $table->json('sales_data');        // Stores the snapshot of items
    $table->decimal('advance_amount', 15, 2);
    $table->string('supplier_code');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_links');
    }
};
