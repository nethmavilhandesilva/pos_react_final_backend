<?php

// app/Models/Supplier.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'advance_amount','advance_created_date'];
}

