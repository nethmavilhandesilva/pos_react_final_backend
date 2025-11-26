<?php

// app/Models/SupplierBillNumber.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierBillNumber extends Model
{
    protected $table = 'supplier_bill_numbers';
    protected $fillable = ['prefix', 'last_number'];
}