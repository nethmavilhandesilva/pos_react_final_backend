<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Debtor extends Model
{
    protected $fillable = [
        'bill_no',
        'customer_code',
        'credit_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'settled_way'
    ];

    protected $casts = [
        'credit_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    // Relationship with Sales
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'bill_no', 'bill_no');
    }
}