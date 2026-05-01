<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $table = 'banks';
    
    protected $fillable = [
        'bank_name',
        'branch',
        'account_no',
        'account_type',
        'ifsc_code',
        'opening_balance',
        'status'
    ];
    
    protected $casts = [
        'opening_balance' => 'decimal:2',
        'status' => 'boolean'
    ];
    
    public function sales()
    {
        return $this->hasMany(Sale::class, 'bank_account_id');
    }
    
    public function getCurrentBalanceAttribute()
    {
        $totalReceived = $this->sales()->sum('given_amount');
        $totalSales = $this->sales()->sum('total');
        
        return $this->opening_balance + ($totalReceived - $totalSales);
    }
}