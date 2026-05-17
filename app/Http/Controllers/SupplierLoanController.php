<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\SupplierLoan;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Models\Setting;
use App\Models\Bank;
use App\Models\Supplier;

class SupplierLoanController extends Controller
{
    /**
     * Store a new supplier loan record with support for all payment types
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('SupplierLoan store endpoint hit', ['request_data' => $request->all()]);

        $validated = $request->validate([
            'code' => 'required|string',
            'loan_amount' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric',
            'bill_no' => 'nullable|string',
            'type' => 'nullable|string',
            'payment_details' => 'nullable|array',
            'transaction_ids' => 'nullable|array',
            'notes' => 'nullable|string',
            'bank_name' => 'nullable|string',
            'cheque_no' => 'nullable|string',
            'realized_date' => 'nullable|date',
            'bank_account_id' => 'nullable|integer',
            'transfer_reference_no' => 'nullable|string',
            'transfer_date' => 'nullable|date',
            'transfer_notes' => 'nullable|string',
            'bag_count' => 'nullable|integer',
            'box_count' => 'nullable|integer',
            'bag_value' => 'nullable|numeric',
            'box_value' => 'nullable|numeric',
            'adjustment_amount' => 'nullable|numeric',
            'target_supplier_code' => 'nullable|string',
            'target_supplier_bill_no' => 'nullable|string',
            'target_supplier_bill_value' => 'nullable|numeric',
            'bad_debt_name' => 'nullable|string',
            'bad_debt_amount' => 'nullable|numeric',
            'use_history' => 'nullable|boolean'
        ]);

        try {
            DB::beginTransaction();

            $useHistory = $request->input('use_history', false);
            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';
            
            // Calculate total payable from sales or sales_history records
            if ($useHistory) {
                $salesQuery = SalesHistory::where('supplier_code', $validated['code']);
            } else {
                $salesQuery = Sale::where('supplier_code', $validated['code']);
            }

            if (!empty($validated['bill_no'])) {
                $salesQuery->where('supplier_bill_no', $validated['bill_no']);
            }

            if (!empty($validated['transaction_ids'])) {
                $salesQuery->whereIn('id', $validated['transaction_ids']);
            }

            $salesRecords = $salesQuery->get();

            // Calculate the TOTAL PAYABLE from sales records (SupplierTotal sum)
            $totalPayable = $salesRecords->sum(function ($sale) {
                return (float) ($sale->SupplierTotal ?? 0);
            });

            Log::info('Calculated total payable', [
                'total_payable' => $totalPayable,
                'use_history' => $useHistory
            ]);

            $settingDate = $this->getSettingDate();

            // Get existing loan record from the appropriate table
            $existingLoan = DB::table($table)
                ->where('code', $validated['code'])
                ->where('bill_no', $validated['bill_no'])
                ->first();

            // ==================== FETCH CREDITOR NO FROM CREDITORS TABLE ====================
            $creditorNo = null;

            // Try to find existing creditor record
            $creditorRecord = Creditor::where('bill_no', $validated['bill_no'])
                ->where('supplier_code', $validated['code'])
                ->first();

            if ($creditorRecord && $creditorRecord->Creditor_no) {
                $creditorNo = $creditorRecord->Creditor_no;
                Log::info('Found existing creditor number from creditors table', [
                    'creditor_no' => $creditorNo,
                    'bill_no' => $validated['bill_no'],
                    'supplier_code' => $validated['code']
                ]);
            } elseif ($existingLoan && isset($existingLoan->Creditor_no) && $existingLoan->Creditor_no) {
                $creditorNo = $existingLoan->Creditor_no;
                Log::info('Using creditor number from existing loan', [
                    'creditor_no' => $creditorNo
                ]);
            }

            // Calculate payment amounts
            $currentPaidAmount = $existingLoan ? ($existingLoan->loan_amount ?? 0) : 0;
            $newPaidAmount = $currentPaidAmount + $validated['loan_amount'];
            
            // IMPORTANT: Store the original bill total in total_amount, not the remaining
            // This is the bill total amount from sales records
            $billTotalAmount = $totalPayable;

            Log::info('Payment calculation', [
                'current_paid' => $currentPaidAmount,
                'new_payment' => $validated['loan_amount'],
                'new_total_paid' => $newPaidAmount,
                'bill_total_amount' => $billTotalAmount,
                'total_payable' => $totalPayable,
                'creditor_no' => $creditorNo,
                'use_history' => $useHistory,
                'table' => $table
            ]);

            // Merge payment details
            $existingPaymentDetails = [];
            if ($existingLoan && isset($existingLoan->payment_details) && $existingLoan->payment_details) {
                $existingPaymentDetails = is_array($existingLoan->payment_details)
                    ? $existingLoan->payment_details
                    : (json_decode($existingLoan->payment_details, true) ?: []);
            }

            $newPaymentDetails = $validated['payment_details'] ?? [];
            $mergedPaymentDetails = array_merge($existingPaymentDetails, $newPaymentDetails);

            // Prepare the data array - total_amount is the BILL TOTAL, not remaining
            $loanData = [
                'code' => $validated['code'],
                'bill_no' => $validated['bill_no'],
                'loan_amount' => $newPaidAmount,
                'total_amount' => $billTotalAmount,
                'notes' => $validated['notes'] ?? null,
                'Date' => $settingDate,
                'payment_type' => $validated['type'] ?? 'Cash',
                'payment_details' => json_encode($mergedPaymentDetails),
                'Creditor_no' => $creditorNo,
            ];

            // Handle payment type and specific fields
            $paymentType = $validated['type'] ?? $request->input('type') ?? 'Cash';
            $loanData['type'] = $paymentType;

            // Check for bad debt
            $badDebtName = $validated['bad_debt_name'] ?? $request->input('bad_debt_name') ?? null;
            $badDebtAmount = $validated['bad_debt_amount'] ?? $request->input('bad_debt_amount') ?? 0;

            if (empty($badDebtName) && !empty($validated['payment_details'])) {
                foreach ($validated['payment_details'] as $payment) {
                    if (isset($payment['method']) && $payment['method'] === 'bad_debt') {
                        $badDebtName = $payment['bad_debt_name'] ?? $payment['name'] ?? null;
                        $badDebtAmount = $payment['bad_debt_amount'] ?? $payment['amount'] ?? 0;
                        break;
                    }
                }
            }

            if ($paymentType === 'bad_debt' || !empty($badDebtName)) {
                $loanData['bad_debt_name'] = $badDebtName;
                $loanData['bad_debt_amount'] = floatval($badDebtAmount);
                $loanData['type'] = 'bad_debt';
                Log::info('Setting bad debt fields', [
                    'bad_debt_name' => $badDebtName,
                    'bad_debt_amount' => $badDebtAmount
                ]);
            }

            // Handle other payment types
            if ($paymentType === 'Cheque') {
                $loanData['bank_name'] = $validated['bank_name'] ?? null;
                $loanData['cheque_no'] = $validated['cheque_no'] ?? null;
                $loanData['realized_date'] = $validated['realized_date'] ?? $settingDate;
                $loanData['bank_account_id'] = $validated['bank_account_id'] ?? null;
            } elseif ($paymentType === 'Bank Transfer') {
                $loanData['bank_name'] = $validated['bank_name'] ?? null;
                $loanData['transfer_reference_no'] = $validated['transfer_reference_no'] ?? null;
                $loanData['transfer_date'] = $validated['transfer_date'] ?? $settingDate;
                $loanData['transfer_notes'] = $validated['transfer_notes'] ?? null;
                $loanData['bank_account_id'] = $validated['bank_account_id'] ?? null;
            } elseif ($paymentType === 'bag_to_box') {
                $loanData['bag_count'] = $validated['bag_count'] ?? 0;
                $loanData['box_count'] = $validated['box_count'] ?? 0;
                $loanData['bag_value'] = $validated['bag_value'] ?? 0;
                $loanData['box_value'] = $validated['box_value'] ?? 0;
                $loanData['adjustment_amount'] = $validated['loan_amount'];
            } elseif ($paymentType === 'bill_to_bill') {
                $loanData['target_supplier_code'] = $validated['target_supplier_code'] ?? null;
                $loanData['target_supplier_bill_no'] = $validated['target_supplier_bill_no'] ?? null;
                $loanData['target_supplier_bill_value'] = $validated['target_supplier_bill_value'] ?? 0;
                $loanData['adjustment_amount'] = $validated['loan_amount'];
            }

            Log::info('Final loan data before save', $loanData);

            // Update or create record in the appropriate table
            DB::table($table)->updateOrInsert(
                [
                    'code' => $validated['code'],
                    'bill_no' => $validated['bill_no']
                ],
                $loanData
            );

            // Get the saved record
            $savedLoan = DB::table($table)
                ->where('code', $validated['code'])
                ->where('bill_no', $validated['bill_no'])
                ->first();

            Log::info('Loan record saved', ['table' => $table, 'loan_amount' => $savedLoan->loan_amount ?? $newPaidAmount, 'total_amount' => $savedLoan->total_amount ?? $billTotalAmount, 'creditor_no' => $creditorNo]);

            // ==================== UPDATE SALES/SALES_HISTORY TABLE WITH CREDITOR_NO ====================
            if ($creditorNo) {
                if ($useHistory) {
                    $updatedSalesCount = SalesHistory::where('supplier_code', $validated['code'])
                        ->where('supplier_bill_no', $validated['bill_no'])
                        ->update([
                            'Creditor_no' => $creditorNo,
                            'updated_at' => now()
                        ]);
                } else {
                    $updatedSalesCount = Sale::where('supplier_code', $validated['code'])
                        ->where('supplier_bill_no', $validated['bill_no'])
                        ->update([
                            'Creditor_no' => $creditorNo,
                            'updated_at' => now()
                        ]);
                }

                Log::info('Updated sales records with Creditor_no from creditors table', [
                    'creditor_no' => $creditorNo,
                    'supplier_code' => $validated['code'],
                    'bill_no' => $validated['bill_no'],
                    'records_updated' => $updatedSalesCount,
                    'use_history' => $useHistory
                ]);
            } else {
                Log::warning('No creditor number found to update sales table', [
                    'supplier_code' => $validated['code'],
                    'bill_no' => $validated['bill_no']
                ]);
            }

            // Update sales records (loan taken flag) - only for current table
            if (!$useHistory) {
                $this->updateSalesRecords($validated);
            }

            // If this is a bill_to_bill payment, update the target supplier bill
            if ($paymentType === 'bill_to_bill') {
                if (!empty($validated['target_supplier_code']) && !empty($validated['target_supplier_bill_no'])) {
                    $this->updateTargetSupplierBillPayment(
                        $validated['target_supplier_code'],
                        $validated['target_supplier_bill_no'],
                        $validated['target_supplier_bill_value'] ?? 0,
                        $creditorNo,
                        $useHistory
                    );
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment saved successfully.',
                'data' => $savedLoan,
                'creditor_no' => $creditorNo,
                'bill_total' => $billTotalAmount,
                'table_used' => $table
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Loan Store Error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get supplier loans summary with option to view history
     */
    public function getSupplierLoansSummary(Request $request): JsonResponse
    {
        try {
            $useHistory = $request->query('use_history', false);
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $isDateFiltered = $request->query('date_filtered', false);

            // Determine which table to use for loans
            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';
            
            Log::info('Fetching supplier loans summary', [
                'use_history' => $useHistory,
                'table' => $table,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_date_filtered' => $isDateFiltered
            ]);

            if ($useHistory) {
                // For history table, we need to show ALL records from history
                $query = DB::table($table)->select('code', 'bill_no', 'loan_amount', 'total_amount', 'Creditor_no', 'Date', 'type', 'payment_details');
                
                // Apply date filters if provided
                if ($isDateFiltered && $startDate && $endDate) {
                    $start = date('Y-m-d', strtotime($startDate));
                    $end = date('Y-m-d', strtotime($endDate));
                    $query->whereDate('Date', '>=', $start)
                          ->whereDate('Date', '<=', $end);
                    Log::info('Applied date filter to query', ['start' => $start, 'end' => $end]);
                }
                
                $allLoans = $query->get();
                
                Log::info('History loans fetched', [
                    'count' => $allLoans->count(),
                    'sample' => $allLoans->take(5)->toArray()
                ]);
                
                // For history, separate pending vs completed based on payment status
                $pendingBills = [];
                $completedBills = [];
                
                foreach ($allLoans as $loan) {
                    $totalAmount = floatval($loan->total_amount);
                    $loanAmount = floatval($loan->loan_amount);
                    
                    // Check if this loan is fully paid (loan_amount >= total_amount means fully paid)
                    // Note: total_amount in history is the bill total, loan_amount is total paid
                    if ($loanAmount >= $totalAmount) {
                        // Fully paid - FULLY SETTLED
                        $completedBills[] = [
                            'supplier_code' => $loan->code,
                            'supplier_bill_no' => $loan->bill_no,
                            'loan_amount' => $loanAmount,
                            'total_amount' => $totalAmount,
                            'creditor_no' => $loan->Creditor_no,
                            'date' => $loan->Date ?? null,
                            'is_history' => true,
                            'type' => $loan->type ?? null
                        ];
                    } else {
                        // Still has remaining balance - NOT SETTLED (from history)
                        $pendingBills[] = [
                            'supplier_code' => $loan->code,
                            'supplier_bill_no' => $loan->bill_no,
                            'loan_amount' => $loanAmount,
                            'total_amount' => $totalAmount,
                            'creditor_no' => $loan->Creditor_no,
                            'date' => $loan->Date ?? null,
                            'is_history' => true,
                            'type' => $loan->type ?? null
                        ];
                    }
                }
                
                Log::info('History summary result', [
                    'pending_count' => count($pendingBills),
                    'completed_count' => count($completedBills),
                    'total_history_records' => $allLoans->count()
                ]);
                
                return response()->json([
                    'printed' => $completedBills,    // Fully settled from history
                    'unprinted' => $pendingBills,     // Not settled from history
                    'table_used' => $table,
                    'date_filter_applied' => $isDateFiltered ? true : false,
                    'total_loans_found' => $allLoans->count()
                ]);
                
            } else {
                // For current table, use the original logic with sales matching
                // Get all printed bills from sales table
                $allPrintedBills = DB::table('sales')
                    ->select('supplier_code', 'supplier_bill_no')
                    ->whereNotNull('supplier_code')
                    ->where('supplier_bill_printed', 'Y')
                    ->whereNotNull('supplier_bill_no')
                    ->where('supplier_bill_no', '!=', '')
                    ->groupBy('supplier_code', 'supplier_bill_no')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'supplier_code' => $item->supplier_code,
                            'supplier_bill_no' => $item->supplier_bill_no
                        ];
                    });

