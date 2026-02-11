<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillLink extends Model
{
    // Define which fields can be filled by your code
    protected $fillable = [
        'token',
        'bill_no',
        'sales_data',
        'loan_amount',
        'customer_name'
    ];

    // This converts the JSON from the DB into a PHP array automatically
    protected $casts = [
        'sales_data' => 'array',
    ];
}