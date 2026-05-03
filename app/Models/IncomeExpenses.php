<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomeExpenses extends Model
{
    protected $table = 'income_expenses';

    protected $fillable = [
        'customer_id',
        'loan_id', // Added this field to link records
        'loan_type',
        'settling_way',
        'bill_no',
        'description',
        'amount',
        'cheque_no',
        'bank',
        'cheque_date',
        'customer_short_name',
        'unique_code',
        'user_id',
        'Date',
        'GRN_Code',
        'Item_Code',
        'Bill_no',
        'weight ',
        'packs',
        'Reason',
        'ip_address',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function loan()
    {
        return $this->belongsTo(CustomersLoan::class, 'loan_id');
    }
    public function user()
{
    return $this->belongsTo(User::class, 'user_id', 'user_id');
}
}