                Log::info('Found printed bills count', ['count' => $allPrintedBills->count()]);

                // Get loans from current table
                $query = DB::table($table)->select('code', 'bill_no', 'loan_amount', 'total_amount', 'Creditor_no', 'Date', 'type');
                
                $allLoans = $query->get();
                
                Log::info('Current loans fetched', [
                    'count' => $allLoans->count(),
                    'sample' => $allLoans->take(5)->toArray()
                ]);

                // Create a keyed collection for faster lookup
                $allLoansKeyed = $allLoans->keyBy(function ($item) {
                    return $item->code . '|' . $item->bill_no;
                });

                // Separate: Pending = have loans with remaining balance > 0
                $pendingBills = [];
                $completedBills = [];

                foreach ($allPrintedBills as $bill) {
                    $key = $bill['supplier_code'] . '|' . $bill['supplier_bill_no'];
                    
                    if (isset($allLoansKeyed[$key])) {
                        // Has loan record
                        $loanRecord = $allLoansKeyed[$key];
                        $totalAmount = floatval($loanRecord->total_amount);
                        $loanAmount = floatval($loanRecord->loan_amount);
                        $remainingAmount = $totalAmount - $loanAmount;
                        
                        if ($remainingAmount > 0) {
                            // Still has remaining balance - NOT SETTLED
                            $pendingBills[] = [
                                'supplier_code' => $bill['supplier_code'],
                                'supplier_bill_no' => $bill['supplier_bill_no'],
                                'loan_amount' => $loanAmount,
                                'total_amount' => $remainingAmount,
                                'creditor_no' => $loanRecord->Creditor_no,
                                'date' => $loanRecord->Date ?? null,
                                'type' => $loanRecord->type ?? null
                            ];
                        } else {
                            // Fully paid - FULLY SETTLED
                            $completedBills[] = [
                                'supplier_code' => $bill['supplier_code'],
                                'supplier_bill_no' => $bill['supplier_bill_no'],
                                'creditor_no' => $loanRecord->Creditor_no,
                                'date' => $loanRecord->Date ?? null,
                                'type' => $loanRecord->type ?? null
                            ];
                        }
                    } else {
                        // No loan record - means never paid, treat as pending with full amount
                        $totalPayable = DB::table('sales')
                            ->where('supplier_code', $bill['supplier_code'])
                            ->where('supplier_bill_no', $bill['supplier_bill_no'])
                            ->where('supplier_bill_printed', 'Y')
                            ->sum('SupplierTotal');

                        $pendingBills[] = [
                            'supplier_code' => $bill['supplier_code'],
                            'supplier_bill_no' => $bill['supplier_bill_no'],
                            'loan_amount' => 0,
                            'total_amount' => floatval($totalPayable),
                            'creditor_no' => null,
                            'date' => null,
                            'type' => null
                        ];
                    }
                }

