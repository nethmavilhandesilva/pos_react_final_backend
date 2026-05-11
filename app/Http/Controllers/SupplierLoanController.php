<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Sale;
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
            // Bill to Bill fields (simplified - only supplier fields)
            'target_supplier_code' => 'nullable|string',
            'target_supplier_bill_no' => 'nullable|string',
            'target_supplier_bill_value' => 'nullable|numeric',
            // Bad debt fields
            'bad_debt_name' => 'nullable|string',
            'bad_debt_amount' => 'nullable|numeric'
        ]);

        try {
            DB::beginTransaction();

            // IMPORTANT FIX: Calculate total payable from sales records
            // First, get all sales records for this bill
            $salesQuery = Sale::where('supplier_code', $validated['code']);

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

            Log::info('Calculated total payable', ['total_payable' => $totalPayable]);

            $settingDate = $this->getSettingDate();

            // Get existing loan record
            $existingLoan = SupplierLoan::where('code', $validated['code'])
                ->where('bill_no', $validated['bill_no'])
                ->first();

            // Calculate new totals
            $currentPaidAmount = $existingLoan ? ($existingLoan->loan_amount ?? 0) : 0;
            $newPaidAmount = $currentPaidAmount + $validated['loan_amount'];
            $newRemainingAmount = max(0, $totalPayable - $newPaidAmount);

            Log::info('Payment calculation', [
                'current_paid' => $currentPaidAmount,
                'new_payment' => $validated['loan_amount'],
                'new_total_paid' => $newPaidAmount,
                'total_payable' => $totalPayable,
                'new_remaining' => $newRemainingAmount
            ]);

            // Merge payment details
            $existingPaymentDetails = [];
            if ($existingLoan && $existingLoan->payment_details) {
                $existingPaymentDetails = is_array($existingLoan->payment_details)
                    ? $existingLoan->payment_details
                    : (json_decode($existingLoan->payment_details, true) ?: []);
            }

            $newPaymentDetails = $validated['payment_details'] ?? [];
            $mergedPaymentDetails = array_merge($existingPaymentDetails, $newPaymentDetails);

            // Prepare the data array
            $loanData = [
                'code' => $validated['code'],
                'bill_no' => $validated['bill_no'],
                'loan_amount' => $newPaidAmount,
                'total_amount' => $newRemainingAmount,
                'notes' => $validated['notes'] ?? null,
                'Date' => $settingDate,
                'payment_type' => $validated['type'] ?? 'Cash',
                'payment_details' => $mergedPaymentDetails,
            ];

            // Handle payment type and specific fields
            $paymentType = $validated['type'] ?? $request->input('type') ?? 'Cash';
            $loanData['type'] = $paymentType;

            // Check for bad debt
            $badDebtName = $validated['bad_debt_name'] ?? $request->input('bad_debt_name') ?? null;
            $badDebtAmount = $validated['bad_debt_amount'] ?? $request->input('bad_debt_amount') ?? 0;

            // Also check payment_details for bad debt
            if (empty($badDebtName) && !empty($validated['payment_details'])) {
                foreach ($validated['payment_details'] as $payment) {
                    if (isset($payment['method']) && $payment['method'] === 'bad_debt') {
                        $badDebtName = $payment['bad_debt_name'] ?? $payment['name'] ?? null;
                        $badDebtAmount = $payment['bad_debt_amount'] ?? $payment['amount'] ?? 0;
                        break;
                    }
                }
            }

            // Set bad debt fields if this is a bad debt payment
            if ($paymentType === 'bad_debt' || !empty($badDebtName)) {
                $loanData['bad_debt_name'] = $badDebtName;
                $loanData['bad_debt_amount'] = floatval($badDebtAmount);
                $loanData['type'] = 'bad_debt';
                $loanData['total_amount'] = max(0, $totalPayable - $newPaidAmount - floatval($badDebtAmount));
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
                // Updated: Only supplier fields
                $loanData['target_supplier_code'] = $validated['target_supplier_code'] ?? null;
                $loanData['target_supplier_bill_no'] = $validated['target_supplier_bill_no'] ?? null;
                $loanData['target_supplier_bill_value'] = $validated['target_supplier_bill_value'] ?? 0;
                $loanData['adjustment_amount'] = $validated['loan_amount'];
            }

            Log::info('Final loan data before save', $loanData);

            // Update or create record
            $loan = SupplierLoan::updateOrCreate(
                [
                    'code' => $validated['code'],
                    'bill_no' => $validated['bill_no']
                ],
                $loanData
            );

            Log::info('Loan record saved', ['id' => $loan->id, 'remaining' => $loan->total_amount]);

            // Update sales records
            $this->updateSalesRecords($validated);

            // If this is a bill_to_bill payment, update the target supplier bill
            if ($paymentType === 'bill_to_bill') {
                if (!empty($validated['target_supplier_code']) && !empty($validated['target_supplier_bill_no'])) {
                    $this->updateTargetSupplierBillPayment(
                        $validated['target_supplier_code'],
                        $validated['target_supplier_bill_no'],
                        $validated['target_supplier_bill_value'] ?? 0
                    );
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment saved successfully.',
                'data' => $loan
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

    // Add this new method to update target supplier bill payment
    protected function updateTargetSupplierBillPayment($supplierCode, $supplierBillNo, $paymentAmount)
    {
        try {
            Log::info('Updating target supplier bill payment', [
                'supplier_code' => $supplierCode,
                'supplier_bill_no' => $supplierBillNo,
                'payment_amount' => $paymentAmount
            ]);

            // Find the supplier loan record for the target bill
            $targetLoan = SupplierLoan::where('code', $supplierCode)
                ->where('bill_no', $supplierBillNo)
                ->first();

            if ($targetLoan) {
                // Get the total payable amount for this bill from sales records
                $totalPayable = Sale::where('supplier_code', $supplierCode)
                    ->where('supplier_bill_no', $supplierBillNo)
                    ->sum('SupplierTotal');

                // Update the target loan's loan_amount (paid amount)
                $newPaidAmount = ($targetLoan->loan_amount ?? 0) + $paymentAmount;
                $newRemainingAmount = max(0, $totalPayable - $newPaidAmount);

                $targetLoan->loan_amount = $newPaidAmount;
                $targetLoan->total_amount = $newRemainingAmount;
                $targetLoan->save();

                Log::info('Updated target supplier loan record', [
                    'supplier_code' => $supplierCode,
                    'bill_no' => $supplierBillNo,
                    'old_paid' => ($targetLoan->loan_amount ?? 0) - $paymentAmount,
                    'new_paid' => $newPaidAmount,
                    'total_payable' => $totalPayable,
                    'remaining' => $newRemainingAmount
                ]);
            } else {
                // Create a new loan record if it doesn't exist
                $totalPayable = Sale::where('supplier_code', $supplierCode)
                    ->where('supplier_bill_no', $supplierBillNo)
                    ->sum('SupplierTotal');

                $newRemainingAmount = max(0, $totalPayable - $paymentAmount);

                $newLoan = SupplierLoan::create([
                    'code' => $supplierCode,
                    'bill_no' => $supplierBillNo,
                    'loan_amount' => $paymentAmount,
                    'total_amount' => $newRemainingAmount,
                    'Date' => now(),
                    'payment_type' => 'Bill to Bill Transfer',
                    'type' => 'bill_to_bill'
                ]);

                Log::info('Created new supplier loan record for target bill', [
                    'supplier_code' => $supplierCode,
                    'bill_no' => $supplierBillNo,
                    'loan_id' => $newLoan->id,
                    'paid_amount' => $paymentAmount,
                    'remaining' => $newRemainingAmount
                ]);
            }

            // Update the sales records for this supplier bill
            $updatedCount = Sale::where('supplier_code', $supplierCode)
                ->where('supplier_bill_no', $supplierBillNo)
                ->update([
                    'supplier_paid_amount' => DB::raw('COALESCE(supplier_paid_amount, 0) + ' . floatval($paymentAmount)),
                    'supplier_paid_status' => 'Y',
                    'updated_at' => now()
                ]);

            Log::info('Updated target supplier sales records', [
                'supplier_code' => $supplierCode,
                'bill_no' => $supplierBillNo,
                'payment_amount' => $paymentAmount,
                'records_updated' => $updatedCount
            ]);

            // Also update the main bill's payment details to include this transfer
            // This helps track that this payment was a bill-to-bill transfer
            if (!empty($supplierBillNo)) {
                // You can add additional logic here if needed
            }

        } catch (\Exception $e) {
            Log::error('Error updating target supplier bill payment: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
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
            Log::info('Date found from current_date setting', ['value' => $settingDate]);
        }

        if (!$settingDate) {
            $setting = Setting::where('key', 'Date_of_balance')->first();
            if ($setting && $setting->value) {
                $settingDate = $setting->value;
                Log::info('Date found from Date_of_balance setting', ['value' => $settingDate]);
            }
        }

        if (!$settingDate) {
            $setting = Setting::first();
            if ($setting && $setting->value) {
                $settingDate = $setting->value;
                Log::info('Date found from first setting record', ['value' => $settingDate]);
            }
        }

        if (!$settingDate) {
            $settingDate = now()->format('Y-m-d');
            Log::info('Using current date as fallback', ['value' => $settingDate]);
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
     * Update target customer bill
     */
    private function updateTargetCustomerBill($customerCode, $billNo, $amount)
    {
        Sale::where('customer_code', $customerCode)
            ->where('bill_no', $billNo)
            ->where('bill_printed', 'Y')
            ->update([
                'given_amount' => DB::raw('COALESCE(given_amount, 0) + ' . $amount),
                'given_amount_applied' => DB::raw('CASE WHEN COALESCE(given_amount, 0) + ' . $amount . ' >= total + (packs * CustomerPackCost) THEN "Y" ELSE "N" END'),
                'payment_adjustment_type' => 'bill_to_bill'
            ]);
    }

    /**
     * Update target supplier bill
     */
    private function updateTargetSupplierBill($supplierCode, $billNo, $amount)
    {
        Sale::where('supplier_code', $supplierCode)
            ->where('supplier_bill_no', $billNo)
            ->where('supplier_bill_printed', 'Y')
            ->update([
                'supplier_paid_amount' => DB::raw('COALESCE(supplier_paid_amount, 0) + ' . $amount),
                'supplier_paid_status' => 'Y'
            ]);
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
     * Find loan by code and bill number
     */
    public function findLoan(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $billNo = $request->query('bill_no');

        $loan = SupplierLoan::where('code', $code)
            ->where('bill_no', $billNo)
            ->first();

        if (!$loan) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($loan);
    }

    /**
     * Delete loan record
     */
public function deleteLoanRecord(Request $request): JsonResponse
{
    $validated = $request->validate([
        'code' => 'required|string',
        'bill_no' => 'nullable|string',
    ]);

    DB::beginTransaction();

    try {

        // Find supplier loan record
        $loanRecord = SupplierLoan::where('code', $validated['code'])
            ->where('bill_no', $validated['bill_no'])
            ->first();

        if (!$loanRecord) {

            DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Loan record not found.'
            ], 404);
        }

        // Find matching creditor record
        $creditor = Creditor::where('bill_no', trim($loanRecord->bill_no))
            ->where('supplier_code', trim($validated['code']))
            ->first();

        // Permanently delete creditor record if exists
        if ($creditor) {
            $creditor->forceDelete();
        }

        // Permanently delete supplier loan record
        $loanRecord->forceDelete();

        // Update sales table
        Sale::where('supplier_code', $validated['code'])
            ->where('supplier_bill_no', $validated['bill_no'])
            ->update([
                'loan_taken' => null
            ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Loan and creditor records permanently deleted successfully.'
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
     * Get all supplier codes
     */
    public function getAllCodes(): JsonResponse
    {
        return response()->json(Supplier::select('id', 'code', 'name')->get());
    }

    /**
     * Get farmer full report
     */
    public function getFarmerFullReport(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $supplier = Supplier::where('code', $code)->first();

        if (!$supplier) {
            return response()->json(['success' => false], 404);
        }

        $loans = SupplierLoan::where('code', $code)->orderBy('created_at', 'desc')->get();
        $sales = Sale::where('supplier_code', $code)->orderBy('Date', 'desc')->get();

        return response()->json([
            'success' => true,
            'profile' => $supplier,
            'loans' => $loans,
            'sales' => $sales
        ]);
    }

    /**
     * Get loan summary
     */
    public function getLoanSummary(): JsonResponse
    {
        try {
            $loans = DB::table('supplier_loans')
                ->select('code as supplier_code', 'bill_no as supplier_bill_no', 'loan_amount', 'total_amount', 'type', 'payment_details', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $loans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loan summary'
            ], 500);
        }
    }

    /**
     * Get supplier bill status summary
     */
    public function getSupplierBillStatusSummary2(): JsonResponse
    {
        try {
            $printedBills = DB::table('sales')
                ->leftJoin('supplier_loans', function ($join) {
                    $join->on('sales.supplier_code', '=', 'supplier_loans.code')
                        ->on('sales.supplier_bill_no', '=', 'supplier_loans.bill_no');
                })
                ->select('sales.supplier_code', 'sales.supplier_bill_no')
                ->where('sales.supplier_bill_printed', 'Y')
                ->whereNotNull('sales.supplier_bill_no')
                ->where(function ($q) {
                    $q->whereNull('supplier_loans.id')
                        ->orWhere('supplier_loans.total_amount', '>', 0);
                })
                ->groupBy('sales.supplier_code', 'sales.supplier_bill_no')
                ->get()
                ->map(function ($item) {
                    return [
                        'supplier_code' => $item->supplier_code,
                        'supplier_bill_no' => $item->supplier_bill_no
                    ];
                });

            $paidBills = SupplierLoan::select('code as supplier_code', 'bill_no as supplier_bill_no')
                ->whereNotNull('bill_no')
                ->where('total_amount', '<=', 0)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'supplier_code' => $item->supplier_code,
                        'supplier_bill_no' => $item->supplier_bill_no
                    ];
                });

            return response()->json([
                'printed' => $printedBills,
                'unprinted' => $paidBills
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getSupplierBillStatusSummary2: ' . $e->getMessage());
            return response()->json([
                'printed' => [],
                'unprinted' => []
            ]);
        }
    }

    /**
     * Get payment history
     */
    public function getPaymentHistory(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $billNo = $request->query('bill_no');

        try {
            $loan = SupplierLoan::where('code', $code)
                ->where('bill_no', $billNo)
                ->first();

            if (!$loan) {
                return response()->json([
                    'success' => false,
                    'message' => 'No payment records found'
                ], 404);
            }

            $paymentDetails = $loan->payment_details;
            if (is_string($paymentDetails)) {
                $paymentDetails = json_decode($paymentDetails, true);
            }
            if (!is_array($paymentDetails)) {
                $paymentDetails = [];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_paid' => floatval($loan->loan_amount),
                    'remaining_balance' => floatval($loan->total_amount),
                    'payments' => $paymentDetails,
                    'payment_methods' => $loan->type
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
    public function getPaymentCollectionReport(Request $request): JsonResponse
    {
        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Build the query
            $query = SupplierLoan::query();

            // Apply date filters
            if ($startDate) {
                $query->whereDate('Date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('Date', '<=', $endDate);
            }

            // Get all loans with their supplier info
            $loans = $query->with('supplier')->orderBy('Date', 'desc')->get();

            // Group and calculate totals by bill number
            $reportData = [];
            $totals = [
                'cash_collection' => 0,
                'cheques_collection' => 0,
                'bag_box_total' => 0,
                'bag_total' => 0,
                'box_total' => 0,
                'banks_transfer' => 0,
                'bad_debt' => 0,
                'total_collection' => 0
            ];

            foreach ($loans as $loan) {
                $billNo = $loan->bill_no ?? 'N/A';
                $supplierCode = $loan->code;
                $supplierName = $loan->supplier ? $loan->supplier->name : 'Unknown';
                $displayBillNo = $billNo !== 'N/A' ? "{$supplierCode} - {$billNo}" : $supplierCode;

                if (!isset($reportData[$billNo])) {
                    $reportData[$billNo] = [
                        'customer_bill_no' => $displayBillNo,
                        'supplier_code' => $supplierCode,
                        'supplier_name' => $supplierName,
                        'bill_no' => $billNo,
                        'cash_collection' => 0,
                        'cheques_collection' => 0,
                        'bag_box_total' => 0,
                        'bag_total' => 0,
                        'box_total' => 0,
                        'banks_transfer' => 0,
                        'bad_debt' => 0,
                        'total_paid' => 0,
                        'date' => $loan->Date,
                        'payment_methods' => [],
                        'payment_details' => []
                    ];
                }

                // Calculate based on payment type
                $amount = floatval($loan->loan_amount);

                switch ($loan->type) {
                    case 'Cash':
                        $reportData[$billNo]['cash_collection'] += $amount;
                        $totals['cash_collection'] += $amount;
                        $reportData[$billNo]['payment_methods'][] = 'Cash';
                        break;

                    case 'Cheque':
                        $reportData[$billNo]['cheques_collection'] += $amount;
                        $totals['cheques_collection'] += $amount;
                        $reportData[$billNo]['payment_methods'][] = 'Cheque';
                        $reportData[$billNo]['cheque_details'] = [
                            'bank_name' => $loan->bank_name,
                            'cheque_no' => $loan->cheque_no,
                            'realized_date' => $loan->realized_date
                        ];
                        break;

                    case 'Bank Transfer':
                        $reportData[$billNo]['banks_transfer'] += $amount;
                        $totals['banks_transfer'] += $amount;
                        $reportData[$billNo]['payment_methods'][] = 'Bank Transfer';
                        $reportData[$billNo]['transfer_details'] = [
                            'bank_name' => $loan->bank_name,
                            'reference_no' => $loan->transfer_reference_no,
                            'transfer_date' => $loan->transfer_date
                        ];
                        break;

                    case 'bag_to_box':
                        $bagCount = intval($loan->bag_count);
                        $bagValue = floatval($loan->bag_value);
                        $boxCount = intval($loan->box_count);
                        $boxValue = floatval($loan->box_value);
                        $bagTotal = $bagCount * $bagValue;
                        $boxTotal = $boxCount * $boxValue;

                        $reportData[$billNo]['bag_box_total'] += $amount;
                        $reportData[$billNo]['bag_total'] += $bagCount;
                        $reportData[$billNo]['box_total'] += $boxCount;
                        $totals['bag_box_total'] += $amount;
                        $totals['bag_total'] += $bagCount;
                        $totals['box_total'] += $boxCount;
                        $reportData[$billNo]['payment_methods'][] = 'Bag to Box';
                        break;

                    case 'bill_to_bill':
                        $reportData[$billNo]['cash_collection'] += $amount; // Treat as credit adjustment
                        $totals['cash_collection'] += $amount;
                        $reportData[$billNo]['payment_methods'][] = 'Bill to Bill';
                        $reportData[$billNo]['bill_to_bill_details'] = [
                            'target_customer' => $loan->target_customer_code,
                            'target_bill' => $loan->target_bill_no,
                            'target_supplier' => $loan->target_supplier_code
                        ];
                        break;

                    case 'bad_debt':
                        $reportData[$billNo]['bad_debt'] += $amount;
                        $totals['bad_debt'] += $amount;
                        $reportData[$billNo]['payment_methods'][] = 'Bad Debt';
                        $reportData[$billNo]['bad_debt_details'] = [
                            'name' => $loan->bad_debt_name,
                            'amount' => $loan->bad_debt_amount
                        ];
                        break;
                }

                $reportData[$billNo]['total_paid'] += $amount;
                $reportData[$billNo]['date'] = $loan->Date;

                // Add payment details
                if ($loan->payment_details) {
                    $reportData[$billNo]['payment_details'] = array_merge(
                        $reportData[$billNo]['payment_details'],
                        is_array($loan->payment_details) ? $loan->payment_details : json_decode($loan->payment_details, true) ?? []
                    );
                }
            }

            $totals['total_collection'] = array_sum([
                $totals['cash_collection'],
                $totals['cheques_collection'],
                $totals['bag_box_total'],
                $totals['banks_transfer'],
                $totals['bad_debt']
            ]);

            // Convert to array and sort by date
            $reportArray = array_values($reportData);
            usort($reportArray, function ($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });

            return response()->json([
                'success' => true,
                'data' => $reportArray,
                'totals' => $totals,
                'filters' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Payment Collection Report Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment collection report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment details by bill number
     */
    public function getPaymentDetailsByBill(Request $request): JsonResponse
    {
        $billNo = $request->query('bill_no');
        $code = $request->query('code');

        try {
            $query = SupplierLoan::where('bill_no', $billNo);
            if ($code) {
                $query->where('code', $code);
            }

            $payments = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment details'
            ], 500);
        }
    }
    /**
     * Get supplier bill details for a specific bill
     */
    public function getSupplierBillDetails($billNo, Request $request): JsonResponse
    {
        try {
            $supplierCode = $request->query('supplier_code');

            $sales = Sale::where('supplier_bill_no', $billNo)
                ->where('supplier_code', $supplierCode)
                ->where('supplier_bill_printed', 'Y')
                ->get();

            if ($sales->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sales found for this bill'
                ], 404);
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
     * Get unprinted supplier details (pending loans)
     */
    public function getUnprintedDetails($supplierCode, Request $request): JsonResponse
    {
        try {
            $sales = Sale::where('supplier_code', $supplierCode)
                ->where(function ($q) {
                    $q->where('supplier_bill_printed', 'N')
                        ->orWhereNull('supplier_bill_printed');
                })
                ->where('bill_printed', 'Y')
                ->get();

            if ($sales->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No unprinted sales found for this supplier'
                ], 404);
            }

            return response()->json($sales);

        } catch (\Exception $e) {
            Log::error('Error fetching unprinted details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unprinted details'
            ], 500);
        }
    }
    /**
     * Get supplier bill status summary (for left and right panels)
     */

    public function getSupplierLoansSummary(): JsonResponse
    {
        try {
            // Get all printed bills
            $allPrintedBills = DB::table('sales')
                ->select('supplier_code', 'supplier_bill_no')
                ->whereNotNull('supplier_code')
                ->where('supplier_bill_printed', 'Y')
                ->whereNotNull('supplier_bill_no')
                ->groupBy('supplier_code', 'supplier_bill_no')
                ->get()
                ->map(function ($item) {
                    return [
                        'supplier_code' => $item->supplier_code,
                        'supplier_bill_no' => $item->supplier_bill_no
                    ];
                });

            // Get ALL loans (not just active ones)
            $allLoans = SupplierLoan::select('code', 'bill_no', 'loan_amount', 'total_amount')
                ->get()
                ->keyBy(function ($item) {
                    return $item->code . '-' . $item->bill_no;
                });

            // Separate: Pending = have loans with total_amount > 0 (remaining balance)
            $pendingBills = [];
            $completedBills = [];

            foreach ($allPrintedBills as $bill) {
                $key = $bill['supplier_code'] . '-' . $bill['supplier_bill_no'];
                if (isset($allLoans[$key])) {
                    // Has loan record
                    $remainingAmount = $allLoans[$key]->total_amount;
                    if ($remainingAmount > 0) {
                        // Still has remaining balance - NOT SETTLED
                        $pendingBills[] = [
                            'supplier_code' => $bill['supplier_code'],
                            'supplier_bill_no' => $bill['supplier_bill_no'],
                            'loan_amount' => $allLoans[$key]->loan_amount,
                            'total_amount' => $remainingAmount
                        ];
                    } else {
                        // Fully paid - FULLY SETTLED
                        $completedBills[] = [
                            'supplier_code' => $bill['supplier_code'],
                            'supplier_bill_no' => $bill['supplier_bill_no']
                        ];
                    }
                } else {
                    // No loan record - means never paid, treat as pending with full amount
                    // Get total payable from sales
                    $totalPayable = DB::table('sales')
                        ->where('supplier_code', $bill['supplier_code'])
                        ->where('supplier_bill_no', $bill['supplier_bill_no'])
                        ->where('supplier_bill_printed', 'Y')
                        ->sum('SupplierTotal');

                    $pendingBills[] = [
                        'supplier_code' => $bill['supplier_code'],
                        'supplier_bill_no' => $bill['supplier_bill_no'],
                        'loan_amount' => 0,
                        'total_amount' => floatval($totalPayable)
                    ];
                }
            }

            Log::info('Supplier Loans Summary', [
                'total_printed_bills' => count($allPrintedBills),
                'pending_count' => count($pendingBills),
                'completed_count' => count($completedBills)
            ]);

            // IMPORTANT: 
            // 'printed' = FULLY SETTLED (complete)
            // 'unprinted' = NOT SETTLED (pending/partial)
            return response()->json([
                'printed' => $completedBills,    // Fully settled
                'unprinted' => $pendingBills      // Not settled
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getSupplierLoansSummary: ' . $e->getMessage());
            return response()->json([
                'printed' => [],
                'unprinted' => []
            ]);
        }
    }
}