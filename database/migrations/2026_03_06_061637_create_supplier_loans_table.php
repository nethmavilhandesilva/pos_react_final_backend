<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('supplier_loans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->decimal('loan_amount', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->string('bill_no')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key constraint (optional but recommended)
            $table->foreign('code')->references('code')->on('suppliers')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('supplier_loans');
    }
};