<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; 

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_numbers', function (Blueprint $table) {
            // We use a simple ID, knowing we only need one row (id=1)
            $table->id(); 
            $table->string('prefix', 5)->default('F');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Initialize the counter starting at 0 (so the first generated number is F1)
        DB::table('supplier_bill_numbers')->insert([
            'prefix' => 'F',
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_numbers');
    }
};
