<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'short_name', 'name', 'ID_NO', 'telephone_no', 
        'address', 'credit_limit', 'profile_pic', 'nic_front', 'nic_back', 'Debtor', 'Debtor_no','credit_period','introducer'
    ];

    public function salesHistory()
    {
        return $this->hasMany(SalesHistory::class, 'customer_id', 'id');
    }
    
    // Accessor to get formatted debtor number
    public function getFormattedDebtorNoAttribute()
    {
        return $this->Debtor_no ?? 'Not Assigned';
    }
}