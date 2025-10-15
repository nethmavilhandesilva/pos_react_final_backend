<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    // If your table name is not "sales", uncomment and change this
    // protected $table = 'sales';

    // Columns you want to be mass assignable
    protected $fillable = [

        'customer_name',
        'customer_code',
        'supplier_code',
        'code',
        'item_code',
        'item_name',
        'weight',
        'price_per_kg',
        'total',
        'packs',
        'bill_printed', // <-- ADD THIS
        'Processed', 
        'bill_no',
        'updated'   ,
        'is_printed', // <-- ADD THIS
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

    ];

    // If you're using timestamps (created_at and updated_at), keep this
    public $timestamps = true;

    // Relationships (Optional)
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    
   public function item()
{
    return $this->belongsTo(Item::class, 'item_code', 'no');
}
// In Sale.php model
public function itemByNo()
{
    return $this->belongsTo(Item::class, 'item_code', 'no');
}
}
