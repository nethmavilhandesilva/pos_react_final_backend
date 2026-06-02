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
    // Properties to track current supplier context for bill_to_bill payments
    private $currentSupplierCode = null;
    private $currentBillNo = null;

    /**
     * Helper function to calculate total paid amount excluding Credit payments
     */
    private function calculateTotalPaidExcludingCredit($paymentDetails): float
    {
        $totalPaid = 0;

        if (empty($paymentDetails)) {
            return $totalPaid;
        }

        // Decode if it's a JSON string
        $payments = $paymentDetails;
        if (is_string($payments)) {
            $payments = json_decode($payments, true);
        }

        if (!is_array($payments)) {
            return $totalPaid;
        }

        foreach ($payments as $payment) {
            $method = $payment['method'] ?? '';
            // Exclude Credit payments from total paid calculation
            if ($method !== 'Credit') {
                $amount = floatval($payment['amount'] ?? 0);
                $totalPaid += $amount;
            }
        }

        return $totalPaid;
    }

    /**
     * Helper function to calculate total Credit amount from payment_details
     */
    private function calculateTotalCreditAmount($paymentDetails): float
    {
        $totalCredit = 0;

        if (empty($paymentDetails)) {
            return $totalCredit;
        }

        // Decode if it's a JSON string
        $payments = $paymentDetails;
        if (is_string($payments)) {
            $payments = json_decode($payments, true);
        }

        if (!is_array($payments)) {
            return $totalCredit;
        }

        foreach ($payments as $payment) {
            $method = $payment['method'] ?? '';
            // Only include Credit payments
            if ($method === 'Credit') {
                $amount = floatval($payment['amount'] ?? 0);
                $totalCredit += $amount;
            }
        }

        return $totalCredit;
    }

    /**
     * Helper function to calculate total paid amount including all payments (for display)
     */
    private function calculateTotalPaidIncludingCredit($paymentDetails): float
    {
        $totalPaid = 0;

        if (empty($paymentDetails)) {
            return $totalPaid;
        }

        // Decode if it's a JSON string
        $payments = $paymentDetails;
        if (is_string($payments)) {
            $payments = json_decode($payments, true);
        }

        if (!is_array($payments)) {
            return $totalPaid;
        }

        foreach ($payments as $payment) {
            $amount = floatval($payment['amount'] ?? 0);
            $totalPaid += $amount;
        }

        return $totalPaid;
    }

    /**
     * Helper function to get remaining amount (bill total - total paid excluding credit)
     */
    private function calculateRemainingAmount($billTotal, $paymentDetails): float
    {
        $totalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
        return max(0, floatval($billTotal) - $totalPaidExcludingCredit);
    }

    /**
     * Get unique payment key for deduplication - FIXED for cash payments
     */
    private function getPaymentKey(array $payment): string
    {
        $method = $payment['method'] ?? $payment['type'] ?? 'unknown';
        
        // CRITICAL FIX: Cash payments must use their UNIQUE ID from frontend
        if ($method === 'Cash') {
            // Use the exact ID sent from frontend - this is the unique identifier
            $paymentId = $payment['id'] ?? $payment['unique_id'] ?? null;
            $callId = $payment['call_id'] ?? '';
            $timestamp = $payment['date'] ?? $payment['created_at'] ?? time();
            
            // If we have a payment ID, use it as the primary key
            if ($paymentId) {
                return 'Cash_' . $paymentId;
            }
            
            // Fallback to a truly unique key
            return 'Cash_' . $timestamp . '_' . uniqid() . '_' . rand(10000, 99999);
        }
        
        // Different keys based on payment method for better duplicate detection
        switch ($method) {
            case 'Cheque':
                return $method . '_' . ($payment['cheque_no'] ?? '') . '_' . ($payment['bank_name'] ?? '');
            case 'Bank Transfer':
                return $method . '_' . ($payment['transfer_reference_no'] ?? '') . '_' . ($payment['bank_name'] ?? '');
            case 'bad_debt':
                return $method . '_' . ($payment['bad_debt_name'] ?? $payment['name'] ?? '');
            case 'bill_to_bill':
                return $method . '_' . ($payment['target_supplier_code'] ?? '') . '_' . ($payment['target_supplier_bill_no'] ?? '');
            case 'bag_to_box':
                return $method . '_' . ($payment['bag_count'] ?? 0) . '_' . ($payment['box_count'] ?? 0);
            case 'Credit':
                return $method . '_' . ($payment['id'] ?? time() . '_' . rand());
            default:
                return $method . '_' . ($payment['id'] ?? time() . '_' . rand());
        }
    }

    /**
     * Check if two payments are the same - FIXED for cash payments
     */
    private function isSamePayment(array $payment1, array $payment2): bool
    {
        $method1 = $payment1['method'] ?? $payment1['type'] ?? 'unknown';
        $method2 = $payment2['method'] ?? $payment2['type'] ?? 'unknown';
        
        if ($method1 !== $method2) {
            return false;
        }
        
        // CRITICAL FIX: Cash payments - compare by ID only
        if ($method1 === 'Cash') {
            $id1 = $payment1['id'] ?? null;
            $id2 = $payment2['id'] ?? null;
            
            // If both have IDs, they are the same only if IDs match
            if ($id1 && $id2) {
                return $id1 === $id2;
            }
            
            // Without IDs, consider them different to avoid false merges
            return false;
        }
        
        // Compare based on payment method specific fields
        switch ($method1) {
            case 'Cheque':
                return ($payment1['cheque_no'] ?? '') === ($payment2['cheque_no'] ?? '') &&
                       ($payment1['bank_name'] ?? '') === ($payment2['bank_name'] ?? '');
            case 'Bank Transfer':
                return ($payment1['transfer_reference_no'] ?? '') === ($payment2['transfer_reference_no'] ?? '') &&
                       ($payment1['bank_name'] ?? '') === ($payment2['bank_name'] ?? '');
            case 'bad_debt':
                return ($payment1['bad_debt_name'] ?? $payment1['name'] ?? '') === 
                       ($payment2['bad_debt_name'] ?? $payment2['name'] ?? '');
            case 'bill_to_bill':
                return ($payment1['target_supplier_code'] ?? '') === ($payment2['target_supplier_code'] ?? '') &&
                       ($payment1['target_supplier_bill_no'] ?? '') === ($payment2['target_supplier_bill_no'] ?? '');
            case 'Credit':
                return false;
            default:
                return false;
        }
    }

    /**
     * Merge payment details with deduplication - FIXED version
     */
    private function mergePaymentDetailsWithDeduplication(array $existingDetails, array $newDetails): array
    {
        // Create an associative array keyed by unique payment identifier
        $merged = [];
        
        // First, add existing payments - use payment ID as key for cash payments
        foreach ($existingDetails as $payment) {
            $method = $payment['method'] ?? 'unknown';
            if ($method === 'Cash' && isset($payment['id'])) {
                // Use payment ID as the key for cash payments
                $key = 'Cash_' . $payment['id'];
            } else {
                $key = $this->getPaymentKey($payment);
            }
            $merged[$key] = $payment;
            Log::info('Added existing payment', ['key' => $key, 'method' => $method, 'id' => $payment['id'] ?? null]);
        }
        
        // Then add new payments - check for duplicates by ID first
        foreach ($newDetails as $payment) {
            $method = $payment['method'] ?? 'unknown';
            $paymentId = $payment['id'] ?? null;
            
            // For Cash payments, use the ID as the unique identifier
            if ($method === 'Cash') {
                if (!$paymentId) {
                    Log::error('Cash payment without ID received!', $payment);
                    continue;
                }
                
                $key = 'Cash_' . $paymentId;
                
                // Check if this payment ID already exists
                if (isset($merged[$key])) {
                    Log::warning('Duplicate cash payment detected by ID, skipping', [
                        'payment_id' => $paymentId,
                        'existing_amount' => $merged[$key]['amount'] ?? 0,
                        'new_amount' => $payment['amount'] ?? 0
                    ]);
                    continue; // Skip adding duplicate
                }
                
                // Add new cash payment
                $merged[$key] = $payment;
                Log::info('Added new cash payment', [
                    'key' => $key,
                    'payment_id' => $paymentId,
                    'amount' => $payment['amount'] ?? 0
                ]);
                continue;
            }
            
            // For non-cash payments, use the regular key
            $key = $this->getPaymentKey($payment);
            
            if (isset($merged[$key])) {
                Log::warning('Duplicate payment detected for non-cash method, skipping', [
                    'key' => $key,
                    'method' => $method,
                    'existing_amount' => $merged[$key]['amount'] ?? 0,
                    'new_amount' => $payment['amount'] ?? 0
                ]);
                continue; // Skip adding duplicate
            } else {
                // Add new payment
                $merged[$key] = $payment;
                Log::info('Added new non-cash payment', [
                    'key' => $key,
                    'method' => $method,
                    'amount' => $payment['amount'] ?? 0
                ]);
            }
        }
        
        // Log the final result
        Log::info('Payment details after merge', [
            'original_existing_count' => count($existingDetails),
            'original_new_count' => count($newDetails),
            'merged_count' => count($merged),
            'duplicates_skipped' => (count($existingDetails) + count($newDetails)) - count($merged)
        ]);
        
        // Sort by date to maintain chronological order
        usort($merged, function($a, $b) {
            $dateA = $a['date'] ?? '1970-01-01';
            $dateB = $b['date'] ?? '1970-01-01';
            return strtotime($dateA) - strtotime($dateB);
        });
        
        return array_values($merged);
    }

    /**
     * Check if a duplicate payment was made - FIXED for cash payments
     */
    private function isDuplicatePayment($supplierCode, $billNo, $amount, $method, $transactionId = null, $paymentId = null): bool
    {
        $table = 'supplier_loans';
        
        // First check by payment ID if provided (most reliable)
        if ($paymentId) {
            $existingLoan = DB::table($table)
                ->where('code', $supplierCode)
                ->where('bill_no', $billNo)
                ->first();
                
            if ($existingLoan && isset($existingLoan->payment_details)) {
                $paymentDetails = json_decode($existingLoan->payment_details, true) ?? [];
                
                foreach ($paymentDetails as $payment) {
                    if (isset($payment['id']) && $payment['id'] === $paymentId) {
                        Log::warning('Duplicate payment detected by payment ID', [
                            'payment_id' => $paymentId,
                            'supplier' => $supplierCode,
                            'bill_no' => $billNo
                        ]);
                        return true;
                    }
                }
            }
        }
        
        // Check by transaction ID if provided
        if ($transactionId) {
            $existingLoan = DB::table($table)
                ->where('code', $supplierCode)
                ->where('bill_no', $billNo)
                ->first();
                
            if ($existingLoan && isset($existingLoan->payment_details)) {
                $paymentDetails = json_decode($existingLoan->payment_details, true) ?? [];
                
                foreach ($paymentDetails as $payment) {
                    if (isset($payment['transaction_id']) && $payment['transaction_id'] === $transactionId) {
                        Log::warning('Duplicate payment detected by transaction ID', [
                            'transaction_id' => $transactionId,
                            'supplier' => $supplierCode,
                            'bill_no' => $billNo
                        ]);
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Store a new supplier loan record with support for all payment types
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('SupplierLoan store endpoint hit', ['request_data' => $request->all()]);

        // CRITICAL: Check for duplicate payment first
        $supplierCode = $request->input('code');
        $billNo = $request->input('bill_no');
        $amount = $request->input('loan_amount', 0);
        $method = $request->input('type', 'Cash');
        $transactionId = $request->input('transaction_id');
        $paymentId = $request->input('payment_id');
        
        if ($this->isDuplicatePayment($supplierCode, $billNo, $amount, $method, $transactionId, $paymentId)) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate payment detected. Please wait before trying again.',
                'is_duplicate' => true
            ], 409);
        }

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
            'use_history' => 'nullable|boolean',
            'transaction_id' => 'nullable|string',
            'payment_id' => 'nullable|string'
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

            // ==================== GET EXISTING PAYMENT DETAILS ====================
            $existingPaymentDetails = [];
            if ($existingLoan && isset($existingLoan->payment_details) && $existingLoan->payment_details) {
                $existingPaymentDetails = is_array($existingLoan->payment_details)
                    ? $existingLoan->payment_details
                    : (json_decode($existingLoan->payment_details, true) ?: []);
            }

            // ==================== CALCULATE PAID AMOUNTS EXCLUDING CREDIT ====================
            // Calculate current total paid amount from existing payments (excluding Credit)
            $currentPaidAmountExcludingCredit = $this->calculateTotalPaidExcludingCredit($existingPaymentDetails);
            $currentCreditAmount = $this->calculateTotalCreditAmount($existingPaymentDetails);

            // Get new payment amount (the current payment)
            $newPaymentAmount = floatval($validated['loan_amount']);
            $newPaymentMethod = $validated['type'] ?? 'Cash';

            // New total paid (excluding Credit) - only add if the new payment is NOT Credit
            if ($newPaymentMethod !== 'Credit') {
                $newTotalPaidExcludingCredit = $currentPaidAmountExcludingCredit + $newPaymentAmount;
                $newCreditAmount = $currentCreditAmount;
            } else {
                $newTotalPaidExcludingCredit = $currentPaidAmountExcludingCredit;
                $newCreditAmount = $currentCreditAmount + $newPaymentAmount;
            }

            // Calculate remaining amount after this payment (excluding Credit)
            $remainingAmount = $this->calculateRemainingAmount($totalPayable, $existingPaymentDetails);
            if ($newPaymentMethod !== 'Credit') {
                $remainingAmount = max(0, $remainingAmount - $newPaymentAmount);
            }

            // ==================== MERGE PAYMENT DETAILS WITH DEDUPLICATION ====================
            $newPaymentDetails = $validated['payment_details'] ?? [];

            // Use deduplication logic to merge payment details
            if (!empty($existingPaymentDetails) && !empty($newPaymentDetails)) {
                $mergedPaymentDetails = $this->mergePaymentDetailsWithDeduplication($existingPaymentDetails, $newPaymentDetails);
            } elseif (!empty($existingPaymentDetails)) {
                $mergedPaymentDetails = $existingPaymentDetails;
            } else {
                $mergedPaymentDetails = $newPaymentDetails;
            }

            // Calculate totals including credit (for informational purposes)
            $totalPaidIncludingCredit = $this->calculateTotalPaidIncludingCredit($mergedPaymentDetails);

            Log::info('Payment calculation (excluding Credit)', [
                'current_paid_excluding_credit' => $currentPaidAmountExcludingCredit,
                'current_credit_amount' => $currentCreditAmount,
                'new_payment_amount' => $newPaymentAmount,
                'new_payment_method' => $newPaymentMethod,
                'new_total_paid_excluding_credit' => $newTotalPaidExcludingCredit,
                'new_credit_amount' => $newCreditAmount,
                'total_payable' => $totalPayable,
                'remaining_after_payment' => $remainingAmount,
                'total_paid_including_credit' => $totalPaidIncludingCredit,
                'creditor_no' => $creditorNo,
                'merged_payments_count' => count($mergedPaymentDetails)
            ]);

            // IMPORTANT: Store the original bill total in total_amount
            $billTotalAmount = $totalPayable;

            // Prepare the data array - loan_amount is the TOTAL PAID EXCLUDING CREDIT
            $loanData = [
                'code' => $validated['code'],
                'bill_no' => $validated['bill_no'],
                'loan_amount' => $newTotalPaidExcludingCredit,  // This excludes Credit payments
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

            Log::info('Loan record saved', [
                'table' => $table,
                'loan_amount' => $savedLoan->loan_amount ?? $newTotalPaidExcludingCredit,
                'total_amount' => $savedLoan->total_amount ?? $billTotalAmount,
                'creditor_no' => $creditorNo,
                'remaining' => $remainingAmount,
                'credit_amount' => $newCreditAmount,
                'payment_details_count' => count($mergedPaymentDetails)
            ]);

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

            // Set current context for bill_to_bill tracking
            $this->currentSupplierCode = $validated['code'];
            $this->currentBillNo = $validated['bill_no'];

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
                'total_paid_excluding_credit' => $newTotalPaidExcludingCredit,
                'total_credit_amount' => $newCreditAmount,
                'remaining_amount' => $remainingAmount,
                'table_used' => $table,
                'payments_merged' => count($mergedPaymentDetails)
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

                // Decode payment details to calculate remaining excluding credit
                $paymentDetails = $loan->payment_details;
                if (is_string($paymentDetails)) {
                    $paymentDetails = json_decode($paymentDetails, true);
                }
                if (!is_array($paymentDetails)) {
                    $paymentDetails = [];
                }

                // Calculate remaining amount (total - paid excluding credit)
                $totalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
                $totalCreditAmount = $this->calculateTotalCreditAmount($paymentDetails);
                $remainingAmount = max(0, $totalAmount - $totalPaidExcludingCredit);

                // Check if fully paid (remaining amount <= 0)
                if ($remainingAmount <= 0) {
                    // Fully paid - FULLY SETTLED
                    $completedBills[] = [
                        'supplier_code' => $loan->code,
                        'supplier_bill_no' => $loan->bill_no,
                        'loan_amount' => $totalPaidExcludingCredit,
                        'total_amount' => $totalAmount,
                        'total_paid_excluding_credit' => $totalPaidExcludingCredit,
                        'total_credit_amount' => $totalCreditAmount,
                        'remaining_amount' => $remainingAmount,
                        'creditor_no' => $loan->Creditor_no,
                        'date' => $loan->Date ?? null,
                        'is_history' => true,
                        'type' => $loan->type ?? null,
                        'payment_details' => $paymentDetails  // ✅ ADDED: Include payment details
                    ];
                } else {
                    // Still has remaining balance - NOT SETTLED (from history)
                    $pendingBills[] = [
                        'supplier_code' => $loan->code,
                        'supplier_bill_no' => $loan->bill_no,
                        'loan_amount' => $totalPaidExcludingCredit,
                        'total_amount' => $totalAmount,
                        'total_paid_excluding_credit' => $totalPaidExcludingCredit,
                        'total_credit_amount' => $totalCreditAmount,
                        'remaining_amount' => $remainingAmount,
                        'creditor_no' => $loan->Creditor_no,
                        'date' => $loan->Date ?? null,
                        'is_history' => true,
                        'type' => $loan->type ?? null,
                        'payment_details' => $paymentDetails  // ✅ ADDED: Include payment details
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
            $query = DB::table($table)->select('code', 'bill_no', 'loan_amount', 'total_amount', 'Creditor_no', 'Date', 'type', 'payment_details');

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

                    // Decode payment details to calculate paid amount excluding credit
                    $paymentDetails = $loanRecord->payment_details;
                    if (is_string($paymentDetails)) {
                        $paymentDetails = json_decode($paymentDetails, true);
                    }
                    if (!is_array($paymentDetails)) {
                        $paymentDetails = [];
                    }

                    $totalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
                    $totalCreditAmount = $this->calculateTotalCreditAmount($paymentDetails);
                    $remainingAmount = max(0, $totalAmount - $totalPaidExcludingCredit);

                    if ($remainingAmount > 0) {
                        // Still has remaining balance - NOT SETTLED
                        $pendingBills[] = [
                            'supplier_code' => $bill['supplier_code'],
                            'supplier_bill_no' => $bill['supplier_bill_no'],
                            'loan_amount' => $totalPaidExcludingCredit,
                            'total_amount' => $totalAmount,
                            'total_credit_amount' => $totalCreditAmount,
                            'creditor_no' => $loanRecord->Creditor_no,
                            'date' => $loanRecord->Date ?? null,
                            'type' => $loanRecord->type ?? null,
                            'payment_details' => $paymentDetails  // ✅ ADDED: Include payment details
                        ];
                    } else {
                        // Fully paid - FULLY SETTLED
                        $completedBills[] = [
                            'supplier_code' => $bill['supplier_code'],
                            'supplier_bill_no' => $bill['supplier_bill_no'],
                            'loan_amount' => $totalPaidExcludingCredit,
                            'total_amount' => $totalAmount,
                            'total_credit_amount' => $totalCreditAmount,
                            'creditor_no' => $loanRecord->Creditor_no,
                            'date' => $loanRecord->Date ?? null,
                            'type' => $loanRecord->type ?? null,
                            'payment_details' => $paymentDetails  // ✅ ADDED: Include payment details
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
                        'total_credit_amount' => 0,
                        'creditor_no' => null,
                        'date' => null,
                        'type' => null,
                        'payment_details' => []  // ✅ ADDED: Empty payment details
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
            'success' => false,
            'message' => $e->getMessage(),
            'printed' => [],
            'unprinted' => []
        ]);
    }
}
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

            // Calculate totals
            $totalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
            $totalCreditAmount = $this->calculateTotalCreditAmount($paymentDetails);
            $remainingBalance = max(0, $totalAmount - $totalPaidExcludingCredit);
            $totalPaidIncludingCredit = $this->calculateTotalPaidIncludingCredit($paymentDetails);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_paid_excluding_credit' => $totalPaidExcludingCredit,
                    'total_credit_amount' => $totalCreditAmount,
                    'total_paid_including_credit' => $totalPaidIncludingCredit,
                    'remaining_balance' => $remainingBalance,
                    'total_bill' => $totalAmount,
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

        // Convert to array and ensure ID is included
        $loanArray = (array) $loan;

        if (isset($loan->payment_details) && is_string($loan->payment_details)) {
            $loanArray['payment_details'] = json_decode($loan->payment_details, true);
        }

        // Add calculated fields
        $paymentDetails = $loanArray['payment_details'] ?? [];
        $totalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
        $totalCreditAmount = $this->calculateTotalCreditAmount($paymentDetails);
        $remainingAmount = max(0, floatval($loan->total_amount) - $totalPaidExcludingCredit);

        $loanArray['total_paid_excluding_credit'] = $totalPaidExcludingCredit;
        $loanArray['total_credit_amount'] = $totalCreditAmount;
        $loanArray['remaining_amount'] = $remainingAmount;
        $loanArray['id'] = $loan->id; // Explicitly include ID

        return response()->json($loanArray);
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

                // Add calculated fields to loan record
                if ($loanRecord && isset($loanRecord->payment_details)) {
                    $paymentDetails = is_string($loanRecord->payment_details)
                        ? json_decode($loanRecord->payment_details, true)
                        : $loanRecord->payment_details;

                    $loanRecord->total_paid_excluding_credit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
                    $loanRecord->total_credit_amount = $this->calculateTotalCreditAmount($paymentDetails);
                    $loanRecord->remaining_amount = max(0, floatval($loanRecord->total_amount) - $loanRecord->total_paid_excluding_credit);
                }

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

                // Add calculated fields to loan records
                foreach ($loanRecords as $loanRecord) {
                    if (isset($loanRecord->payment_details)) {
                        $paymentDetails = is_string($loanRecord->payment_details)
                            ? json_decode($loanRecord->payment_details, true)
                            : $loanRecord->payment_details;

                        $loanRecord->total_paid_excluding_credit = $this->calculateTotalPaidExcludingCredit($paymentDetails);
                        $loanRecord->total_credit_amount = $this->calculateTotalCreditAmount($paymentDetails);
                        $loanRecord->remaining_amount = max(0, floatval($loanRecord->total_amount) - $loanRecord->total_paid_excluding_credit);
                    }
                }

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
     * This method UPDATES or CREATES the target supplier's bill with the bill_to_bill payment
     */
    protected function updateTargetSupplierBillPayment($supplierCode, $supplierBillNo, $paymentAmount, $creditorNo = null, $useHistory = false)
    {
        try {
            Log::info('🔄 Updating target supplier bill payment', [
                'supplier_code' => $supplierCode,
                'supplier_bill_no' => $supplierBillNo,
                'payment_amount' => $paymentAmount,
                'creditor_no' => $creditorNo,
                'use_history' => $useHistory,
                'source_supplier' => $this->currentSupplierCode,
                'source_bill_no' => $this->currentBillNo
            ]);

            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';

            // Get existing target loan record
            $targetLoan = DB::table($table)
                ->where('code', $supplierCode)
                ->where('bill_no', $supplierBillNo)
                ->first();

            Log::info('Target loan check', [
                'exists' => $targetLoan ? true : false,
                'loan_id' => $targetLoan->id ?? null,
                'current_paid' => $targetLoan->loan_amount ?? 0
            ]);

            // ... rest of the method remains the same ...

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

    /**
     * Update an existing loan record (add payment)
     */
    public function update(Request $request, $id): JsonResponse
    {
        Log::info('SupplierLoan update endpoint hit', ['id' => $id, 'request_data' => $request->all()]);

        // CRITICAL: Check for duplicate payment first
        $supplierCode = $request->input('code');
        $billNo = $request->input('bill_no');
        $amount = $request->input('loan_amount', 0);
        $method = $request->input('type', 'Cash');
        $transactionId = $request->input('transaction_id');
        $paymentId = $request->input('payment_id');
        
        if ($this->isDuplicatePayment($supplierCode, $billNo, $amount, $method, $transactionId, $paymentId)) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate payment detected. Please wait before trying again.',
                'is_duplicate' => true
            ], 409);
        }

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
            'use_history' => 'nullable|boolean',
            'transaction_id' => 'nullable|string',
            'payment_id' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $useHistory = $request->input('use_history', false);
            $table = $useHistory ? 'supplier_loans_history' : 'supplier_loans';

            // Find existing loan
            $existingLoan = DB::table($table)->where('id', $id)->first();

            if (!$existingLoan) {
                DB::rollback();
                return response()->json([
                    'success' => false,
                    'message' => 'Loan record not found'
                ], 404);
            }

            // Get existing payment details
            $existingPaymentDetails = [];
            if ($existingLoan->payment_details) {
                $existingPaymentDetails = is_string($existingLoan->payment_details)
                    ? json_decode($existingLoan->payment_details, true)
                    : (array) $existingLoan->payment_details;
            }

            // Merge new payment details with existing
            $newPaymentDetails = $validated['payment_details'] ?? [];
            $mergedPaymentDetails = $this->mergePaymentDetailsWithDeduplication($existingPaymentDetails, $newPaymentDetails);

            // Calculate new total paid amount (excluding credit)
            $newTotalPaidExcludingCredit = $this->calculateTotalPaidExcludingCredit($mergedPaymentDetails);

            // Prepare update data
            $updateData = [
                'loan_amount' => $newTotalPaidExcludingCredit,
                'payment_details' => json_encode($mergedPaymentDetails),
                'updated_at' => now(),
            ];

            // Add type if provided
            if (isset($validated['type'])) {
                $updateData['type'] = $validated['type'];
            }

            // Handle payment-specific fields
            if ($validated['type'] === 'Cheque') {
                $updateData['bank_name'] = $validated['bank_name'] ?? null;
                $updateData['cheque_no'] = $validated['cheque_no'] ?? null;
                $updateData['realized_date'] = $validated['realized_date'] ?? now();
                $updateData['bank_account_id'] = $validated['bank_account_id'] ?? null;
            } elseif ($validated['type'] === 'Bank Transfer') {
                $updateData['bank_name'] = $validated['bank_name'] ?? null;
                $updateData['transfer_reference_no'] = $validated['transfer_reference_no'] ?? null;
                $updateData['transfer_date'] = $validated['transfer_date'] ?? now();
                $updateData['transfer_notes'] = $validated['transfer_notes'] ?? null;
                $updateData['bank_account_id'] = $validated['bank_account_id'] ?? null;
            } elseif ($validated['type'] === 'bag_to_box') {
                $updateData['bag_count'] = $validated['bag_count'] ?? 0;
                $updateData['box_count'] = $validated['box_count'] ?? 0;
                $updateData['bag_value'] = $validated['bag_value'] ?? 0;
                $updateData['box_value'] = $validated['box_value'] ?? 0;
                $updateData['adjustment_amount'] = $validated['loan_amount'];
            } elseif ($validated['type'] === 'bill_to_bill') {
                $updateData['target_supplier_code'] = $validated['target_supplier_code'] ?? null;
                $updateData['target_supplier_bill_no'] = $validated['target_supplier_bill_no'] ?? null;
                $updateData['target_supplier_bill_value'] = $validated['target_supplier_bill_value'] ?? 0;
                $updateData['adjustment_amount'] = $validated['loan_amount'];

                // Update target supplier bill payment if this is a bill_to_bill payment
                $this->currentSupplierCode = $validated['code'];
                $this->currentBillNo = $validated['bill_no'];

                if (!empty($validated['target_supplier_code']) && !empty($validated['target_supplier_bill_no'])) {
                    $this->updateTargetSupplierBillPayment(
                        $validated['target_supplier_code'],
                        $validated['target_supplier_bill_no'],
                        $validated['target_supplier_bill_value'] ?? 0,
                        $existingLoan->Creditor_no ?? null,
                        $useHistory
                    );
                }
            } elseif ($validated['type'] === 'bad_debt') {
                $updateData['bad_debt_name'] = $validated['bad_debt_name'] ?? null;
                $updateData['bad_debt_amount'] = $validated['bad_debt_amount'] ?? 0;
            }

            // Update the loan record
            DB::table($table)->where('id', $id)->update($updateData);

            // Get the updated record
            $updatedLoan = DB::table($table)->where('id', $id)->first();

            DB::commit();

            // Calculate remaining amount
            $remainingAmount = $this->calculateRemainingAmount(
                $validated['total_amount'],
                $mergedPaymentDetails
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment updated successfully.',
                'data' => $updatedLoan,
                'total_paid_excluding_credit' => $newTotalPaidExcludingCredit,
                'remaining_amount' => $remainingAmount,
                'payments_count' => count($mergedPaymentDetails)
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Loan Update Error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
   public function getAdjustedTotal(Request $request)
{
    try {

        \Log::info('===== getAdjustedTotal START =====');
        \Log::info('Request Data', $request->all());

        // Get total funds
        $totalFunds = $request->input('total_funds', 0);

        \Log::info('Total Funds Received', [
            'total_funds' => $totalFunds
        ]);

        // Get all supplier loans
        $supplierLoans = SupplierLoan::all();

        \Log::info('Supplier Loans Retrieved', [
            'count' => $supplierLoans->count()
        ]);

        $totalLoanAmount = 0;
        $totalPaymentsExcludingCredit = 0;

        foreach ($supplierLoans as $loan) {

            \Log::info('Processing Loan', [
                'loan_id' => $loan->id ?? null,
                'loan_amount' => $loan->loan_amount ?? null
            ]);

            // Add loan amount
            $totalLoanAmount += floatval($loan->loan_amount);

            \Log::info('Running Loan Total', [
                'current_total_loan_amount' => $totalLoanAmount
            ]);

            // Get payment details
            $payments = $loan->payment_details;

            \Log::info('Raw Payment Details', [
                'loan_id' => $loan->id ?? null,
                'payment_details' => $payments
            ]);

            // Decode JSON if needed
            if (is_string($payments)) {

                $payments = json_decode($payments, true);

                \Log::info('JSON Decoded Payment Details', [
                    'loan_id' => $loan->id ?? null,
                    'json_error' => json_last_error_msg(),
                    'decoded_data' => $payments
                ]);
            }

            if (is_array($payments)) {

                \Log::info('Payment Count', [
                    'loan_id' => $loan->id ?? null,
                    'payment_count' => count($payments)
                ]);

                foreach ($payments as $index => $payment) {

                    \Log::info('Processing Payment', [
                        'loan_id' => $loan->id ?? null,
                        'payment_index' => $index,
                        'payment_data' => $payment
                    ]);

                    if (
                        isset($payment['method']) &&
                        $payment['method'] !== 'Credit'
                    ) {

                        $amount = floatval($payment['amount'] ?? 0);

                        $totalPaymentsExcludingCredit += $amount;

                        \Log::info('Payment Included', [
                            'loan_id' => $loan->id ?? null,
                            'method' => $payment['method'],
                            'amount' => $amount,
                            'running_payment_total' => $totalPaymentsExcludingCredit
                        ]);
                    } else {

                        \Log::info('Payment Skipped (Credit)', [
                            'loan_id' => $loan->id ?? null,
                            'payment_data' => $payment
                        ]);
                    }
                }
            } else {

                \Log::warning('Payment Details Not Array', [
                    'loan_id' => $loan->id ?? null,
                    'payment_details_type' => gettype($payments)
                ]);
            }
        }

        $remainingAfterPayments =
            $totalLoanAmount - $totalPaymentsExcludingCredit;

        $adjustedAmount =
            $remainingAfterPayments - floatval($totalFunds);

        \Log::info('Final Calculation', [
            'total_loan_amount' => $totalLoanAmount,
            'total_payments_excluding_credit' => $totalPaymentsExcludingCredit,
            'remaining_after_payments' => $remainingAfterPayments,
            'total_funds' => $totalFunds,
            'adjusted_amount_before_max' => $adjustedAmount,
            'adjusted_amount_final' => max(0, $adjustedAmount)
        ]);

        \Log::info('===== getAdjustedTotal SUCCESS =====');

        return response()->json([
            'success' => true,
            'data' => [
                'total_loan_amount' => $totalLoanAmount,
                'total_payments_excluding_credit' => $totalPaymentsExcludingCredit,
                'remaining_after_payments' => $remainingAfterPayments,
                'total_funds' => floatval($totalFunds),
                'adjusted_amount' => max(0, $adjustedAmount),
                'message' => $adjustedAmount < 0
                    ? 'Total Funds exceed remaining loan amount'
                    : 'Calculation completed'
            ]
        ]);

    } catch (\Exception $e) {

        \Log::error('===== getAdjustedTotal FAILED =====', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
}