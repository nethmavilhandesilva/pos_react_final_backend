<?php

namespace App\Http\Controllers;

use App\Models\Debtor;
use App\Models\Customer;
use App\Models\Sale;
use App\Helpers\DebtorNumberHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtorController extends Controller
{
    // Create or update debtor record (for credit payments)
  // Add this method to DebtorController.php
public function createDebtorWithCustomer(Request $request)
{
    $request->validate([
        'bill_no' => 'nullable|string',
        'customer_code' => 'required|string',
        'credit_amount' => 'numeric|min:0',
        'debtor_no' => 'required|string'
    ]);

    try {
        DB::beginTransaction();

        // ✅ Log frontend received data
        \Log::info('Frontend request received for debtor creation', [
            'bill_no' => $request->bill_no,
            'customer_code' => $request->customer_code,
            'credit_amount' => $request->credit_amount,
            'debtor_no' => $request->debtor_no,
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
                'Debtor_no' => $request->debtor_no
            ]);

            \Log::info('Debtor record created with customer registration', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'debtor_no' => $request->debtor_no
            ]);
        } else {

            // ✅ Log if already exists
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
            'bill_no' => $request->bill_no ?? null,
            'customer_code' => $request->customer_code ?? null,
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
public function createDebt(Request $request)
{
    $request->validate([
        'bill_no' => 'nullable|string',
        'customer_code' => 'required|string',
        'credit_amount' => 'numeric|min:0',
        'debtor_no' => 'nullable|string'
    ]);

    try {

        DB::beginTransaction();

        // ✅ Log frontend received data
        \Log::info('Frontend request received for debtor creation', [
            'bill_no' => $request->bill_no,
            'customer_code' => $request->customer_code,
            'credit_amount' => $request->credit_amount,
            'debtor_no' => $request->debtor_no,
            'full_request' => $request->all()
        ]);

        // ✅ Check record only by bill_no
        $debtor = Debtor::where('bill_no', $request->bill_no)->first();

        if ($debtor) {

            // ✅ Update existing record
            $debtor->update([
                'customer_code' => $request->customer_code,
                'credit_amount' => $request->credit_amount ?? 0,
                'remaining_amount' => $request->credit_amount ?? 0,
                'Debtor_no' => $request->debtor_no
            ]);

            \Log::info('Existing debtor updated', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'debtor_no' => $request->debtor_no
            ]);

        } else {

            // ✅ Create new record
            $debtor = Debtor::create([
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'credit_amount' => $request->credit_amount ?? 0,
                'paid_amount' => 0,
                'remaining_amount' => $request->credit_amount ?? 0,
                'status' => 'pending',
                'settled_way' => 'registration',
                'Debtor_no' => $request->debtor_no
            ]);

            \Log::info('New debtor record created', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'debtor_no' => $request->debtor_no
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
            'bill_no' => $request->bill_no ?? null,
            'customer_code' => $request->customer_code ?? null,
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

    // Update payment for debtor (when customer pays using cash/cheque/bank_transfer)
    public function updateDebtorPayment(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:cash,cheque,bank_transfer'
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

            // Update paid amount and remaining amount
            $debtor->paid_amount += $request->payment_amount;
            $debtor->remaining_amount -= $request->payment_amount;
            $debtor->settled_way = $request->payment_method;
            
            // Update status
            if ($debtor->remaining_amount <= 0) {
                $debtor->status = 'paid';
                $debtor->remaining_amount = 0;
            } elseif ($debtor->paid_amount > 0) {
                $debtor->status = 'partial';
            }
            
            $debtor->save();

            \Log::info('Debtor payment updated', [
                'bill_no' => $request->bill_no,
                'payment_amount' => $request->payment_amount,
                'payment_method' => $request->payment_method,
                'remaining_amount' => $debtor->remaining_amount,
                'status' => $debtor->status
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Debtor payment updated successfully',
                'data' => $debtor
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get debtor by bill number
    public function getDebtor($billNo)
    {
        try {
            $debtor = Debtor::where('bill_no', $billNo)->first();
            
            return response()->json([
                'success' => true,
                'data' => $debtor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get all debtors for a customer
    public function getCustomerDebtors($customerCode)
    {
        try {
            $debtors = Debtor::where('customer_code', $customerCode)
                            ->orderBy('created_at', 'desc')
                            ->get();
            
            return response()->json([
                'success' => true,
                'data' => $debtors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get all pending debtors
    public function getPendingDebtors()
    {
        try {
            $debtors = Debtor::where('status', '!=', 'paid')
                            ->orderBy('created_at', 'desc')
                            ->get();
            
            // Calculate summary
            $summary = [
                'total_credit' => $debtors->sum('credit_amount'),
                'total_paid' => $debtors->sum('paid_amount'),
                'total_remaining' => $debtors->sum('remaining_amount'),
                'total_count' => $debtors->count()
            ];
            
            return response()->json([
                'success' => true,
                'data' => $debtors,
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    // Get debtor by debtor number
    public function getDebtorByNumber($debtorNo)
    {
        try {
            $debtors = Debtor::where('Debtor_no', $debtorNo)->get();
            
            return response()->json([
                'success' => true,
                'data' => $debtors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}