<?php

namespace App\Http\Controllers;

use App\Models\Debtor;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtorController extends Controller
{
    // Create or update debtor record (for credit payments)
    public function createDebtor(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'customer_code' => 'required|string',
            'credit_amount' => 'required|numeric|min:0'
        ]);

        try {
            DB::beginTransaction();

            \Log::info('Creating debtor record', [
                'bill_no' => $request->bill_no,
                'customer_code' => $request->customer_code,
                'credit_amount' => $request->credit_amount
            ]);

            $debtor = Debtor::where('bill_no', $request->bill_no)->first();

            if ($debtor) {
                // Update existing debtor - ADD to existing credit
                $newCreditAmount = $debtor->credit_amount + $request->credit_amount;
                $newRemainingAmount = $debtor->remaining_amount + $request->credit_amount;
                
                $debtor->credit_amount = $newCreditAmount;
                $debtor->remaining_amount = $newRemainingAmount;
                $debtor->status = $newRemainingAmount > 0 ? 'pending' : 'paid';
                $debtor->settled_way = 'credit'; // Mark as credit settlement
                $debtor->save();
                
                $result = $debtor;
            } else {
                // Create new debtor
                $result = Debtor::create([
                    'bill_no' => $request->bill_no,
                    'customer_code' => $request->customer_code,
                    'credit_amount' => $request->credit_amount,
                    'paid_amount' => 0,
                    'remaining_amount' => $request->credit_amount,
                    'status' => 'pending',
                    'settled_way' => 'credit'
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Debtor record created successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating debtor record', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // NEW: Update payment for debtor (when customer pays using cash/cheque/bank_transfer)
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
    
}