<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'customer_name',
        'id',
        'customer_code',
        'supplier_code',
        'code',
        'item_code',
        'item_name',
        'weight',
        'price_per_kg',
        'total',
        'packs',
        'bill_printed',
        'Processed', 
        'bill_no',
        'updated',
        'is_printed',
        'CustomerBillEnteredOn',
        'FirstTimeBillPrintedOn',
        'BillChangedOn',
        'BillReprintAfterchanges',
        'UniqueCode',
        'PerKGPrice',
        'PerKGTotal',
        'SellingKGTotal',
        'Date',
        'ip_address',
        'given_amount',
        'commission_amount',
        'CustomerPackCost',
        'CustomerPackLabour',
        'SupplierWeight',
        'SupplierPricePerKg',
        'SupplierTotal',
        'SupplierPackCost',
        'SupplierPackLabour',
        'profit',
        'supplier_bill_printed',
        'supplier_bill_no',
        'breakdown_history',
        'bag_real_weight',
        'credit_transaction',
        'loan_amount',
        'loan_taken',
        'given_amount_applied',
        'cheq_date',
        'cheq_no',
        'bank_name',
        'payment_adjustment_type',
        'bag_count',
        'box_count',
        'bag_value',
        'box_value',
        'target_customer_code',
        'target_bill_no',
        'target_bill_value',
        'target_supplier_code',
        'target_supplier_bill_no',
        'target_supplier_bill_value',
        'bad_debt_name',
        'bad_debt_amount',
        'supplier_paid_amount',
        'supplier_paid_status',
        'adjustment_amount',
        'bank_account_id',
        'payment_history',
         'Debtor_no',
    ];

    protected $casts = [
        'breakdown_history' => 'array',
        'bag_count' => 'integer',
        'box_count' => 'integer',
        'bag_value' => 'decimal:2',
        'box_value' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'target_bill_value' => 'decimal:2',
        'target_supplier_bill_value' => 'decimal:2',
        'bad_debt_amount' => 'decimal:2',
        'supplier_paid_amount' => 'decimal:2',
        'cheq_date' => 'date',
        'payment_history' => 'array', // This automatically handles JSON conversion
    ];

    public $timestamps = true;

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_code', 'short_name');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_code', 'no');
    }

    public function itemByNo()
    {
        return $this->belongsTo(Item::class, 'item_code', 'no');
    }

    public function bankAccount()
    {
        return $this->belongsTo(Bank::class, 'bank_account_id');
    }

    // ==================== PAYMENT HISTORY METHODS ====================
    
    /**
     * Get payment history as array
     */
    public function getPaymentHistoryAttribute($value)
    {
        if (is_null($value)) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        return json_decode($value, true) ?: [];
    }
    
    /**
     * Set payment history
     */
    public function setPaymentHistoryAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['payment_history'] = json_encode($value);
        } else {
            $this->attributes['payment_history'] = $value;
        }
    }
    
    /**
     * Add a payment to history
     */
    public function addPayment($paymentData)
    {
        $history = $this->payment_history;
        $history[] = $paymentData;
        $this->payment_history = $history;
        return $this->save();
    }
    
    /**
     * Get payment summary for display
     */
    public function getPaymentSummaryAttribute()
    {
        $history = $this->payment_history;
        if (!$history || empty($history)) {
            return [];
        }
        
        return array_map(function($payment) {
            $reference = null;
            if (isset($payment['details']['cheq_no'])) {
                $reference = $payment['details']['cheq_no'];
            } elseif (isset($payment['details']['transfer_reference_no'])) {
                $reference = $payment['details']['transfer_reference_no'];
            } elseif (isset($payment['details']['bad_debt_name'])) {
                $reference = $payment['details']['bad_debt_name'];
            } elseif (isset($payment['details']['target_bill_no'])) {
                $reference = $payment['details']['target_bill_no'];
            }
            
            return [
                'date' => $payment['date'],
                'amount' => (float)$payment['amount'],
                'method' => $payment['method'],
                'reference' => $reference,
                'running_balance' => $payment['running_balance'] ?? null,
                'is_fully_paid' => $payment['is_fully_paid'] ?? false
            ];
        }, $history);
    }
    
    /**
     * Get total paid amount from payment history
     */
    public function getTotalPaidFromHistoryAttribute()
    {
        $history = $this->payment_history;
        if (!$history || empty($history)) {
            return 0;
        }
        
        return array_sum(array_column($history, 'amount'));
    }
    
    /**
     * Get last payment
     */
    public function getLastPaymentAttribute()
    {
        $history = $this->payment_history;
        if (!$history || empty($history)) {
            return null;
        }
        
        return end($history);
    }

    // ==================== EXISTING ACCESSOR METHODS ====================

    public function getTotalBagValueAttribute()
    {
        return ($this->bag_count ?? 0) * ($this->bag_value ?? 0);
    }

    public function getTotalBoxValueAttribute()
    {
        return ($this->box_count ?? 0) * ($this->box_value ?? 0);
    }

    public function getBagToBoxDifferenceAttribute()
    {
        return $this->total_bag_value - $this->total_box_value;
    }

    public function getHasPaymentAdjustmentAttribute()
    {
        return $this->payment_adjustment_type !== null && $this->payment_adjustment_type !== 'none' && $this->payment_adjustment_type !== 'Cash';
    }

    public function getAdjustmentTypeLabelAttribute()
    {
        $labels = [
            'bag_to_box' => 'Bag to Box Conversion',
            'bill_to_bill' => 'Bill to Bill Transfer',
            'bad_debt' => 'Bad Debt Write-off',
            'Cash' => 'Cash Payment',
            'Cheque' => 'Cheque Payment',
            'Bank Transfer' => 'Bank Transfer',
            'none' => 'No Adjustment'
        ];
        return $labels[$this->payment_adjustment_type] ?? 'Unknown';
    }

    public function getTotalPayableAttribute()
    {
        return ($this->total ?? 0) + (($this->packs ?? 0) * ($this->CustomerPackCost ?? 0));
    }

    public function getRemainingAmountAttribute()
    {
        return max(0, $this->total_payable - ($this->given_amount ?? 0));
    }

    public function getIsFullyPaidAttribute()
    {
        return $this->remaining_amount == 0;
    }

    public function getAdjustmentSummaryAttribute()
    {
        if (!$this->has_payment_adjustment) {
            return null;
        }
        
        $summary = [
            'type' => $this->adjustment_type_label,
            'amount' => $this->adjustment_amount
        ];
        
        if ($this->payment_adjustment_type === 'bag_to_box') {
            $summary['details'] = "{$this->bag_count} bags @ Rs.{$this->bag_value} → {$this->box_count} boxes @ Rs.{$this->box_value}";
            $summary['difference'] = $this->bag_to_box_difference;
        }
        
        if ($this->payment_adjustment_type === 'bill_to_bill') {
            $summary['details'] = "Customer Bill: {$this->target_bill_no} (Rs.{$this->target_bill_value}), Supplier Bill: {$this->target_supplier_bill_no} (Rs.{$this->target_supplier_bill_value})";
        }
        
        if ($this->payment_adjustment_type === 'bad_debt') {
            $summary['details'] = "Bad Debt: {$this->bad_debt_name}";
        }
        
        return $summary;
    }

    public function getChequeDetailsAttribute()
    {
        if (!$this->cheq_no && !$this->bank_account_id) {
            return null;
        }
        
        return [
            'cheq_date' => $this->cheq_date,
            'cheq_no' => $this->cheq_no,
            'bank_account_id' => $this->bank_account_id,
            'bank_name' => $this->bankAccount ? $this->bankAccount->bank_name : $this->bank_name,
            'branch' => $this->bankAccount ? $this->bankAccount->branch : null,
            'account_no' => $this->bankAccount ? $this->bankAccount->account_no : null,
        ];
    }

    public function getAdjustmentDetailsAttribute()
    {
        if (!$this->has_payment_adjustment) {
            return null;
        }
        
        $details = [
            'type' => $this->payment_adjustment_type,
            'type_label' => $this->adjustment_type_label,
            'amount' => $this->adjustment_amount,
            'summary' => $this->adjustment_summary,
        ];
        
        if ($this->payment_adjustment_type === 'bag_to_box') {
            $details['bag_count'] = $this->bag_count;
            $details['box_count'] = $this->box_count;
            $details['bag_value'] = $this->bag_value;
            $details['box_value'] = $this->box_value;
            $details['total_bag_value'] = $this->total_bag_value;
            $details['total_box_value'] = $this->total_box_value;
            $details['difference'] = $this->bag_to_box_difference;
        }
        
        if ($this->payment_adjustment_type === 'bill_to_bill') {
            $details['target_customer_code'] = $this->target_customer_code;
            $details['target_bill_no'] = $this->target_bill_no;
            $details['target_bill_value'] = $this->target_bill_value;
            $details['target_supplier_code'] = $this->target_supplier_code;
            $details['target_supplier_bill_no'] = $this->target_supplier_bill_no;
            $details['target_supplier_bill_value'] = $this->target_supplier_bill_value;
        }
        
        if ($this->payment_adjustment_type === 'bad_debt') {
            $details['bad_debt_name'] = $this->bad_debt_name;
            $details['bad_debt_amount'] = $this->bad_debt_amount;
        }
        
        return $details;
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('bill_printed', 'Y')
                     ->where(function($q) {
                         $q->where('given_amount_applied', 'N')
                           ->orWhereRaw('COALESCE(given_amount, 0) < (total + (packs * CustomerPackCost))');
                     });
    }

    public function scopeCompleted($query)
    {
        return $query->where('bill_printed', 'Y')
                     ->where('given_amount_applied', 'Y')
                     ->whereRaw('COALESCE(given_amount, 0) >= (total + (packs * CustomerPackCost))');
    }

    public function scopeChequePayments($query)
    {
        return $query->whereNotNull('cheq_no')
                     ->whereNotNull('bank_account_id');
    }

    public function scopeCashPayments($query)
    {
        return $query->where('payment_adjustment_type', 'Cash');
    }

    public function scopeBankTransferPayments($query)
    {
        return $query->where('payment_adjustment_type', 'Bank Transfer');
    }

    public function scopeWithAdjustments($query)
    {
        return $query->whereNotNull('payment_adjustment_type')
                     ->where('payment_adjustment_type', '!=', 'none')
                     ->where('payment_adjustment_type', '!=', 'Cash')
                     ->where('payment_adjustment_type', '!=', 'Cheque')
                     ->where('payment_adjustment_type', '!=', 'Bank Transfer');
    }

    public function scopeBagToBoxAdjustments($query)
    {
        return $query->where('payment_adjustment_type', 'bag_to_box');
    }

    public function scopeBillToBillAdjustments($query)
    {
        return $query->where('payment_adjustment_type', 'bill_to_bill');
    }

    public function scopeBadDebtAdjustments($query)
    {
        return $query->where('payment_adjustment_type', 'bad_debt');
    }

    public function scopeUnpaidSupplierBills($query)
    {
        return $query->where('supplier_bill_printed', 'Y')
                     ->where('supplier_paid_status', 'N');
    }

    // ==================== BOOT METHOD ====================

    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($model) {
            if ($model->payment_adjustment_type === 'bag_to_box') {
                $totalBagValue = ($model->bag_count ?? 0) * ($model->bag_value ?? 0);
                $totalBoxValue = ($model->box_count ?? 0) * ($model->box_value ?? 0);
                $model->adjustment_amount = $totalBagValue - $totalBoxValue;
            }
            
            if ($model->payment_adjustment_type === 'bill_to_bill') {
                $model->adjustment_amount = ($model->target_bill_value ?? 0) + ($model->target_supplier_bill_value ?? 0);
            }
            
            if ($model->payment_adjustment_type === 'bad_debt') {
                $model->adjustment_amount = $model->bad_debt_amount ?? 0;
            }
        });
        
        static::updating(function ($model) {
            if ($model->isDirty('given_amount') || $model->isDirty('adjustment_amount')) {
                $totalGiven = ($model->given_amount ?? 0) + ($model->adjustment_amount ?? 0);
                $totalPayable = $model->total_payable;
                
                if ($totalGiven >= $totalPayable) {
                    $model->given_amount_applied = 'Y';
                    $model->credit_transaction = 'N';
                } else {
                    $model->given_amount_applied = 'N';
                    $model->credit_transaction = 'Y';
                }
            }
        });
    }
}