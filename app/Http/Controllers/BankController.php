<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class BankController extends Controller
{
    /**
     * Display a listing of all banks
     */
    public function index()
    {
        $banks = Bank::latest()->get();
        return response()->json([
            'success' => true,
            'data' => $banks
        ]);
    }

    /**
     * Get banks list for dropdown (alias of index)
     */
    public function getBanksList()
    {
        $banks = Bank::latest()->get();
        
        if ($banks->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No banks found'
            ]);
        }
        
        return response()->json([
            'success' => true,
            'data' => $banks
        ]);
    }

    /**
     * Store a newly created bank
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'branch' => 'required|string|max:255',
            'account_no' => 'required|string|unique:banks,account_no|max:50',
            'account_type' => 'nullable|string|max:50',
            'ifsc_code' => 'nullable|string|max:20',
            'opening_balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean'
        ]);

        $bank = Bank::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Bank account created successfully',
            'data' => $bank
        ], 201);
    }

    /**
     * Display specific bank
     */
    public function show($id)
    {
        $bank = Bank::find($id);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $bank
        ]);
    }

    /**
     * Update bank account
     */
    public function update(Request $request, $id)
    {
        $bank = Bank::find($id);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }

        $validated = $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'branch' => 'sometimes|string|max:255',
            'account_no' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('banks')->ignore($bank->id)
            ],
            'account_type' => 'nullable|string|max:50',
            'ifsc_code' => 'nullable|string|max:20',
            'opening_balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean'
        ]);

        $bank->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Bank account updated successfully',
            'data' => $bank
        ]);
    }

    /**
     * Delete bank account
     */
    public function destroy($id)
    {
        $bank = Bank::find($id);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }

        // Check if bank has any transactions
        $hasTransactions = Sale::where('bank_account_id', $id)->exists();
        
        if ($hasTransactions) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete bank account with existing transactions'
            ], 400);
        }

        $bank->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bank account deleted successfully'
        ]);
    }

    /**
     * Get bank dashboard with summary
     */
    public function dashboard(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth());
        
        // Get all bank accounts
        $bankAccounts = Bank::all();
        
        $summary = [];
        foreach ($bankAccounts as $bank) {
            $transactions = $this->getBankTransactions($bank->id, $startDate, $endDate);
            $summary[$bank->id] = [
                'bank' => $bank,
                'total_debit' => $transactions['total_debit'],
                'total_credit' => $transactions['total_credit'],
                'balance' => $transactions['balance'],
                'transaction_count' => $transactions['count']
            ];
        }
        
        // Overall summary
        $overall = [
            'total_debit' => collect($summary)->sum('total_debit'),
            'total_credit' => collect($summary)->sum('total_credit'),
            'total_balance' => collect($summary)->sum('balance'),
            'total_transactions' => collect($summary)->sum('transaction_count')
        ];
        
        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'overall' => $overall,
                'date_range' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d')
                ]
            ]
        ]);
    }
    
    /**
     * Get bank account transactions with debit/credit entries
     */
    public function getTransactions(Request $request, $bankAccountId = null)
    {
        $query = Sale::with(['bankAccount', 'customer'])
            ->where(function($q) {
                $q->whereNotNull('cheq_no')
                  ->orWhereNotNull('bank_account_id');
            });
        
        if ($bankAccountId && $bankAccountId !== 'all') {
            $query->where('bank_account_id', $bankAccountId);
        }
        
        // Apply filters
        if ($request->has('start_date')) {
            $query->whereDate('Date', '>=', $request->start_date);
        }
        
        if ($request->has('end_date')) {
            $query->whereDate('Date', '<=', $request->end_date);
        }
        
        if ($request->has('transaction_type')) {
            if ($request->transaction_type === 'debit') {
                $query->where('given_amount', '>', 0);
            } elseif ($request->transaction_type === 'credit') {
                $query->where('total', '>', 0);
            }
        }
        
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('cheq_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('bill_no', 'like', "%{$search}%");
            });
        }
        
        $transactions = $query->orderBy('Date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));
        
        // Transform transactions to show debit/credit properly
        $transactions->getCollection()->transform(function($sale) {
            return [
                'id' => $sale->id,
                'date' => $sale->Date,
                'bill_no' => $sale->bill_no,
                'customer_name' => $sale->customer_name,
                'customer_code' => $sale->customer_code,
                'cheq_no' => $sale->cheq_no,
                'cheq_date' => $sale->cheq_date,
                'bank_name' => $sale->bankAccount ? $sale->bankAccount->bank_name : $sale->bank_name,
                'bank_account_id' => $sale->bank_account_id,
                'debit' => $sale->given_amount > 0 ? (float)$sale->given_amount : 0,
                'credit' => $sale->total > 0 ? (float)$sale->total : 0,
                'total_payable' => (float)$sale->total_payable,
                'given_amount' => (float)$sale->given_amount,
                'remaining_amount' => (float)$sale->remaining_amount,
                'is_fully_paid' => $sale->is_fully_paid,
                'payment_adjustment_type' => $sale->payment_adjustment_type,
                'adjustment_amount' => (float)$sale->adjustment_amount,
                'cheque_details' => $sale->cheque_details,
                'status' => $sale->is_fully_paid ? 'completed' : ($sale->given_amount > 0 ? 'partial' : 'pending')
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }
    
    /**
     * Get bank account statement with proper debit/credit format
     */
    public function getStatement(Request $request, $bankAccountId)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->format('Y-m-d'));
        
        $bank = Bank::find($bankAccountId);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }
        
        $transactions = Sale::where('bank_account_id', $bankAccountId)
            ->whereBetween('Date', [$startDate, $endDate])
            ->orderBy('Date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();
        
        $statement = [];
        $runningBalance = $this->getOpeningBalance($bankAccountId, $startDate);
        
        foreach ($transactions as $transaction) {
            // Debit: Money received / deposited (Dr)
            $debit = $transaction->given_amount > 0 ? (float)$transaction->given_amount : 0;
            // Credit: Sales / payments made (Cr)
            $credit = $transaction->total > 0 ? (float)$transaction->total : 0;
            
            $runningBalance += $debit - $credit;
            
            $description = "Bill #{$transaction->bill_no} - {$transaction->customer_name}";
            
            // Add adjustment description if any
            if ($transaction->payment_adjustment_type && $transaction->payment_adjustment_type !== 'none') {
                $description .= " ({$transaction->adjustment_type_label})";
            }
            
            $statement[] = [
                'date' => $transaction->Date,
                'description' => $description,
                'cheq_no' => $transaction->cheq_no,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
                'transaction_id' => $transaction->id,
                'bill_no' => $transaction->bill_no,
                'customer_name' => $transaction->customer_name
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'bank' => $bank,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'opening_balance' => $this->getOpeningBalance($bankAccountId, $startDate),
                'closing_balance' => $runningBalance,
                'transactions' => $statement,
                'summary' => [
                    'total_debit' => collect($statement)->sum('debit'),
                    'total_credit' => collect($statement)->sum('credit')
                ]
            ]
        ]);
    }
    
    /**
     * Get cheque payments report
     */
    public function getChequeReport(Request $request)
    {
        $query = Sale::whereNotNull('cheq_no')
            ->whereNotNull('bank_account_id')
            ->with(['bankAccount', 'customer']);
        
        if ($request->has('status')) {
            if ($request->status === 'pending') {
                $query->where('is_fully_paid', false);
            } elseif ($request->status === 'cleared') {
                $query->where('is_fully_paid', true);
            }
        }
        
        if ($request->has('bank_account_id') && $request->bank_account_id !== 'all') {
            $query->where('bank_account_id', $request->bank_account_id);
        }
        
        $cheques = $query->orderBy('cheq_date', 'desc')
            ->orderBy('Date', 'desc')
            ->paginate($request->get('per_page', 20));
        
        $cheques->getCollection()->transform(function($cheque) {
            return [
                'id' => $cheque->id,
                'cheq_no' => $cheque->cheq_no,
                'cheq_date' => $cheque->cheq_date,
                'bank_name' => $cheque->bankAccount ? $cheque->bankAccount->bank_name : $cheque->bank_name,
                'customer_name' => $cheque->customer_name,
                'bill_no' => $cheque->bill_no,
                'amount' => (float)$cheque->given_amount,
                'date' => $cheque->Date,
                'status' => $cheque->is_fully_paid ? 'cleared' : 'pending'
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $cheques
        ]);
    }
    
    /**
     * Get monthly summary for charts
     */
    public function getMonthlySummary(Request $request)
    {
        $year = $request->get('year', Carbon::now()->year);
        
        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
            
            $transactions = Sale::whereNotNull('bank_account_id')
                ->whereBetween('Date', [$startDate, $endDate])
                ->get();
            
            $totalDebit = $transactions->sum('given_amount');
            $totalCredit = $transactions->sum('total');
            
            $monthlyData[] = [
                'month' => $startDate->format('F'),
                'month_number' => $month,
                'total_debit' => (float)$totalDebit,
                'total_credit' => (float)$totalCredit,
                'net' => (float)($totalDebit - $totalCredit),
                'transaction_count' => $transactions->count()
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => $monthlyData
        ]);
    }
    
    /**
     * Export transactions to CSV
     */
    public function exportTransactions(Request $request)
    {
        $bankAccountId = $request->get('bank_account_id');
        
        $query = Sale::with(['bankAccount', 'customer'])
            ->where(function($q) {
                $q->whereNotNull('cheq_no')
                  ->orWhereNotNull('bank_account_id');
            });
        
        if ($bankAccountId && $bankAccountId !== 'all') {
            $query->where('bank_account_id', $bankAccountId);
        }
        
        if ($request->has('start_date')) {
            $query->whereDate('Date', '>=', $request->start_date);
        }
        
        if ($request->has('end_date')) {
            $query->whereDate('Date', '<=', $request->end_date);
        }
        
        $transactions = $query->orderBy('Date', 'desc')->get();
        
        $csvData = [];
        $csvData[] = [
            'Date', 'Bill No', 'Customer Name', 'Cheque No', 'Cheque Date',
            'Bank Name', 'Debit (Dr)', 'Credit (Cr)', 'Balance Status'
        ];
        
        foreach ($transactions as $transaction) {
            $csvData[] = [
                $transaction->Date,
                $transaction->bill_no,
                $transaction->customer_name,
                $transaction->cheq_no ?? '-',
                $transaction->cheq_date ?? '-',
                $transaction->bankAccount ? $transaction->bankAccount->bank_name : $transaction->bank_name ?? '-',
                $transaction->given_amount > 0 ? $transaction->given_amount : 0,
                $transaction->total > 0 ? $transaction->total : 0,
                $transaction->is_fully_paid ? 'Completed' : 'Pending'
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => $csvData
        ]);
    }
    
    /**
     * Get single transaction details
     */
    public function getTransaction($id)
    {
        $sale = Sale::with(['bankAccount', 'customer'])->find($id);
        
        if (!$sale) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sale->id,
                'date' => $sale->Date,
                'bill_no' => $sale->bill_no,
                'customer_name' => $sale->customer_name,
                'customer_code' => $sale->customer_code,
                'total_payable' => (float)$sale->total_payable,
                'given_amount' => (float)$sale->given_amount,
                'remaining_amount' => (float)$sale->remaining_amount,
                'cheq_no' => $sale->cheq_no,
                'cheq_date' => $sale->cheq_date,
                'bank_account_id' => $sale->bank_account_id,
                'bank_name' => $sale->bankAccount ? $sale->bankAccount->bank_name : $sale->bank_name,
                'payment_adjustment_type' => $sale->payment_adjustment_type,
                'adjustment_amount' => (float)$sale->adjustment_amount,
                'items' => [
                    'item_name' => $sale->item_name,
                    'weight' => (float)$sale->weight,
                    'price_per_kg' => (float)$sale->price_per_kg,
                    'total' => (float)$sale->total,
                    'packs' => (int)$sale->packs
                ],
                'status' => $sale->is_fully_paid ? 'completed' : ($sale->given_amount > 0 ? 'partial' : 'pending')
            ]
        ]);
    }
    
    /**
     * Get bank account balance
     */
    public function getBalance($bankAccountId)
    {
        $bank = Bank::find($bankAccountId);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }
        
        $totalDebit = Sale::where('bank_account_id', $bankAccountId)->sum('given_amount');
        $totalCredit = Sale::where('bank_account_id', $bankAccountId)->sum('total');
        $currentBalance = ($bank->opening_balance ?? 0) + ($totalDebit - $totalCredit);
        
        return response()->json([
            'success' => true,
            'data' => [
                'bank' => $bank,
                'opening_balance' => (float)($bank->opening_balance ?? 0),
                'total_debit' => (float)$totalDebit,
                'total_credit' => (float)$totalCredit,
                'current_balance' => (float)$currentBalance
            ]
        ]);
    }
    
    /**
     * Get bank transactions summary
     */
    private function getBankTransactions($bankAccountId, $startDate, $endDate)
    {
        $transactions = Sale::where('bank_account_id', $bankAccountId)
            ->whereBetween('Date', [$startDate, $endDate])
            ->get();
        
        $totalDebit = $transactions->sum('given_amount');
        $totalCredit = $transactions->sum('total');
        $balance = $totalDebit - $totalCredit;
        
        return [
            'total_debit' => (float)$totalDebit,
            'total_credit' => (float)$totalCredit,
            'balance' => (float)$balance,
            'count' => $transactions->count()
        ];
    }
    
    /**
     * Get opening balance for a bank account before a specific date
     */
    private function getOpeningBalance($bankAccountId, $startDate)
    {
        $bank = Bank::find($bankAccountId);
        $openingBalance = $bank ? (float)($bank->opening_balance ?? 0) : 0;
        
        $previousTransactions = Sale::where('bank_account_id', $bankAccountId)
            ->whereDate('Date', '<', $startDate)
            ->get();
        
        $totalDebit = $previousTransactions->sum('given_amount');
        $totalCredit = $previousTransactions->sum('total');
        
        return $openingBalance + ($totalDebit - $totalCredit);
    }
}