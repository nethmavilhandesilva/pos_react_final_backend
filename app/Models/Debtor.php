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
        'settled_way',
        'Debtor_no'
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
    
    // Relationship with Customer
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_code', 'short_name');
    }
    
    // Accessor to get formatted debtor number
    public function getFormattedDebtorNoAttribute()
    {
        return $this->Debtor_no ?? 'Not Assigned';
    }
}