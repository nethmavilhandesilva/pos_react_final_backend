<?php
// app/Models/CashierBalance.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashierBalance extends Model
{
    use HasFactory;

    protected $table = 'cashier_balances';

    protected $fillable = [
        'cashier_name',
        'cash_balance',
        'bank_balance',
        'allocated_funds',
        'remaining',
    ];

    protected $casts = [
        'cash_balance' => 'decimal:2',
        'bank_balance' => 'array',  // Stores bank balances like {"COMMERCIAL_BANK": 50000, "BOC": 30000}
        'allocated_funds' => 'array',  // Stores allocated funds like {"cash": 10000, "COMMERCIAL_BANK": 5000, "BOC": 2000}
        'remaining' => 'array',  // Stores remaining balances like {"cash": 5000, "COMMERCIAL_BANK": 2000, "BOC": 1000}
    ];
}