<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'address', 'advance_amount', 'dob', 'telephone_no',
        'advance_created_date', 'profile_pic', 'nic_front', 'nic_back', 'Creditor', 'Creditor_no'
    ];

    // Accessor to get formatted creditor number
    public function getFormattedCreditorNoAttribute()
    {
        return $this->Creditor_no ?? 'Not Assigned';
    }
}