<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\IncomeExpenses;
use App\Models\Sale;
use App\Models\GrnEntry;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController2 extends Controller
{
  
public function getValue()
{
    // Assuming you want the first record's value
    $setting = Setting::first();
    
    if ($setting) {
        return response()->json(['value' => $setting->value]);
    }
    
    return response()->json(['value' => ''], 404);
}
}