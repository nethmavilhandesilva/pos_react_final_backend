<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
    'short_name', 'name', 'ID_NO', 'telephone_no', 
    'address', 'credit_limit', 'profile_pic', 'nic_front', 'nic_back'
    ];

    public function salesHistory()
    {
        return $this->hasMany(SalesHistory::class, 'customer_id', 'id');
    }
}