                Log::info('Current summary result', [
                    'pending_count' => count($pendingBills),
                    'completed_count' => count($completedBills),
                    'table_used' => $table
                ]);

                return response()->json([
                    'printed' => $completedBills,
                    'unprinted' => $pendingBills,
                    'table_used' => $table,
                    'date_filter_applied' => false,
                    'total_loans_found' => $allLoans->count()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in getSupplierLoansSummary: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'printed' => [],
                'unprinted' => []
            ]);
        }
    }

    /**
     * Get payment history with support for history table
     */
    public function getPaymentHistory(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $billNo = $request->query('bill_no');
        $useHistory = $request->query('use_history', false);
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        try {
            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';
            
            $query = DB::table($table)
                ->where('code', $code)
                ->where('bill_no', $billNo);
            
            // Apply date filters if provided
            if ($startDate && $endDate) {
                $start = date('Y-m-d', strtotime($startDate));
                $end = date('Y-m-d', strtotime($endDate));
                $query->whereDate('Date', '>=', $start)
                      ->whereDate('Date', '<=', $end);
            } elseif ($startDate) {
                $query->whereDate('Date', '>=', date('Y-m-d', strtotime($startDate)));
            } elseif ($endDate) {
                $query->whereDate('Date', '<=', date('Y-m-d', strtotime($endDate)));
            }
            
            $loan = $query->first();

            if (!$loan) {
                return response()->json([
                    'success' => false,
                    'message' => 'No payment records found'
                ], 404);
            }

            $paymentDetails = isset($loan->payment_details) ? $loan->payment_details : null;
            if (is_string($paymentDetails)) {
                $paymentDetails = json_decode($paymentDetails, true);
            }
            if (!is_array($paymentDetails)) {
                $paymentDetails = [];
            }

            $totalAmount = floatval($loan->total_amount ?? 0);
            $loanAmount = floatval($loan->loan_amount ?? 0);
            $remainingBalance = $totalAmount - $loanAmount;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_paid' => $loanAmount,
                    'remaining_balance' => $remainingBalance,
                    'payments' => $paymentDetails,
                    'payment_methods' => $loan->type ?? null,
                    'creditor_no' => $loan->Creditor_no ?? null,
                    'table_used' => $table
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Payment History Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history'
            ], 500);
        }
    }

    /**
     * Find loan by code and bill number with history support
     */
    public function findLoan(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $billNo = $request->query('bill_no');
        $useHistory = $request->query('use_history', false);
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';
        
        $query = DB::table($table)
            ->where('code', $code)
            ->where('bill_no', $billNo);
        
        // Apply date filters if provided
        if ($startDate && $endDate) {
            $start = date('Y-m-d', strtotime($startDate));
            $end = date('Y-m-d', strtotime($endDate));
            $query->whereDate('Date', '>=', $start)
                  ->whereDate('Date', '<=', $end);
        } elseif ($startDate) {
            $query->whereDate('Date', '>=', date('Y-m-d', strtotime($startDate)));
        } elseif ($endDate) {
            $query->whereDate('Date', '<=', date('Y-m-d', strtotime($endDate)));
        }
        
        $loan = $query->first();

        if (!$loan) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (isset($loan->payment_details) && is_string($loan->payment_details)) {
            $loan->payment_details = json_decode($loan->payment_details, true);
        }

        return response()->json($loan);
    }

    /**
     * Get supplier bill details for a specific bill (with history support)
     */
    public function getSupplierBillDetails($billNo, Request $request): JsonResponse
    {
        try {
            $supplierCode = $request->query('supplier_code');
            $useHistory = $request->query('use_history', false);

            // Fetch from appropriate table based on useHistory flag
            if ($useHistory) {
                $sales = SalesHistory::where('supplier_bill_no', $billNo)
                    ->where('supplier_code', $supplierCode)
                    ->where('supplier_bill_printed', 'Y')
                    ->get();
            } else {
                $sales = Sale::where('supplier_bill_no', $billNo)
                    ->where('supplier_code', $supplierCode)
                    ->where('supplier_bill_printed', 'Y')
                    ->get();
            }

            if ($sales->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sales found for this bill'
                ], 404);
            }

            // If using history, also fetch the loan record
            if ($useHistory) {
                $loanRecord = DB::table('supplier_loans_history')
                    ->where('code', $supplierCode)
                    ->where('bill_no', $billNo)
                    ->first();
                    
                return response()->json([
                    'sales' => $sales,
                    'loan_record' => $loanRecord,
                    'use_history' => true
                ]);
            }

            return response()->json($sales);

        } catch (\Exception $e) {
            Log::error('Error fetching supplier bill details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bill details'
            ], 500);
        }
    }

    /**
     * Get unprinted supplier details (pending loans) - with history support
     */
    public function getUnprintedDetails($supplierCode, Request $request): JsonResponse
    {
        try {
            $useHistory = $request->query('use_history', false);
            
            // Fetch from appropriate table based on useHistory flag
            if ($useHistory) {
                $sales = SalesHistory::where('supplier_code', $supplierCode)
                    ->where(function ($q) {
                        $q->where('supplier_bill_printed', 'N')
                            ->orWhereNull('supplier_bill_printed');
                    })
                    ->where('bill_printed', 'Y')
                    ->get();
            } else {
                $sales = Sale::where('supplier_code', $supplierCode)
                    ->where(function ($q) {
                        $q->where('supplier_bill_printed', 'N')
                            ->orWhereNull('supplier_bill_printed');
                    })
                    ->where('bill_printed', 'Y')
                    ->get();
            }

            if ($sales->isEmpty()) {
                return response()->json([
                    'success' => false,
                ], 404);
            }

            // If using history, fetch loan records for these sales
            if ($useHistory) {
                $loanRecords = DB::table('supplier_loans_history')
                    ->where('code', $supplierCode)
                    ->get()
                    ->keyBy('bill_no');
                    
                return response()->json([
                    'sales' => $sales,
                    'loan_records' => $loanRecords,
                    'use_history' => true
                ]);
            }

            return response()->json($sales);

        } catch (\Exception $e) {
            Log::error('Error fetching unprinted details: ' . $e->getMessage());
            return response()->json([
                'success' => false
            ], 500);
        }
    }

    /**
     * Delete loan record (supports both current and history tables)
     */
    public function deleteLoanRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'bill_no' => 'nullable|string',
            'use_history' => 'nullable|boolean'
        ]);

        $useHistory = $validated['use_history'] ?? false;
        $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';

        DB::beginTransaction();

        try {
            // Delete from the appropriate table
            $deleted = DB::table($table)
                ->where('code', $validated['code'])
                ->where('bill_no', $validated['bill_no'])
                ->delete();

            if (!$deleted) {
                DB::rollback();
                return response()->json([
                    'success' => false,
                    'message' => 'Loan record not found.'
                ], 404);
            }

            // Also delete creditor record if exists
            $creditor = Creditor::where('bill_no', $validated['bill_no'])
                ->where('supplier_code', $validated['code'])
                ->first();

            if ($creditor) {
                $creditor->forceDelete();
            }

            // Only update sales records for current table (not history)
            if (!$useHistory) {
                Sale::where('supplier_code', $validated['code'])
                    ->where('supplier_bill_no', $validated['bill_no'])
                    ->update([
                        'loan_taken' => null,
                        'Creditor_no' => null
                    ]);
            } else {
                // Also remove from sales_history if needed
                SalesHistory::where('supplier_code', $validated['code'])
                    ->where('supplier_bill_no', $validated['bill_no'])
                    ->update([
                        'Creditor_no' => null
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Loan and creditor records permanently deleted successfully.',
                'table_used' => $table
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all banks
     */
    public function getBanks(): JsonResponse
    {
        try {
            $banks = Bank::all(['id', 'bank_name', 'branch', 'account_no']);
            return response()->json([
                'success' => true,
                'data' => $banks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch banks'
            ], 500);
        }
    }

    /**
     * Get banks list for dropdown
     */
    public function getBanksList(): JsonResponse
    {
        try {
            $banks = Bank::select('id', 'bank_name', 'branch', 'account_no')->get();
            return response()->json([
                'success' => true,
                'data' => $banks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch banks'
            ], 500);
        }
    }

    /**
     * Update target supplier bill payment (with history support)
     */
    protected function updateTargetSupplierBillPayment($supplierCode, $supplierBillNo, $paymentAmount, $creditorNo = null, $useHistory = false)
    {
        try {
            Log::info('Updating target supplier bill payment', [
                'supplier_code' => $supplierCode,
                'supplier_bill_no' => $supplierBillNo,
                'payment_amount' => $paymentAmount,
                'creditor_no' => $creditorNo,
                'use_history' => $useHistory
            ]);

            $targetCreditorNo = $creditorNo;

            if (!$targetCreditorNo) {
                $creditorRecord = Creditor::where('bill_no', $supplierBillNo)
                    ->where('supplier_code', $supplierCode)
                    ->first();

                if ($creditorRecord && $creditorRecord->Creditor_no) {
                    $targetCreditorNo = $creditorRecord->Creditor_no;
                }
            }

            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';
            
            $targetLoan = DB::table($table)
                ->where('code', $supplierCode)
                ->where('bill_no', $supplierBillNo)
                ->first();

            // Get total payable from appropriate sales table
            if ($useHistory) {
                $totalPayable = SalesHistory::where('supplier_code', $supplierCode)
                    ->where('supplier_bill_no', $supplierBillNo)
                    ->sum('SupplierTotal');
            } else {
                $totalPayable = Sale::where('supplier_code', $supplierCode)
                    ->where('supplier_bill_no', $supplierBillNo)
                    ->sum('SupplierTotal');
            }

            if ($targetLoan) {
                $currentPaid = floatval($targetLoan->loan_amount ?? 0);
                $newPaidAmount = $currentPaid + $paymentAmount;
                
                $updateData = [
                    'loan_amount' => $newPaidAmount,
                    'updated_at' => now()
                ];
                
                if ($targetCreditorNo && empty($targetLoan->Creditor_no)) {
                    $updateData['Creditor_no'] = $targetCreditorNo;
                }
                
                DB::table($table)
                    ->where('code', $supplierCode)
                    ->where('bill_no', $supplierBillNo)
                    ->update($updateData);
                    
                Log::info('Updated target loan record', [
                    'table' => $table,
                    'new_paid_amount' => $newPaidAmount
                ]);
            } else {
                $newLoanData = [
                    'code' => $supplierCode,
                    'bill_no' => $supplierBillNo,
                    'loan_amount' => $paymentAmount,
                    'total_amount' => $totalPayable,
                    'Date' => now(),
                    'payment_type' => 'Bill to Bill Transfer',
                    'type' => 'bill_to_bill',
                    'Creditor_no' => $targetCreditorNo,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                
                DB::table($table)->insert($newLoanData);
                Log::info('Created new target loan record', ['table' => $table]);
            }

            // Only update sales for current table (not history)
            if (!$useHistory) {
                if ($targetCreditorNo) {
                    Sale::where('supplier_code', $supplierCode)
                        ->where('supplier_bill_no', $supplierBillNo)
                        ->update([
                            'supplier_paid_amount' => DB::raw('COALESCE(supplier_paid_amount, 0) + ' . floatval($paymentAmount)),
                            'supplier_paid_status' => 'Y',
                            'Creditor_no' => $targetCreditorNo,
                            'updated_at' => now()
                        ]);
                } else {
                    Sale::where('supplier_code', $supplierCode)
                        ->where('supplier_bill_no', $supplierBillNo)
                        ->update([
                            'supplier_paid_amount' => DB::raw('COALESCE(supplier_paid_amount, 0) + ' . floatval($paymentAmount)),
                            'supplier_paid_status' => 'Y',
                            'updated_at' => now()
                        ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Error updating target supplier bill payment: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get setting date from database
     */
    private function getSettingDate()
    {
        $settingDate = null;

        $setting = Setting::where('key', 'current_date')->first();
        if ($setting && $setting->value) {
            $settingDate = $setting->value;
        }

        if (!$settingDate) {
            $setting = Setting::where('key', 'Date_of_balance')->first();
            if ($setting && $setting->value) {
                $settingDate = $setting->value;
            }
        }

        if (!$settingDate) {
            $setting = Setting::first();
            if ($setting && $setting->value) {
                $settingDate = $setting->value;
            }
        }

        if (!$settingDate) {
            $settingDate = now()->format('Y-m-d');
        }

        return $settingDate;
    }

    /**
     * Update sales records
     */
    private function updateSalesRecords($validated)
    {
        $salesQuery = Sale::where('supplier_code', $validated['code']);

        if (!empty($validated['bill_no'])) {
            $salesQuery->where('supplier_bill_no', $validated['bill_no']);
        }

        if (!empty($validated['transaction_ids'])) {
            $salesQuery->whereIn('id', $validated['transaction_ids']);
        }

        $paymentType = $validated['type'] ?? 'Multiple';
        if (!empty($validated['bad_debt_name'])) {
            $paymentType = 'bad_debt';
        }

        $count = $salesQuery->update([
            'loan_taken' => 'Y',
            'payment_type' => $paymentType
        ]);

        Log::info('Updated sales records count', ['count' => $count]);
    }

    /**
     * Get pending customer bills
     */
    public function getPendingCustomerBills(Request $request): JsonResponse
    {
        try {
            $request->validate(['customer_code' => 'required|string']);

            $pendingBills = DB::table('sales')
                ->select(
                    'bill_no',
                    'customer_code',
                    DB::raw('SUM(total + (packs * CustomerPackCost)) as total_amount'),
                    DB::raw('MAX(given_amount) as given_amount')
                )
                ->where('customer_code', $request->customer_code)
                ->where('bill_printed', 'Y')
                ->where('given_amount_applied', 'N')
                ->groupBy('bill_no', 'customer_code')
                ->havingRaw('SUM(total + (packs * CustomerPackCost)) > COALESCE(MAX(given_amount), 0)')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pendingBills
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending bills'
            ], 500);
        }
    }

    /**
     * Get pending farmer bills
     */
    public function getPendingFarmerBills(Request $request): JsonResponse
    {
        try {
            $request->validate(['supplier_code' => 'required|string']);

            $pendingBills = DB::table('sales')
                ->select(
                    'supplier_bill_no',
                    'supplier_code',
                    DB::raw('SUM(SupplierTotal) as total_amount')
                )
                ->where('supplier_code', $request->supplier_code)
                ->where('supplier_bill_printed', 'Y')
                ->where('supplier_paid_status', 'N')
                ->whereNotNull('supplier_bill_no')
                ->groupBy('supplier_bill_no', 'supplier_code')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pendingBills
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending farmer bills'
            ], 500);
        }
    }

    /**
     * Get all supplier codes
     */
    public function getAllCodes(): JsonResponse
    {
        return response()->json(Supplier::select('id', 'code', 'name')->get());
    }

    /**
     * Get suppliers by first letter of code (only those with Creditor = 'Y')
     */
    public function getSuppliersByLetter(Request $request): JsonResponse
    {
        try {
            $letter = $request->query('letter', '');

            $query = Supplier::where('Creditor', 'Y')
                ->select('code', 'Creditor_no', 'name');

            if (!empty($letter)) {
                $query->where('code', 'LIKE', $letter . '%');
            }

            $suppliers = $query->orderBy('code', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => $suppliers
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching suppliers by letter: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch suppliers'
            ], 500);
        }
    }
}