<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FarmerLoan extends Model
{
    use HasFactory;

    protected $table = 'farmer_loans';

    protected $fillable = [
        'Date',
        'supplier_code',
        'loan_type',
        'settling_way',
        'bill_no',
        'description',
        'amount',
        'cheque_no',
        'bank',
        'cheque_date',
        'ip_address'
    ];
}