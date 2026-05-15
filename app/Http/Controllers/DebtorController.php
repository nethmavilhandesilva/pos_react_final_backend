<?php

namespace App\Http\Controllers;

use App\Models\Debtor;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Helpers\DebtorNumberHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtorController extends Controller
{
    /**
     * Helper to get the appropriate sale model based on where the bill exists
     */
    private function getSaleModelForBill($billNo)
    {
        // First check in SalesHistory (archived bills)
        $archivedSale = SalesHistory::where('bill_no', $billNo)
            ->where('bill_printed', 'Y')
            ->first();

        if ($archivedSale) {
            return [
                'model' => SalesHistory::class,
                'sale' => $archivedSale,
                'is_archived' => true
            ];
        }

        // Then check in Sale (current bills)
        $currentSale = Sale::where('bill_no', $billNo)
            ->where('bill_printed', 'Y')
            ->first();

        if ($currentSale) {
            return [
                'model' => Sale::class,
                'sale' => $currentSale,
                'is_archived' => false
            ];
        }

        return null;
    }

    /**
     * Update the sale record with new payment amount
     */
    private function updateSalePayment($billNo, $updateData)
    {
        // Try to update in SalesHistory first
        $updatedInHistory = SalesHistory::where('bill_no', $billNo)
            ->where('bill_printed', 'Y')
            ->update($updateData);

        if ($updatedInHistory > 0) {
            return ['success' => true, 'table' => 'sales_history', 'updated' => $updatedInHistory];
        }

        // Then try to update in Sale
        $updatedInSale = Sale::where('bill_no', $billNo)
            ->where('bill_printed', 'Y')
            ->update($updateData);

        if ($updatedInSale > 0) {
            return ['success' => true, 'table' => 'sales', 'updated' => $updatedInSale];
        }

        return ['success' => false, 'table' => null, 'updated' => 0];
    }

    /**
     * Create debtor record with customer registration
     */
    public function createDebtorWithCustomer(Request $request)
    {
        $request->validate([
            'bill_no' => 'nullable|string',
            'customer_code' => 'required|string',
            'credit_amount' => 'numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            \Log::info('Frontend request received for debtor creation', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'credit_amount' => $request->credit_amount,
                'full_request' => $request->all()
            ]);

            // Check if debtor record already exists for this bill and customer
            $debtor = Debtor::where('bill_no', $request->bill_no)
                ->where('customer_code', $request->customer_code)
                ->first();

            if (!$debtor) {
                $debtor = Debtor::create([
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                    'credit_amount' => $request->credit_amount ?? 0,
                    'paid_amount' => 0,
                    'remaining_amount' => $request->credit_amount ?? 0,
                    'status' => 'pending',
                    'settled_way' => 'registration',
                ]);

                // Also update the sale record (either current or archived) with debtor_no
                $saleData = $this->getSaleModelForBill($request->bill_no);
                if ($saleData) {
                    $updateData = ['Debtor_no' => $debtor->Debtor_no];
                    $this->updateSalePayment($request->bill_no, $updateData);
                }

                \Log::info('Debtor record created with customer registration', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                    'debtor_no' => $debtor->Debtor_no
                ]);
            } else {
                \Log::warning('Debtor already exists', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Debtor record created successfully',
                'data' => $debtor
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating debtor with customer', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update debt record
     */
    public function createDebt(Request $request)
    {
        $request->validate([
            'bill_no' => 'nullable|string',
            'customer_code' => 'required|string',
            'credit_amount' => 'numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            \Log::info('Frontend request received for debtor creation', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'credit_amount' => $request->credit_amount,
                'full_request' => $request->all()
            ]);

            // Check record only by bill_no
            $debtor = Debtor::where('bill_no', $request->bill_no)->first();

            if ($debtor) {
                // Update existing record
                $debtor->update([
                    'customer_code' => $request->customer_code,
                    'credit_amount' => $request->credit_amount ?? 0,
                    'remaining_amount' => $request->credit_amount ?? 0,
                ]);

                \Log::info('Existing debtor updated', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                ]);
            } else {
                // Create new record
                $debtor = Debtor::create([
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                    'credit_amount' => $request->credit_amount ?? 0,
                    'paid_amount' => 0,
                    'remaining_amount' => $request->credit_amount ?? 0,
                    'status' => 'pending',
                    'settled_way' => 'registration',
                ]);

                // Also update the sale record (either current or archived) with debtor_no
                $saleData = $this->getSaleModelForBill($request->bill_no);
                if ($saleData) {
                    $updateData = ['Debtor_no' => $debtor->Debtor_no];
                    $this->updateSalePayment($request->bill_no, $updateData);
                }

                \Log::info('New debtor record created', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $debtor->wasRecentlyCreated
                    ? 'Debtor record created successfully'
                    : 'Debtor record updated successfully',
                'data' => $debtor
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating/updating debtor', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateDebtorPayment(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string'  // Accept any string, no restrictions
        ]);

        try {
            DB::beginTransaction();

            $debtor = Debtor::where('bill_no', $request->bill_no)->first();

            if (!$debtor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Debtor record not found for this bill'
                ], 404);
            }

            // Calculate new amounts
            $newPaidAmount = $debtor->paid_amount + $request->payment_amount;
            $newRemainingAmount = $debtor->credit_amount - $newPaidAmount;

            // Update debtor record
            $debtor->paid_amount = $newPaidAmount;
            $debtor->remaining_amount = max(0, $newRemainingAmount);

            // Store the payment method (any string is accepted)
            if ($request->has('payment_method') && !empty($request->payment_method)) {
                $debtor->settled_way = $request->payment_method;
            }

            // Update status based on remaining amount
            if ($debtor->remaining_amount <= 0) {
                $debtor->status = 'paid';
                $debtor->remaining_amount = 0;
            } elseif ($debtor->paid_amount > 0 && $debtor->paid_amount < $debtor->credit_amount) {
                $debtor->status = 'partial';
            } else {
                $debtor->status = 'pending';
            }

            $debtor->save();

            \Log::info('Debtor payment updated', [
                'bill_no' => $request->bill_no,
                'payment_amount' => $request->payment_amount,
                'payment_method' => $request->payment_method ?? 'Not specified',
                'new_paid_amount' => $debtor->paid_amount,
                'new_remaining_amount' => $debtor->remaining_amount,
                'status' => $debtor->status
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Debtor payment updated successfully',
                'data' => [
                    'id' => $debtor->id,
                    'bill_no' => $debtor->bill_no,
                    'customer_code' => $debtor->customer_code,
                    'credit_amount' => $debtor->credit_amount,
                    'paid_amount' => $debtor->paid_amount,
                    'remaining_amount' => $debtor->remaining_amount,
                    'status' => $debtor->status,
                    'settled_way' => $debtor->settled_way
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Debtor payment update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get debtor by bill number
     */
    public function getDebtor($billNo)
    {
        try {
            $debtor = Debtor::where('bill_no', $billNo)->first();

            // Also get the sale record info to know if it's archived
            $saleData = $this->getSaleModelForBill($billNo);

            return response()->json([
                'success' => true,
                'data' => $debtor,
                'bill_info' => $saleData ? [
                    'exists' => true,
                    'is_archived' => $saleData['is_archived']
                ] : ['exists' => false]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all debtors for a customer
     */
    public function getCustomerDebtors($customerCode)
    {
        try {
            $debtors = Debtor::where('customer_code', $customerCode)
                ->orderBy('created_at', 'desc')
                ->get();

            // For each debtor, check if the bill is archived
            $debtorsWithStatus = $debtors->map(function ($debtor) {
                $saleData = $this->getSaleModelForBill($debtor->bill_no);
                $debtor->is_bill_archived = $saleData ? $saleData['is_archived'] : false;
                return $debtor;
            });

            return response()->json([
                'success' => true,
                'data' => $debtorsWithStatus
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all pending debtors
     */
    public function getPendingDebtors()
    {
        try {
            $debtors = Debtor::where('status', '!=', 'paid')
                ->orderBy('created_at', 'desc')
                ->get();

            // For each debtor, check if the bill is archived
            $debtorsWithStatus = $debtors->map(function ($debtor) {
                $saleData = $this->getSaleModelForBill($debtor->bill_no);
                $debtor->is_bill_archived = $saleData ? $saleData['is_archived'] : false;
                return $debtor;
            });

            // Calculate summary
            $summary = [
                'total_credit' => $debtors->sum('credit_amount'),
                'total_paid' => $debtors->sum('paid_amount'),
                'total_remaining' => $debtors->sum('remaining_amount'),
                'total_count' => $debtors->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $debtorsWithStatus,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get debtor by debtor number
     */
    public function getDebtorByNumber($debtorNo)
    {
        try {
            $debtors = Debtor::where('Debtor_no', $debtorNo)->get();

            // For each debtor, check if the bill is archived
            $debtorsWithStatus = $debtors->map(function ($debtor) {
                $saleData = $this->getSaleModelForBill($debtor->bill_no);
                $debtor->is_bill_archived = $saleData ? $saleData['is_archived'] : false;
                return $debtor;
            });

            return response()->json([
                'success' => true,
                'data' => $debtorsWithStatus
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}