<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomersLoan extends Model
{
    protected $fillable = [
        'customer_id', 'loan_type', 'settling_way','user_id',
        'bill_no', 'description', 'amount',
        'cheque_no', 'bank', 'cheque_date','customer_short_name','Date','ip_address'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}