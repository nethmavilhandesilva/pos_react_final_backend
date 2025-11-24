<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_code',
        'item_name',
        'starting_price',
        'end_price',
        'commission_amount',
        'supplier_code',
        'supplier_name',
        'type',
         // <-- Corrected
    ];
}