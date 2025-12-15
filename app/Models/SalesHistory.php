<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesHistory extends Model
{
    use HasFactory;

    protected $table = 'sales_histories';

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



    ];
}
