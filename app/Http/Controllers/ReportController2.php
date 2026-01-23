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
   public function getFinancialData()
{
    try {
        // Fetch dates from Setting table
        $dates = Setting::pluck('value')->toArray();

        // Fetch Income/Expenses filtered by dates
        $records = IncomeExpenses::select('customer_short_name', 'bill_no', 'description', 'amount', 'loan_type')
            ->whereIn('Date', $dates)
            ->get();

        $reportData = [];
        $totalDr = 0;
        $totalCr = 0;

        // 🧾 Balance row
        $balanceRow = DB::table('settings')
            ->where('key', 'last_day_started_date')
            ->first();

        if ($balanceRow) {
            $balanceValue = isset($balanceRow->Balance) ? $balanceRow->Balance : 
                           (isset($balanceRow->value) ? $balanceRow->value : 0);
            
            $reportData[] = [
                'description' => 'Balance As At ' . ($balanceRow->created_at ? 
                                 Carbon::parse($balanceRow->created_at)->format('Y-m-d') : 
                                 Carbon::now()->format('Y-m-d')),
                'dr' => (float)$balanceValue,
                'cr' => null
            ];
            $totalDr += (float)$balanceValue;
        }

        // 💵 Income/Expenses entries
        foreach ($records as $record) {
            $dr = null;
            $cr = null;

            $desc = $record->customer_short_name ?: '';
            if (!empty($record->bill_no)) {
                $desc .= " ({$record->bill_no})";
            }
            $desc .= " - {$record->description}";

            if (in_array($record->loan_type, ['old', 'ingoing'])) {
                $dr = (float)$record->amount;
                $totalDr += (float)$record->amount;
            } elseif (in_array($record->loan_type, ['today', 'outgoing'])) {
                $cr = (float)$record->amount;
                $totalCr += (float)$record->amount;
            }

            $reportData[] = [
                'description' => $desc,
                'dr' => $dr,
                'cr' => $cr
            ];
        }

        // 🧮 Sales Total
        $salesTotal = Sale::sum('total');
        $totalDr += (float)$salesTotal;
        $reportData[] = [
            'description' => 'Sales Total',
            'dr' => (float)$salesTotal,
            'cr' => null
        ];

        // 💰 Supplier Cost (NEW: Add SupplierTotal as an expense)
        $supplierTotalCost = Sale::sum('SupplierTotal');
        $totalCr += (float)$supplierTotalCost; // This is an expense, so it goes to Cr (payments) column
        $reportData[] = [
            'description' => 'සැපයුම්කරු පිරිවැය:',
            'dr' => null,
            'cr' => (float)$supplierTotalCost
        ];

        // 💰 Profit Calculation
        $sales = Sale::all();
        $saleCodes = $sales->pluck('code')->unique()->filter();

        $grnEntriesMap = GrnEntry::whereIn('code', $saleCodes)
            ->get()
            ->keyBy('code');

        $totalProfit = 0;
        $profitDetails = [];

        foreach ($sales as $sale) {
            $grnEntry = $grnEntriesMap->get($sale->code);

            $costPrice = $grnEntry ? $grnEntry->BP : null;

            if (
                !is_null($sale->price_per_kg) &&
                $sale->price_per_kg > 0 &&
                !is_null($costPrice) &&
                $costPrice > 0 &&
                !is_null($sale->weight) &&
                $sale->weight > 0
            ) {
                $profitPerRecord = ($sale->price_per_kg - $costPrice) * $sale->weight;
                $totalProfit += $profitPerRecord;

                $profitDetails[] = [
                    'bill_no' => $sale->bill_no,
                    'item_name' => $sale->item_name,
                    'weight' => $sale->weight,
                    'selling_price_per_kg' => $sale->price_per_kg,
                    'cost_price_per_kg' => $costPrice,
                    'profit' => $profitPerRecord
                ];
            }
        }

        // 💥 Total Damages
        $totalDamages = GrnEntry::select(DB::raw('SUM(wasted_weight * PerKGPrice) as total'))
            ->first()->total ?? 0;

        // 🏦 Loans
        $totalOldLoans = IncomeExpenses::whereIn('Date', $dates)
            ->where('loan_type', 'old')
            ->sum('amount');

        $totaltodayLoans = IncomeExpenses::whereIn('Date', $dates)
            ->where('loan_type', 'today')
            ->sum('amount');

        // 🧾 Sales Info
        $totalQtySold = Sale::sum('weight');
        $totalBillsPrinted = Sale::distinct('bill_no')->count('bill_no');

        // 🕓 First and Last Bill Printed Time
        $firstBill = Sale::where('bill_printed', 'Y')
            ->orderBy('FirstTimeBillPrintedOn', 'asc')
            ->first();

        $lastBill = Sale::where('bill_printed', 'Y')
            ->orderBy('FirstTimeBillPrintedOn', 'desc')
            ->first();

        $firstBillTime = $firstBill && $firstBill->FirstTimeBillPrintedOn ? 
            Carbon::parse($firstBill->FirstTimeBillPrintedOn)->setTimezone('Asia/Colombo')->format('h:i A') : 
            'N/A';
            
        $lastBillTime = $lastBill && $lastBill->FirstTimeBillPrintedOn ? 
            Carbon::parse($lastBill->FirstTimeBillPrintedOn)->setTimezone('Asia/Colombo')->format('h:i A') : 
            'N/A';

        $firstBillNo = $firstBill ? $firstBill->bill_no : 'N/A';
        $lastBillNo = $lastBill ? $lastBill->bill_no : 'N/A';

        // Company Name
        $companyName = Setting::where('key', 'CompanyName')->first();
        $companyName = $companyName ? $companyName->value : 'Default Company';

        // Setting Date
        $settingDate = Setting::orderBy('created_at', 'desc')->first();
        $settingDateValue = $settingDate ? $settingDate->value : Carbon::now()->format('Y-m-d H:i');

        return response()->json([
            'success' => true,
            'data' => [
                'reportData' => $reportData,
                'totalDr' => $totalDr,
                'totalCr' => $totalCr,
                'salesTotal' => (float)$salesTotal,
                'supplierTotalCost' => (float)$supplierTotalCost, // NEW: Add this to response
                'totalProfit' => $totalProfit,
                'profitDetails' => $profitDetails,
                'totalDamages' => (float)$totalDamages,
                'totalOldLoans' => (float)$totalOldLoans,
                'totaltodayLoans' => (float)$totaltodayLoans,
                'totalQtySold' => (float)$totalQtySold,
                'totalBillsPrinted' => $totalBillsPrinted,
                'firstBillTime' => $firstBillTime,
                'lastBillTime' => $lastBillTime,
                'firstBillNo' => $firstBillNo,
                'lastBillNo' => $lastBillNo,
                'companyName' => $companyName,
                'settingDate' => $settingDateValue
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error generating financial report: ' . $e->getMessage()
        ], 500);
    }
}
    
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