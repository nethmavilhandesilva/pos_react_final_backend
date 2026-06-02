<?php
// app/Http/Controllers/CashierBalanceController.php

namespace App\Http\Controllers;

use App\Models\CashierBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CashierBalanceController extends Controller
{
    public function recordPayment(Request $request)
    {
        \Log::info('recordPayment START', [
            'request' => $request->all(),
            'user_id' => Auth::id(),
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $cashierName = $user->name ?? $user->username ?? 'System';
            $paymentAmount = floatval($request->payment_amount);
            $paymentMethod = $request->payment_method;
            $bankName = $request->bank_name;
            $chequeNumber = $request->cheque_number;
            $transferReference = $request->transfer_reference;

            // Get or create balance record
            $balance = CashierBalance::latest()->first();
            
            if (!$balance) {
                $balance = CashierBalance::create([
                    'cashier_name' => $cashierName,
                    'cash_balance' => 0,
                    'bank_balance' => [], // Initialize as empty array
                    'allocated_funds' => [], // Initialize as empty array
                    'remaining' => [] // Initialize as empty array
                ]);
            }

            // Get current bank balances as array
            $bankBalances = $balance->bank_balance ?? [];
            if (!is_array($bankBalances)) {
                $bankBalances = [];
            }
            
            $cashMethods = ['Cash', 'bag_to_box', 'bill_to_bill', 'bad_debt'];
            $bankMethods = ['Cheque', 'Bank Transfer'];

            if (in_array($paymentMethod, $cashMethods)) {
                // Update cash balance
                $balance->cash_balance += $paymentAmount;
                \Log::info('Updated CASH balance', ['new_cash_balance' => $balance->cash_balance]);
                
            } elseif (in_array($paymentMethod, $bankMethods) && $bankName) {
                // Update specific bank balance
                $bankKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $bankName));
                
                if (isset($bankBalances[$bankKey])) {
                    $bankBalances[$bankKey] += $paymentAmount;
                } else {
                    $bankBalances[$bankKey] = $paymentAmount;
                }
                
                $balance->bank_balance = $bankBalances;
                \Log::info('Updated BANK balance', [
                    'bank_key' => $bankKey,
                    'original_bank_name' => $bankName,
                    'amount_added' => $paymentAmount,
                    'all_bank_balances' => $bankBalances
                ]);
            } else {
                // Default to cash
                $balance->cash_balance += $paymentAmount;
                \Log::warning('Unknown payment method or missing bank name', [
                    'payment_method' => $paymentMethod,
                    'bank_name' => $bankName
                ]);
            }

            $balance->save();

            DB::commit();

            // Prepare response - FIXED: Calculate total bank balance correctly
            $totalBankBalance = is_array($balance->bank_balance) ? array_sum($balance->bank_balance) : ($balance->bank_balance ?? 0);
            
            $responseData = [
                'cash_balance' => $balance->cash_balance,
                'bank_balance' => $totalBankBalance,  // Return as number, not array
                'bank_breakdown' => $balance->bank_balance ?? [], // Add breakdown separately
                'total_balance' => $balance->cash_balance + $totalBankBalance
            ];

            if (!empty($balance->bank_balance) && is_array($balance->bank_balance)) {
                $bankStrings = [];
                foreach ($balance->bank_balance as $bank => $amount) {
                    $bankStrings[] = "{$bank}=Rs. " . number_format($amount, 2);
                }
                $responseData['bank_balance_formatted'] = implode(', ', $bankStrings);
            }

            return response()->json([
                'success' => true,
                'data' => $responseData,
                'message' => 'Payment recorded successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('recordPayment FAILED', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getBalance(Request $request)
    {
        $balance = CashierBalance::latest()->first();
        
        if (!$balance) {
            return response()->json([
                'success' => true,
                'data' => [
                    'cash_balance' => 0,
                    'bank_balance' => 0,
                    'bank_breakdown' => [],
                    'total_balance' => 0,
                    'allocated_funds' => [],
                    'remaining' => []
                ]
            ]);
        }
        
        $bankBalances = $balance->bank_balance ?? [];
        if (!is_array($bankBalances)) {
            $bankBalances = [];
        }
        
        $totalBankBalance = array_sum($bankBalances);
        
        $allocatedFunds = $balance->allocated_funds ?? [];
        if (!is_array($allocatedFunds)) {
            $allocatedFunds = [];
        }
        
        $remaining = $balance->remaining ?? [];
        if (!is_array($remaining)) {
            $remaining = [];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'cash_balance' => $balance->cash_balance,
                'bank_balance' => $totalBankBalance,  // Return as number
                'bank_breakdown' => $bankBalances,    // Add breakdown separately
                'total_balance' => $balance->cash_balance + $totalBankBalance,
                'allocated_funds' => $allocatedFunds,
                'remaining' => $remaining,
                'cashier_name' => $balance->cashier_name
            ]
        ]);
    }

    public function getDetailedBalance(Request $request)
    {
        try {
            $query = CashierBalance::query();
            
            // Filter by cashier name if provided
            if ($request->cashier_name && $request->cashier_name !== 'all') {
                $query->where('cashier_name', $request->cashier_name);
            }
            
            // Filter by date range if provided
            if ($request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }
            
            $balances = $query->orderBy('created_at', 'desc')->get();
            
            // Calculate totals
            $totalCashBalance = $balances->sum('cash_balance');
            
            // For bank balance - handle both numeric and JSON formats
            $bankBreakdown = [];
            $totalBankBalance = 0;
            
            foreach ($balances as $balance) {
                $bankBalance = $balance->bank_balance;
                
                // Check if bank_balance is JSON (array) or numeric
                if (is_array($bankBalance) || (is_string($bankBalance) && $this->isJson($bankBalance))) {
                    $bankData = is_array($bankBalance) ? $bankBalance : json_decode($bankBalance, true);
                    if (is_array($bankData)) {
                        foreach ($bankData as $bankName => $amount) {
                            if (!isset($bankBreakdown[$bankName])) {
                                $bankBreakdown[$bankName] = 0;
                            }
                            $bankBreakdown[$bankName] += floatval($amount);
                            $totalBankBalance += floatval($amount);
                        }
                    }
                } else {
                    // Legacy numeric format
                    $totalBankBalance += floatval($bankBalance);
                }
            }
            
            // Get unique cashier names for filter
            $cashierNames = CashierBalance::distinct()->pluck('cashier_name')->toArray();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'cash_balance' => $totalCashBalance,
                    'bank_balance' => $totalBankBalance,
                    'bank_breakdown' => $bankBreakdown,
                    'total_balance' => $totalCashBalance + $totalBankBalance,
                    'cashier_names' => $cashierNames,
                    'last_updated' => $balances->first()?->updated_at,
                    'session_count' => $balances->count()
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error getting detailed balance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get balance details: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper function to check if string is JSON
     */
    private function isJson($string) {
        if (!is_string($string)) return false;
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * SIMPLIFIED: Allocate funds (Cash or Bank)
     * Stores only: {"cash": 50000} or {"COMMERCIAL_BANK": 30000}
     */
    public function allocateFunds(Request $request)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $cashierName = $user->name ?? $user->username ?? 'System';
            $allocationType = $request->allocation_type; // 'cash' or 'bank'
            $allocationAmount = floatval($request->amount);
            
            if ($allocationAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount must be greater than 0'
                ], 400);
            }

            // Get or create balance record
            $balance = CashierBalance::latest()->first();
            
            if (!$balance) {
                $balance = CashierBalance::create([
                    'cashier_name' => $cashierName,
                    'cash_balance' => 0,
                    'bank_balance' => [],
                    'allocated_funds' => [],
                    'remaining' => []
                ]);
            }

            // Get current allocated funds
            $allocatedFunds = $balance->allocated_funds ?? [];
            if (!is_array($allocatedFunds)) {
                $allocatedFunds = [];
            }
            
            // Get current bank balances
            $bankBalances = $balance->bank_balance ?? [];
            if (!is_array($bankBalances)) {
                $bankBalances = [];
            }

            if ($allocationType === 'cash') {
                // Add to cash allocation - simple key-value pair
                if (!isset($allocatedFunds['cash'])) {
                    $allocatedFunds['cash'] = 0;
                }
                $allocatedFunds['cash'] += $allocationAmount;
                
                \Log::info('Cash allocation recorded', [
                    'amount' => $allocationAmount,
                    'total_cash_allocated' => $allocatedFunds['cash']
                ]);

            } elseif ($allocationType === 'bank') {
                $bankName = $request->bank_name;
                if (!$bankName) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bank name is required for bank allocation'
                    ], 400);
                }

                // Clean bank name for key (remove special characters)
                $bankKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $bankName));
                
                // Add to bank allocation - simple key-value pair
                if (!isset($allocatedFunds[$bankKey])) {
                    $allocatedFunds[$bankKey] = 0;
                }
                $allocatedFunds[$bankKey] += $allocationAmount;
                
                \Log::info('Bank allocation recorded', [
                    'bank_name' => $bankName,
                    'bank_key' => $bankKey,
                    'amount' => $allocationAmount,
                    'total_bank_allocated' => $allocatedFunds[$bankKey]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid allocation type. Must be "cash" or "bank"'
                ], 400);
            }
            
            // Update allocated funds
            $balance->allocated_funds = $allocatedFunds;
            
            // Calculate remaining balances for each source
            $remaining = [];
            
            // Calculate remaining cash
            $cashAvailable = $balance->cash_balance;
            $cashAllocated = $allocatedFunds['cash'] ?? 0;
            $remaining['cash'] = max(0, $cashAvailable - $cashAllocated);
            
            // Calculate remaining for each bank
            foreach ($bankBalances as $bankKey => $bankAmount) {
                $bankAllocated = $allocatedFunds[$bankKey] ?? 0;
                $remaining[$bankKey] = max(0, $bankAmount - $bankAllocated);
            }
            
            $balance->remaining = $remaining;
            $balance->save();

            DB::commit();

            // Prepare response data
            $responseData = [
                'allocated_funds' => $allocatedFunds,
                'remaining' => $remaining,
                'cash_balance' => $balance->cash_balance,
                'bank_balance' => $bankBalances,
                'total_balance' => $balance->cash_balance + array_sum($bankBalances)
            ];

            return response()->json([
                'success' => true,
                'data' => $responseData,
                'message' => "Allocated Rs. " . number_format($allocationAmount, 2) . " from " . ($allocationType === 'cash' ? 'Cash' : $bankName)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Fund allocation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to allocate funds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get allocated funds summary with breakdown
     */
    public function getAllocatedFunds(Request $request)
    {
        try {
            $balance = CashierBalance::latest()->first();
            
            if (!$balance) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'allocated_funds' => [],
                        'remaining' => [],
                        'cash_balance' => 0,
                        'bank_balance' => [],
                        'total_balance' => 0
                    ]
                ]);
            }

            $allocatedFunds = $balance->allocated_funds ?? [];
            if (!is_array($allocatedFunds)) {
                $allocatedFunds = [];
            }
            
            $remaining = $balance->remaining ?? [];
            if (!is_array($remaining)) {
                $remaining = [];
            }
            
            $bankBalances = $balance->bank_balance ?? [];
            if (!is_array($bankBalances)) {
                $bankBalances = [];
            }

            // Calculate totals - FIXED: Handle numeric values properly
            $totalAllocated = 0;
            foreach ($allocatedFunds as $amount) {
                if (is_numeric($amount)) {
                    $totalAllocated += $amount;
                }
            }
            
            $totalBalance = $balance->cash_balance + array_sum($bankBalances);
            $totalRemaining = $totalBalance - $totalAllocated;

            return response()->json([
                'success' => true,
                'data' => [
                    'allocated_funds' => $allocatedFunds,
                    'remaining' => $remaining,
                    'cash_balance' => $balance->cash_balance,
                    'bank_balance' => $bankBalances,
                    'total_balance' => $totalBalance,
                    'total_allocated' => $totalAllocated,
                    'total_remaining' => $totalRemaining,
                    'cashier_name' => $balance->cashier_name
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get allocated funds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of banks with their balances, allocated amounts, and remaining
     */
    public function getBankList(Request $request)
    {
        try {
            $balance = CashierBalance::latest()->first();
            
            if (!$balance) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $bankBalances = $balance->bank_balance ?? [];
            if (!is_array($bankBalances)) {
                $bankBalances = [];
            }
            
            $allocatedFunds = $balance->allocated_funds ?? [];
            if (!is_array($allocatedFunds)) {
                $allocatedFunds = [];
            }
            
            $remaining = $balance->remaining ?? [];
            if (!is_array($remaining)) {
                $remaining = [];
            }

            $bankList = [];
            foreach ($bankBalances as $bankKey => $amount) {
                $bankList[] = [
                    'key' => $bankKey,
                    'name' => str_replace('_', ' ', $bankKey),
                    'balance' => $amount,
                    'allocated' => $allocatedFunds[$bankKey] ?? 0,
                    'remaining' => $remaining[$bankKey] ?? $amount
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $bankList
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get bank list: ' . $e->getMessage()
            ], 500);
        }
    }
}