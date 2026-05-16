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
        'Date',
        'total_amount',
        'bill_no',
        'notes',
        'type',
        'payment_details',
        'bank_name',
        'cheque_no',
        'realized_date',
        'bank_account_id',
        'transfer_reference_no',
        'transfer_date',
        'transfer_notes',
        'bag_count',
        'box_count',
        'bag_value',
        'box_value',
        'adjustment_amount',
        'target_customer_code',
        'target_bill_no',
        'target_bill_value',
        'target_supplier_code',
        'target_supplier_bill_no',
        'target_supplier_bill_value',
        'bad_debt_name',
        'bad_debt_amount',
        'payment_type',
        'Creditor_no',
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'bag_value' => 'decimal:2',
        'box_value' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'target_bill_value' => 'decimal:2',
        'target_supplier_bill_value' => 'decimal:2',
        'bad_debt_amount' => 'decimal:2',
        'realized_date' => 'date',
        'transfer_date' => 'date',
        'Date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'payment_details' => 'array',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'code', 'code');
    }

    public function scopeCheque($query)
    {
        return $query->where('type', 'Cheque');
    }

    public function scopeBankTransfer($query)
    {
        return $query->where('type', 'Bank Transfer');
    }

    public function scopeCash($query)
    {
        return $query->where('type', 'Cash');
    }

    public function scopeBagToBox($query)
    {
        return $query->where('type', 'bag_to_box');
    }

    public function scopeBillToBill($query)
    {
        return $query->where('type', 'bill_to_bill');
    }

    public function scopeBadDebt($query)
    {
        return $query->where('type', 'bad_debt');
    }

    public function getPaymentMethodDisplayAttribute(): string
    {
        $methods = [
            'Cash' => 'මුදල් (Cash)',
            'Cheque' => 'චෙක්පත් (Cheque)',
            'Bank Transfer' => 'බැංකු හුවමාරුව (Bank Transfer)',
            'bag_to_box' => 'බෑග් සිට බොක්ස් (Bag to Box)',
            'bill_to_bill' => 'බිල්පත් හුවමාරුව (Bill to Bill)',
            'bad_debt' => 'නරක ණය (Bad Debt)',
        ];
        
        return $methods[$this->type] ?? $this->type;
    }

    public function getPaymentIconAttribute(): string
    {
        $icons = [
            'Cash' => '💰',
            'Cheque' => '💳',
            'Bank Transfer' => '🏦',
            'bag_to_box' => '📦',
            'bill_to_bill' => '📄',
            'bad_debt' => '⚠️',
        ];
        
        return $icons[$this->type] ?? '💵';
    }
    
    // Helper to check if bill is fully paid
    public function isFullyPaid(): bool
    {
        return $this->total_amount <= 0;
    }
}