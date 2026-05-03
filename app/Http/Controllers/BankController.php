<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\SupplierLoan;
use App\Models\SupplierLoanHistory;
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
        $hasTransactions = Sale::where('bank_account_id', $id)->exists() || 
                          SalesHistory::where('bank_account_id', $id)->exists() ||
                          SupplierLoan::where('bank_name', $bank->bank_name)->exists() ||
                          SupplierLoanHistory::where('bank_name', $bank->bank_name)->exists();
        
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
     * Get combined transactions from Sales, SalesHistory, SupplierLoan, and SupplierLoanHistory tables
     */
   /**
 * Get combined transactions from Sales, SalesHistory, SupplierLoan, and SupplierLoanHistory tables
 */
private function getCombinedTransactions($bankAccountId = null, $startDate = null, $endDate = null)
{
    $transactions = [];
    
    // Query from Sales table
    $salesQuery = Sale::query();
    if ($bankAccountId && $bankAccountId !== 'all' && $bankAccountId !== 'null') {
        $salesQuery->where('bank_account_id', $bankAccountId);
    }
    if ($startDate) {
        $salesQuery->whereDate('Date', '>=', $startDate);
    }
    if ($endDate) {
        $salesQuery->whereDate('Date', '<=', $endDate);
    }
    
    $salesTransactions = $salesQuery->get();
    foreach ($salesTransactions as $sale) {
        $totalPayable = (float)($sale->total ?? 0) + ((float)($sale->packs ?? 0) * (float)($sale->CustomerPackCost ?? 0));
        
        $transactions[] = [
            'source' => 'sales',
            'type' => 'customer_sale',
            'id' => $sale->id,
            'Date' => $sale->Date,
            'bill_no' => $sale->bill_no,
            'customer_name' => $sale->customer_name,
            'customer_code' => $sale->customer_code,
            'cheq_no' => $sale->cheq_no,
            'transfer_reference_no' => $sale->transfer_reference_no,
            'transfer_date' => $sale->transfer_date,
            'transfer_notes' => $sale->transfer_notes,
            'bank_account_id' => $sale->bank_account_id,
            'bank_name' => $sale->bank_name,
            'given_amount' => (float)($sale->given_amount ?? 0),  // This becomes DEBIT
            'total' => (float)($sale->total ?? 0),
            'packs' => (int)($sale->packs ?? 0),
            'CustomerPackCost' => (float)($sale->CustomerPackCost ?? 0),
            'total_payable' => $totalPayable,  // This becomes CREDIT
            'payment_adjustment_type' => $sale->payment_adjustment_type ?? 'none',
            'adjustment_amount' => (float)($sale->adjustment_amount ?? 0),
            'given_amount_applied' => $sale->given_amount_applied,
            'created_at' => $sale->created_at,
        ];
    }
    
    // Query from SalesHistory table
    $historyQuery = SalesHistory::query();
    if ($bankAccountId && $bankAccountId !== 'all' && $bankAccountId !== 'null') {
        $historyQuery->where('bank_account_id', $bankAccountId);
    }
    if ($startDate) {
        $historyQuery->whereDate('Date', '>=', $startDate);
    }
    if ($endDate) {
        $historyQuery->whereDate('Date', '<=', $endDate);
    }
    
    $historyTransactions = $historyQuery->get();
    foreach ($historyTransactions as $history) {
        $totalPayable = (float)($history->total ?? 0) + ((float)($history->packs ?? 0) * (float)($history->CustomerPackCost ?? 0));
        
        $transactions[] = [
            'source' => 'sales_history',
            'type' => 'customer_sale',
            'id' => $history->id,
            'Date' => $history->Date,
            'bill_no' => $history->bill_no,
            'customer_name' => $history->customer_name,
            'customer_code' => $history->customer_code,
            'cheq_no' => $history->cheq_no,
            'transfer_reference_no' => $history->transfer_reference_no,
            'transfer_date' => $history->transfer_date,
            'transfer_notes' => $history->transfer_notes,
            'bank_account_id' => $history->bank_account_id,
            'bank_name' => $history->bank_name,
            'given_amount' => (float)($history->given_amount ?? 0),  // This becomes DEBIT
            'total' => (float)($history->total ?? 0),
            'packs' => (int)($history->packs ?? 0),
            'CustomerPackCost' => (float)($history->CustomerPackCost ?? 0),
            'total_payable' => $totalPayable,  // This becomes CREDIT
            'payment_adjustment_type' => $history->payment_adjustment_type ?? 'none',
            'adjustment_amount' => (float)($history->adjustment_amount ?? 0),
            'given_amount_applied' => $history->given_amount_applied,
            'created_at' => $history->created_at,
        ];
    }
    
    // Query from SupplierLoan table (current/active loans)
    // These are CREDIT transactions (money going OUT to suppliers)
    $loanQuery = SupplierLoan::query();
    if ($bankAccountId && $bankAccountId !== 'all' && $bankAccountId !== 'null') {
        $bank = Bank::find($bankAccountId);
        if ($bank) {
            $loanQuery->where('bank_name', $bank->bank_name);
        }
    }
    if ($startDate) {
        $loanQuery->whereDate('realized_date', '>=', $startDate);
    }
    if ($endDate) {
        $loanQuery->whereDate('realized_date', '<=', $endDate);
    }
    
    $loanTransactions = $loanQuery->get();
    foreach ($loanTransactions as $loan) {
        // The loan_amount is the amount paid to supplier - this should be CREDIT
        $loanAmount = (float)($loan->loan_amount ?? 0);
        $totalAmount = (float)($loan->total_amount ?? $loanAmount);
        
        $transactions[] = [
            'source' => 'supplier_loan',
            'type' => 'supplier_payment',
            'id' => $loan->id,
            'Date' => $loan->Date ?? $loan->realized_date ?? $loan->created_at,  // Use Date column first
            'bill_no' => $loan->bill_no,
            'customer_name' => $loan->code, // Supplier code
            'customer_code' => $loan->code,
            'cheq_no' => $loan->cheque_no,
            'transfer_reference_no' => null,
            'transfer_date' => null,
            'transfer_notes' => $loan->notes,
            'bank_account_id' => $bankAccountId,
            'bank_name' => $loan->bank_name,
            'given_amount' => 0,  // No DEBIT for supplier payments
            'total' => $loanAmount,
            'packs' => 0,
            'CustomerPackCost' => 0,
            'total_payable' => $loanAmount,  // This becomes CREDIT (money going OUT)
            'payment_adjustment_type' => $loan->type ?? 'supplier_loan',
            'adjustment_amount' => $loanAmount,
            'given_amount_applied' => 'Y',
            'created_at' => $loan->created_at,
        ];
    }
    
    // Query from SupplierLoanHistory table (historical/archived loans)
    // These are also CREDIT transactions
    $loanHistoryQuery = SupplierLoanHistory::query();
    if ($bankAccountId && $bankAccountId !== 'all' && $bankAccountId !== 'null') {
        $bank = Bank::find($bankAccountId);
        if ($bank) {
            $loanHistoryQuery->where('bank_name', $bank->bank_name);
        }
    }
    if ($startDate) {
        $loanHistoryQuery->whereDate('realized_date', '>=', $startDate);
    }
    if ($endDate) {
        $loanHistoryQuery->whereDate('realized_date', '<=', $endDate);
    }
    
    $loanHistoryTransactions = $loanHistoryQuery->get();
    foreach ($loanHistoryTransactions as $loanHistory) {
        // The loan_amount is the amount paid to supplier - this should be CREDIT
        $loanAmount = (float)($loanHistory->loan_amount ?? 0);
        
        $transactions[] = [
            'source' => 'supplier_loan_history',
            'type' => 'supplier_payment',
            'id' => $loanHistory->id,
            'Date' => $loanHistory->Date ?? $loanHistory->realized_date ?? $loanHistory->created_at,
            'bill_no' => $loanHistory->bill_no,
            'customer_name' => $loanHistory->code, // Supplier code
            'customer_code' => $loanHistory->code,
            'cheq_no' => $loanHistory->cheque_no,
            'transfer_reference_no' => null,
            'transfer_date' => null,
            'transfer_notes' => $loanHistory->notes,
            'bank_account_id' => $bankAccountId,
            'bank_name' => $loanHistory->bank_name,
            'given_amount' => 0,  // No DEBIT for supplier payments
            'total' => $loanAmount,
            'packs' => 0,
            'CustomerPackCost' => 0,
            'total_payable' => $loanAmount,  // This becomes CREDIT (money going OUT)
            'payment_adjustment_type' => $loanHistory->type ?? 'supplier_loan',
            'adjustment_amount' => $loanAmount,
            'given_amount_applied' => 'Y',
            'created_at' => $loanHistory->created_at,
        ];
    }
    
    // Sort by Date
    usort($transactions, function($a, $b) {
        return strtotime($a['Date']) - strtotime($b['Date']);
    });
    
    return $transactions;
}
    
    /**
     * Get bank account transactions with debit/credit entries (Paginated)
     */
    public function getTransactions(Request $request, $bankAccountId = null)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $perPage = (int)$request->get('per_page', 20);
        $search = $request->get('search');
        $filterType = $request->get('filter_type');
        
        // Get combined transactions from all tables
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        // Apply search filter
        if ($search && !empty($search)) {
            $transactions = array_filter($transactions, function($transaction) use ($search) {
                return stripos($transaction['customer_name'], $search) !== false ||
                       stripos($transaction['bill_no'], $search) !== false ||
                       ($transaction['cheq_no'] && stripos($transaction['cheq_no'], $search) !== false) ||
                       ($transaction['transfer_reference_no'] && stripos($transaction['transfer_reference_no'], $search) !== false);
            });
            $transactions = array_values($transactions);
        }
        
        // Apply debit/credit filter
        if ($filterType === 'debit') {
            $transactions = array_filter($transactions, function($transaction) {
                return $transaction['given_amount'] > 0;
            });
            $transactions = array_values($transactions);
        } elseif ($filterType === 'credit') {
            $transactions = array_filter($transactions, function($transaction) {
                return $transaction['total_payable'] > 0;
            });
            $transactions = array_values($transactions);
        }
        
        // Paginate manually
        $currentPage = (int)$request->get('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedItems = array_slice($transactions, $offset, $perPage);
        
        // Transform for response
        $formattedTransactions = [];
        foreach ($paginatedItems as $transaction) {
            $paymentMethod = 'Cash';
            if ($transaction['transfer_reference_no']) {
                $paymentMethod = 'Bank Transfer';
            } elseif ($transaction['cheq_no']) {
                $paymentMethod = 'Cheque';
            }
            
            $remainingAmount = $transaction['total_payable'] - $transaction['given_amount'];
            $isFullyPaid = $remainingAmount <= 0;
            
            $formattedTransactions[] = [
                'id' => $transaction['id'],
                'date' => $transaction['Date'],
                'bill_no' => $transaction['bill_no'],
                'customer_name' => $transaction['customer_name'],
                'customer_code' => $transaction['customer_code'],
                'cheq_no' => $transaction['cheq_no'],
                'transfer_reference_no' => $transaction['transfer_reference_no'],
                'transfer_date' => $transaction['transfer_date'],
                'transfer_notes' => $transaction['transfer_notes'],
                'bank_name' => $transaction['bank_name'],
                'bank_account_id' => $transaction['bank_account_id'],
                'payment_method' => $paymentMethod,
                'payment_adjustment_type' => $transaction['payment_adjustment_type'],
                'debit' => $transaction['given_amount'],
                'credit' => $transaction['total_payable'],
                'total_payable' => $transaction['total_payable'],
                'given_amount' => $transaction['given_amount'],
                'remaining_amount' => $remainingAmount,
                'is_fully_paid' => $isFullyPaid,
                'status' => $isFullyPaid ? 'completed' : ($transaction['given_amount'] > 0 ? 'partial' : 'pending'),
                'source_table' => $transaction['source'],
                'transaction_type' => $transaction['type'] ?? 'customer_sale'
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $formattedTransactions,
                'total' => count($transactions),
                'per_page' => $perPage,
                'last_page' => ceil(count($transactions) / $perPage)
            ]
        ]);
    }
    
    /**
     * Get bank account statement with proper debit/credit format
     */
    public function getStatement(Request $request, $bankAccountId)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        
        $bank = Bank::find($bankAccountId);
        
        if (!$bank) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found'
            ], 404);
        }
        
        // Get combined transactions from all tables
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        // Calculate opening balance
        $openingBalance = $this->getOpeningBalance($bankAccountId, $startDate);
        
        $statement = [];
        $runningBalance = $openingBalance;
        $totalDebit = 0;
        $totalCredit = 0;
        
        foreach ($transactions as $transaction) {
            $debit = $transaction['given_amount'];
            $credit = $transaction['total_payable'];
            
            $runningBalance += $debit - $credit;
            $totalDebit += $debit;
            $totalCredit += $credit;
            
            // Build description based on transaction type
            if ($transaction['type'] === 'supplier_payment') {
                $description = "Supplier Payment - {$transaction['customer_name']}";
                if ($transaction['bill_no']) {
                    $description .= " (Bill #{$transaction['bill_no']})";
                }
                $paymentMethod = $transaction['cheq_no'] ? 'Cheque' : 'Cash';
            } else {
                $description = "Bill #{$transaction['bill_no']} - {$transaction['customer_name']}";
                $paymentMethod = 'Cash';
                
                if ($transaction['transfer_reference_no']) {
                    $paymentMethod = "Bank Transfer";
                    $description .= " [Bank Transfer Ref: {$transaction['transfer_reference_no']}]";
                } elseif ($transaction['cheq_no']) {
                    $paymentMethod = "Cheque";
                    $description .= " [Cheque: {$transaction['cheq_no']}]";
                }
                
                // Add adjustment description if any
                if ($transaction['payment_adjustment_type'] && $transaction['payment_adjustment_type'] !== 'none' && $transaction['payment_adjustment_type'] !== 'Cash') {
                    $description .= " ({$this->getAdjustmentTypeLabel($transaction['payment_adjustment_type'])})";
                }
            }
            
            $statement[] = [
                'date' => $transaction['Date'],
                'description' => $description,
                'bank_name' => $bank->bank_name,
                'cheq_no' => $transaction['cheq_no'],
                'transfer_reference_no' => $transaction['transfer_reference_no'],
                'transfer_date' => $transaction['transfer_date'],
                'transfer_notes' => $transaction['transfer_notes'],
                'payment_method' => $paymentMethod,
                'adjustment_type' => $transaction['payment_adjustment_type'] ?? 'none',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
                'transaction_id' => $transaction['id'],
                'bill_no' => $transaction['bill_no'],
                'customer_name' => $transaction['customer_name'],
                'customer_code' => $transaction['customer_code'],
                'source_table' => $transaction['source'],
                'transaction_type' => $transaction['type'] ?? 'customer_sale'
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'bank' => $bank,
                'start_date' => $startDate ?? 'All time',
                'end_date' => $endDate ?? 'All time',
                'opening_balance' => $openingBalance,
                'closing_balance' => $runningBalance,
                'transactions' => $statement,
                'summary' => [
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit
                ]
            ]
        ]);
    }
    
    /**
     * Get statement for ALL bank accounts combined
     */
    public function getAllAccountsStatement(Request $request)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        
        // Get all bank accounts
        $bankAccounts = Bank::all();
        
        // Get combined transactions from all tables
        $allTransactions = $this->getCombinedTransactions('all', $startDate, $endDate);
        
        // Calculate total opening balance across all banks
        $totalOpeningBalance = 0;
        foreach ($bankAccounts as $bank) {
            $totalOpeningBalance += $this->getOpeningBalance($bank->id, $startDate);
        }
        
        // Process transactions
        $statement = [];
        $runningBalance = $totalOpeningBalance;
        $totalDebit = 0;
        $totalCredit = 0;
        
        foreach ($allTransactions as $transaction) {
            $debit = $transaction['given_amount'];
            $credit = $transaction['total_payable'];
            
            $runningBalance += $debit - $credit;
            $totalDebit += $debit;
            $totalCredit += $credit;
            
            // Build description based on transaction type
            if ($transaction['type'] === 'supplier_payment') {
                $description = "Supplier Payment - {$transaction['customer_name']}";
                if ($transaction['bill_no']) {
                    $description .= " (Bill #{$transaction['bill_no']})";
                }
                $paymentMethod = $transaction['cheq_no'] ? 'Cheque' : 'Cash';
            } else {
                $description = "Bill #{$transaction['bill_no']} - {$transaction['customer_name']}";
                $paymentMethod = 'Cash';
                
                if ($transaction['transfer_reference_no']) {
                    $paymentMethod = "Bank Transfer";
                    $description .= " [Bank Transfer Ref: {$transaction['transfer_reference_no']}]";
                } elseif ($transaction['cheq_no']) {
                    $paymentMethod = "Cheque";
                    $description .= " [Cheque: {$transaction['cheq_no']}]";
                }
            }
            
            if ($transaction['payment_adjustment_type'] && $transaction['payment_adjustment_type'] !== 'none' && $transaction['payment_adjustment_type'] !== 'Cash') {
                $description .= " ({$this->getAdjustmentTypeLabel($transaction['payment_adjustment_type'])})";
            }
            
            // Get bank name
            $bankName = $transaction['bank_name'] ?? 'Unknown Bank';
            $bank = $bankAccounts->firstWhere('id', $transaction['bank_account_id']);
            if ($bank) {
                $bankName = $bank->bank_name;
            }
            
            $statement[] = [
                'date' => $transaction['Date'],
                'description' => $description,
                'bank_name' => $bankName,
                'cheq_no' => $transaction['cheq_no'],
                'transfer_reference_no' => $transaction['transfer_reference_no'],
                'transfer_date' => $transaction['transfer_date'],
                'transfer_notes' => $transaction['transfer_notes'],
                'payment_method' => $paymentMethod,
                'adjustment_type' => $transaction['payment_adjustment_type'] ?? 'none',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
                'transaction_id' => $transaction['id'],
                'bill_no' => $transaction['bill_no'],
                'customer_name' => $transaction['customer_name'],
                'customer_code' => $transaction['customer_code'],
                'bank_account_id' => $transaction['bank_account_id'],
                'source_table' => $transaction['source'],
                'transaction_type' => $transaction['type'] ?? 'customer_sale'
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'bank' => null,
                'start_date' => $startDate ?? 'All time',
                'end_date' => $endDate ?? 'All time',
                'opening_balance' => $totalOpeningBalance,
                'closing_balance' => $runningBalance,
                'transactions' => $statement,
                'summary' => [
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit
                ]
            ]
        ]);
    }
    
    /**
     * Get adjustment type label
     */
    private function getAdjustmentTypeLabel($type)
    {
        $labels = [
            'bag_to_box' => 'Bag to Box Conversion',
            'bill_to_bill' => 'Bill to Bill Transfer',
            'bad_debt' => 'Bad Debt Write-off',
            'cash' => 'Cash Payment',
            'cheque' => 'Cheque Payment',
            'Bank Transfer' => 'Bank Transfer',
            'supplier_loan' => 'Supplier Payment',
            'none' => 'No Adjustment'
        ];
        return $labels[$type] ?? $type;
    }
    
    /**
     * Get cheque payments report
     */
    public function getChequeReport(Request $request)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $bankAccountId = $request->get('bank_account_id');
        
        // Get combined transactions
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        // Filter for cheque transactions only
        $chequeTransactions = array_filter($transactions, function($transaction) {
            return !empty($transaction['cheq_no']);
        });
        
        // Apply status filter
        $status = $request->get('status');
        if ($status === 'pending') {
            $chequeTransactions = array_filter($chequeTransactions, function($transaction) {
                return $transaction['given_amount'] < $transaction['total_payable'];
            });
        } elseif ($status === 'cleared') {
            $chequeTransactions = array_filter($chequeTransactions, function($transaction) {
                return $transaction['given_amount'] >= $transaction['total_payable'];
            });
        }
        
        $chequeTransactions = array_values($chequeTransactions);
        
        // Paginate
        $perPage = (int)$request->get('per_page', 20);
        $currentPage = (int)$request->get('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedItems = array_slice($chequeTransactions, $offset, $perPage);
        
        $formattedCheques = [];
        foreach ($paginatedItems as $cheque) {
            $isFullyPaid = $cheque['given_amount'] >= $cheque['total_payable'];
            
            $formattedCheques[] = [
                'id' => $cheque['id'],
                'cheq_no' => $cheque['cheq_no'],
                'cheq_date' => $cheque['transfer_date'] ?? $cheque['Date'],
                'bank_name' => $cheque['bank_name'],
                'customer_name' => $cheque['customer_name'],
                'bill_no' => $cheque['bill_no'],
                'amount' => $cheque['given_amount'],
                'date' => $cheque['Date'],
                'status' => $isFullyPaid ? 'cleared' : 'pending',
                'source_table' => $cheque['source']
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $formattedCheques,
                'total' => count($chequeTransactions),
                'per_page' => $perPage,
                'last_page' => ceil(count($chequeTransactions) / $perPage)
            ]
        ]);
    }
    
    /**
     * Get bank transfer report
     */
    public function getBankTransferReport(Request $request)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $bankAccountId = $request->get('bank_account_id');
        
        // Get combined transactions
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        // Filter for bank transfer transactions only
        $transferTransactions = array_filter($transactions, function($transaction) {
            return !empty($transaction['transfer_reference_no']);
        });
        
        // Apply status filter
        $status = $request->get('status');
        if ($status === 'pending') {
            $transferTransactions = array_filter($transferTransactions, function($transaction) {
                return $transaction['given_amount'] < $transaction['total_payable'];
            });
        } elseif ($status === 'cleared') {
            $transferTransactions = array_filter($transferTransactions, function($transaction) {
                return $transaction['given_amount'] >= $transaction['total_payable'];
            });
        }
        
        $transferTransactions = array_values($transferTransactions);
        
        // Paginate
        $perPage = (int)$request->get('per_page', 20);
        $currentPage = (int)$request->get('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedItems = array_slice($transferTransactions, $offset, $perPage);
        
        $formattedTransfers = [];
        foreach ($paginatedItems as $transfer) {
            $isFullyPaid = $transfer['given_amount'] >= $transfer['total_payable'];
            
            $formattedTransfers[] = [
                'id' => $transfer['id'],
                'reference_no' => $transfer['transfer_reference_no'],
                'transfer_date' => $transfer['transfer_date'] ?? $transfer['Date'],
                'bank_name' => $transfer['bank_name'],
                'customer_name' => $transfer['customer_name'],
                'bill_no' => $transfer['bill_no'],
                'amount' => $transfer['given_amount'],
                'date' => $transfer['Date'],
                'notes' => $transfer['transfer_notes'],
                'status' => $isFullyPaid ? 'cleared' : 'pending',
                'source_table' => $transfer['source']
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $formattedTransfers,
                'total' => count($transferTransactions),
                'per_page' => $perPage,
                'last_page' => ceil(count($transferTransactions) / $perPage)
            ]
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
            $startDate = Carbon::create($year, $month, 1)->startOfMonth()->format('Y-m-d');
            $endDate = Carbon::create($year, $month, 1)->endOfMonth()->format('Y-m-d');
            
            // Get transactions from all tables for this month
            $transactions = $this->getCombinedTransactions('all', $startDate, $endDate);
            
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($transactions as $transaction) {
                $totalDebit += $transaction['given_amount'];
                $totalCredit += $transaction['total_payable'];
            }
            
            $monthlyData[] = [
                'month' => Carbon::create($year, $month, 1)->format('F'),
                'month_number' => $month,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net' => $totalDebit - $totalCredit,
                'transaction_count' => count($transactions)
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
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $bankAccountId = $request->get('bank_account_id');
        
        // Get combined transactions
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        $csvData = [];
        $csvData[] = [
            'Date', 'Bill No', 'Customer/Supplier Name', 'Bank Account', 'Payment Method', 'Adjustment Type',
            'Cheque No', 'Transfer Reference', 'Transfer Date', 'Debit (Dr)', 'Credit (Cr)', 'Balance Status', 'Source', 'Transaction Type'
        ];
        
        foreach ($transactions as $transaction) {
            $paymentMethod = 'Cash';
            if ($transaction['transfer_reference_no']) {
                $paymentMethod = 'Bank Transfer';
            } elseif ($transaction['cheq_no']) {
                $paymentMethod = 'Cheque';
            }
            
            $bankName = $transaction['bank_name'] ?? 'N/A';
            $adjustmentType = $transaction['payment_adjustment_type'] ?? 'none';
            
            $isFullyPaid = $transaction['given_amount'] >= $transaction['total_payable'];
            
            $csvData[] = [
                $transaction['Date'],
                $transaction['bill_no'] ?? '-',
                $transaction['customer_name'],
                $bankName,
                $paymentMethod,
                $adjustmentType,
                $transaction['cheq_no'] ?? '-',
                $transaction['transfer_reference_no'] ?? '-',
                $transaction['transfer_date'] ?? '-',
                $transaction['given_amount'] > 0 ? $transaction['given_amount'] : 0,
                $transaction['total_payable'] > 0 ? $transaction['total_payable'] : 0,
                $isFullyPaid ? 'Completed' : 'Pending',
                $transaction['source'],
                $transaction['type'] ?? 'customer_sale'
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
        // Try to find in Sales first, then in SalesHistory, then in SupplierLoan, then in SupplierLoanHistory
        $sale = Sale::with(['bankAccount', 'customer'])->find($id);
        $sourceTable = 'sales';
        
        if (!$sale) {
            $sale = SalesHistory::with(['bankAccount', 'customer'])->find($id);
            $sourceTable = 'sales_history';
        }
        
        if (!$sale) {
            $loan = SupplierLoan::find($id);
            if ($loan) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $loan->id,
                        'date' => $loan->realized_date ?? $loan->created_at,
                        'bill_no' => $loan->bill_no,
                        'customer_name' => $loan->code,
                        'customer_code' => $loan->code,
                        'total_payable' => (float)($loan->total_amount ?? $loan->loan_amount ?? 0),
                        'given_amount' => 0,
                        'remaining_amount' => (float)($loan->total_amount ?? $loan->loan_amount ?? 0),
                        'cheq_no' => $loan->cheque_no,
                        'payment_method' => $loan->cheque_no ? 'Cheque' : 'Cash',
                        'payment_adjustment_type' => $loan->type ?? 'supplier_loan',
                        'adjustment_amount' => (float)($loan->loan_amount ?? 0),
                        'transfer_notes' => $loan->notes,
                        'status' => 'completed',
                        'source_table' => 'supplier_loan',
                        'transaction_type' => 'supplier_payment'
                    ]
                ]);
            }
        }
        
        if (!$sale) {
            $loanHistory = SupplierLoanHistory::find($id);
            if ($loanHistory) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $loanHistory->id,
                        'date' => $loanHistory->realized_date ?? $loanHistory->created_at,
                        'bill_no' => $loanHistory->bill_no,
                        'customer_name' => $loanHistory->code,
                        'customer_code' => $loanHistory->code,
                        'total_payable' => (float)($loanHistory->total_amount ?? $loanHistory->loan_amount ?? 0),
                        'given_amount' => 0,
                        'remaining_amount' => (float)($loanHistory->total_amount ?? $loanHistory->loan_amount ?? 0),
                        'cheq_no' => $loanHistory->cheque_no,
                        'payment_method' => $loanHistory->cheque_no ? 'Cheque' : 'Cash',
                        'payment_adjustment_type' => $loanHistory->type ?? 'supplier_loan',
                        'adjustment_amount' => (float)($loanHistory->loan_amount ?? 0),
                        'transfer_notes' => $loanHistory->notes,
                        'status' => 'completed',
                        'source_table' => 'supplier_loan_history',
                        'transaction_type' => 'supplier_payment'
                    ]
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
        
        $paymentMethod = 'Cash';
        if ($sale->transfer_reference_no) {
            $paymentMethod = 'Bank Transfer';
        } elseif ($sale->cheq_no) {
            $paymentMethod = 'Cheque';
        }
        
        $totalPayable = (float)($sale->total ?? 0) + ((float)($sale->packs ?? 0) * (float)($sale->CustomerPackCost ?? 0));
        $givenAmount = (float)($sale->given_amount ?? 0);
        $remainingAmount = $totalPayable - $givenAmount;
        $isFullyPaid = $remainingAmount <= 0;
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sale->id,
                'date' => $sale->Date,
                'bill_no' => $sale->bill_no,
                'customer_name' => $sale->customer_name,
                'customer_code' => $sale->customer_code,
                'total_payable' => $totalPayable,
                'given_amount' => $givenAmount,
                'remaining_amount' => $remainingAmount,
                'cheq_no' => $sale->cheq_no,
                'cheq_date' => $sale->cheq_date,
                'transfer_reference_no' => $sale->transfer_reference_no,
                'transfer_date' => $sale->transfer_date,
                'transfer_notes' => $sale->transfer_notes,
                'bank_account_id' => $sale->bank_account_id,
                'bank_name' => $sale->bankAccount ? $sale->bankAccount->bank_name : $sale->bank_name,
                'payment_method' => $paymentMethod,
                'payment_adjustment_type' => $sale->payment_adjustment_type ?? 'none',
                'adjustment_amount' => (float)($sale->adjustment_amount ?? 0),
                'items' => [
                    'item_name' => $sale->item_name,
                    'weight' => (float)$sale->weight,
                    'price_per_kg' => (float)$sale->price_per_kg,
                    'total' => (float)$sale->total,
                    'packs' => (int)$sale->packs
                ],
                'status' => $isFullyPaid ? 'completed' : ($givenAmount > 0 ? 'partial' : 'pending'),
                'source_table' => $sourceTable,
                'transaction_type' => 'customer_sale'
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
        
        // Get all transactions for this bank
        $transactions = $this->getCombinedTransactions($bankAccountId, null, null);
        
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($transactions as $transaction) {
            $totalDebit += $transaction['given_amount'];
            $totalCredit += $transaction['total_payable'];
        }
        
        $currentBalance = ($bank->opening_balance ?? 0) + ($totalDebit - $totalCredit);
        
        return response()->json([
            'success' => true,
            'data' => [
                'bank' => $bank,
                'opening_balance' => (float)($bank->opening_balance ?? 0),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'current_balance' => $currentBalance
            ]
        ]);
    }
    
    /**
     * Get bank transactions summary
     */
    private function getBankTransactions($bankAccountId, $startDate, $endDate)
    {
        $transactions = $this->getCombinedTransactions($bankAccountId, $startDate, $endDate);
        
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($transactions as $transaction) {
            $totalDebit += $transaction['given_amount'];
            $totalCredit += $transaction['total_payable'];
        }
        
        $balance = $totalDebit - $totalCredit;
        
        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balance' => $balance,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Get opening balance for a bank account before a specific date
     */
    private function getOpeningBalance($bankAccountId, $startDate)
    {
        // Handle NULL bank_account_id case
        if (!$bankAccountId || $bankAccountId === 'all' || $bankAccountId === 'null') {
            return 0;
        }
        
        $bank = Bank::find($bankAccountId);
        $openingBalance = $bank ? (float)($bank->opening_balance ?? 0) : 0;
        
        if (!$startDate) {
            return $openingBalance;
        }
        
        // Get transactions before the start date
        $transactions = $this->getCombinedTransactions($bankAccountId, null, $startDate);
        
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($transactions as $transaction) {
            // Only include transactions before the start date (not including the start date)
            if (strtotime($transaction['Date']) < strtotime($startDate)) {
                $totalDebit += $transaction['given_amount'];
                $totalCredit += $transaction['total_payable'];
            }
        }
        
        return $openingBalance + ($totalDebit - $totalCredit);
    }
}