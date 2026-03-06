<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierLoan extends Model
{
    use HasFactory;

    protected $table = 'supplier_loans';

    protected $fillable = [
        'code',
        'loan_amount',
        'total_amount',
        'bill_no',
        'notes'
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    // Relationship with Supplier
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'code', 'code');
    }
}