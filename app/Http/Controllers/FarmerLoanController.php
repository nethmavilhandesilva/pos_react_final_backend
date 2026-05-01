<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FarmerLoan;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FarmerLoanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $settingDate = Setting::where('key', 'Date')->value('value') ?? now()->toDateString();

        $validated = $request->validate([
            'loan_type' => 'required|string|in:old,today,ingoing,outgoing',
            'settling_way' => 'nullable|string|in:cash,cheque',
            'supplier_code' => 'required|string',
            'amount' => 'required|numeric',
            'description' => 'nullable|string|max:255',
            'bill_no' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string',
            'bank' => 'nullable|string',
            'cheque_date' => 'nullable|date',
        ]);

        $loan = new FarmerLoan();
        $loan->Date = $settingDate;
        $loan->supplier_code = $validated['supplier_code'];
        $loan->loan_type = $validated['loan_type'];
        $loan->settling_way = $validated['settling_way'] ?? 'cash';
        $loan->bill_no = $validated['bill_no'];
        $loan->description = $validated['description'];
        
        // Logic: if today (taking loan), amount is negative; if old (paying back), amount is positive
        $loan->amount = ($validated['loan_type'] === 'today' || $validated['loan_type'] === 'outgoing') 
                        ? -abs($validated['amount']) 
                        : abs($validated['amount']);

        $loan->cheque_no = $validated['cheque_no'];
        $loan->bank = $validated['bank'];
        $loan->cheque_date = $validated['cheque_date'];
        $loan->ip_address = $request->ip();
        $loan->save();

        return response()->json(['message' => 'Farmer loan recorded successfully'], 201);
    }

    /**
     * Get all loans for data table (today's records or all records)
     */
    public function getLoansData(Request $request): JsonResponse
    {
        $date = Setting::where('key', 'Date')->value('value') ?? now()->toDateString();
        
        // If requested, get all loans, otherwise get today's loans
        if ($request->get('all') === 'true') {
            $loans = FarmerLoan::orderBy('created_at', 'desc')->get();
        } else {
            $loans = FarmerLoan::whereDate('Date', $date)
                ->orderBy('created_at', 'desc')
                ->get();
        }
        
        // Format the data for the frontend
        $formattedLoans = $loans->map(function ($loan) {
            return [
                'id' => $loan->id,
                'supplier_code' => $loan->supplier_code,
                'loan_type' => $loan->loan_type,
                'settling_way' => $loan->settling_way,
                'bill_no' => $loan->bill_no,
                'amount' => abs($loan->amount), // Return absolute value for display
                'description' => $loan->description,
                'cheque_no' => $loan->cheque_no,
                'bank' => $loan->bank,
                'cheque_date' => $loan->cheque_date,
                'date' => $loan->Date,
                'created_at' => $loan->created_at,
            ];
        });
        
        return response()->json($formattedLoans);
    }

    /**
     * Get a single loan record for editing
     */
    public function getLoan($id): JsonResponse
    {
        $loan = FarmerLoan::findOrFail($id);
        
        return response()->json([
            'id' => $loan->id,
            'supplier_code' => $loan->supplier_code,
            'loan_type' => $loan->loan_type,
            'settling_way' => $loan->settling_way,
            'bill_no' => $loan->bill_no,
            'amount' => abs($loan->amount), // Return absolute value for editing
            'description' => $loan->description,
            'cheque_no' => $loan->cheque_no,
            'bank' => $loan->bank,
            'cheque_date' => $loan->cheque_date,
        ]);
    }

    /**
     * Update an existing loan record
     */
    public function update(Request $request, $id): JsonResponse
    {
        $loan = FarmerLoan::findOrFail($id);
        
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string|max:255',
            'bank' => 'nullable|string|max:255',
            'cheque_date' => 'nullable|date',
        ]);
        
        // Update the amount while preserving the sign based on loan_type
        $amount = $validated['amount'];
        if ($loan->loan_type === 'today' || $loan->loan_type === 'outgoing') {
            $loan->amount = -abs($amount);
        } else {
            $loan->amount = abs($amount);
        }
        
        $loan->description = $validated['description'] ?? $loan->description;
        $loan->cheque_no = $validated['cheque_no'] ?? $loan->cheque_no;
        $loan->bank = $validated['bank'] ?? $loan->bank;
        $loan->cheque_date = $validated['cheque_date'] ?? $loan->cheque_date;
        $loan->save();
        
        return response()->json([
            'message' => 'Loan updated successfully',
            'data' => $loan
        ]);
    }

    /**
     * Delete a loan record
     */
    public function destroy($id): JsonResponse
    {
        $loan = FarmerLoan::findOrFail($id);
        $loan->delete();
        
        return response()->json([
            'message' => 'Loan deleted successfully'
        ]);
    }

    /**
     * Get today's loans (backward compatibility)
     */
    public function getTodayLoans(): JsonResponse
    {
        return $this->getLoansData(new Request());
    }

    /**
     * Get farmer balance (Old - Today)
     */
    public function getFarmerBalance($supplier_code): JsonResponse
    {
        // Calculate total for 'old' (Repayments/Additions - positive amounts)
        $totalOld = FarmerLoan::where('supplier_code', $supplier_code)
            ->where('loan_type', 'old')
            ->where('amount', '>', 0)
            ->sum('amount');

        // Calculate total for 'today' (Loans given/Payments - negative amounts)
        $totalToday = FarmerLoan::where('supplier_code', $supplier_code)
            ->where('loan_type', 'today')
            ->where('amount', '<', 0)
            ->sum('amount');
        
        // Convert to positive for calculation
        $totalToday = abs($totalToday);
        
        // Balance = Total Old - Total Today
        $balance = $totalOld - $totalToday;

        return response()->json([
            'balance' => (float)$balance
        ]);
    }

    /**
     * Get detailed balance summary for a farmer
     */
    public function getFarmerBalanceDetails($supplier_code): JsonResponse
    {
        $oldLoans = FarmerLoan::where('supplier_code', $supplier_code)
            ->where('loan_type', 'old')
            ->orderBy('created_at', 'desc')
            ->get();
            
        $todayLoans = FarmerLoan::where('supplier_code', $supplier_code)
            ->where('loan_type', 'today')
            ->orderBy('created_at', 'desc')
            ->get();
        
        $totalOld = $oldLoans->where('amount', '>', 0)->sum('amount');
        $totalToday = abs($todayLoans->where('amount', '<', 0)->sum('amount'));
        $balance = $totalOld - $totalToday;
        
        return response()->json([
            'supplier_code' => $supplier_code,
            'total_old' => (float)$totalOld,
            'total_today' => (float)$totalToday,
            'balance' => (float)$balance,
            'old_records' => $oldLoans->map(function($loan) {
                return [
                    'id' => $loan->id,
                    'amount' => abs($loan->amount),
                    'description' => $loan->description,
                    'date' => $loan->Date,
                    'created_at' => $loan->created_at
                ];
            }),
            'today_records' => $todayLoans->map(function($loan) {
                return [
                    'id' => $loan->id,
                    'amount' => abs($loan->amount),
                    'description' => $loan->description,
                    'date' => $loan->Date,
                    'created_at' => $loan->created_at
                ];
            })
        ]);
    }

    /**
     * Get all farmers with their current balances
     */
    public function getAllFarmersBalances(): JsonResponse
    {
        $farmers = Supplier::select('code', 'name')->get();
        
        $balances = [];
        foreach ($farmers as $farmer) {
            $totalOld = FarmerLoan::where('supplier_code', $farmer->code)
                ->where('loan_type', 'old')
                ->where('amount', '>', 0)
                ->sum('amount');
                
            $totalToday = FarmerLoan::where('supplier_code', $farmer->code)
                ->where('loan_type', 'today')
                ->where('amount', '<', 0)
                ->sum('amount');
                
            $totalToday = abs($totalToday);
            $balance = $totalOld - $totalToday;
            
            $balances[] = [
                'code' => $farmer->code,
                'name' => $farmer->name,
                'balance' => (float)$balance,
                'total_old' => (float)$totalOld,
                'total_today' => (float)$totalToday
            ];
        }
        
        // Filter only farmers with non-zero balance and sort by balance descending
        $balances = array_filter($balances, function($farmer) {
            return $farmer['balance'] != 0;
        });
        
        usort($balances, function($a, $b) {
            return $b['balance'] <=> $a['balance'];
        });
        
        return response()->json(array_values($balances));
    }
}