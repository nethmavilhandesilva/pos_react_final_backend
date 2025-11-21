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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->string('item_code'); // Fetches 'no' from Item
            $table->string('item_name'); // Fetches 'type' from Item
            $table->decimal('starting_price', 10, 2);
            $table->decimal('end_price', 10, 2);
            // Stored as a monetary amount, e.g., 50.00
            $table->decimal('commission_amount', 10, 2); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
