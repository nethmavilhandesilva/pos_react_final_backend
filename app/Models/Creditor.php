<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Creditor extends Model
{
    use SoftDeletes;

    protected $table = 'creditors';

    protected $fillable = [
        'bill_no',
        'supplier_code',
        'credit_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'settled_way',
        'notes'
    ];

    protected $casts = [
        'credit_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PARTIAL = 'partial';
    const STATUS_PAID = 'paid';

    const SETTLED_WAY_CREDIT = 'credit';
    const SETTLED_WAY_CASH = 'cash';
    const SETTLED_WAY_CHEQUE = 'cheque';
    const SETTLED_WAY_BANK_TRANSFER = 'bank_transfer';
    const SETTLED_WAY_ADJUSTMENT = 'adjustment';

    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PARTIAL => 'Partial',
            self::STATUS_PAID => 'Paid'
        ];
    }

    public static function getSettledWays()
    {
        return [
            self::SETTLED_WAY_CREDIT => 'Credit',
            self::SETTLED_WAY_CASH => 'Cash',
            self::SETTLED_WAY_CHEQUE => 'Cheque',
            self::SETTLED_WAY_BANK_TRANSFER => 'Bank Transfer',
            self::SETTLED_WAY_ADJUSTMENT => 'Adjustment'
        ];
    }
}