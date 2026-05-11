<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditorController extends Controller
{
    // Create or update creditor record (for credit payments)
    public function createCreditor(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'supplier_code' => 'required|string',
            'credit_amount' => 'required|numeric|min:0'
        ]);

        try {
            DB::beginTransaction();

            \Log::info('Creating creditor record', [
                'bill_no' => $request->bill_no,
                'supplier_code' => $request->supplier_code,
                'credit_amount' => $request->credit_amount
            ]);

            $creditor = Creditor::where('bill_no', $request->bill_no)
                ->where('supplier_code', $request->supplier_code)
                ->first();

            if ($creditor) {
                // Update existing creditor - ADD to existing credit
                $newCreditAmount = $creditor->credit_amount + $request->credit_amount;
                $newRemainingAmount = $creditor->remaining_amount + $request->credit_amount;
                
                $creditor->credit_amount = $newCreditAmount;
                $creditor->remaining_amount = $newRemainingAmount;
                $creditor->status = $newRemainingAmount > 0 ? 'pending' : 'paid';
                $creditor->settled_way = 'credit';
                $creditor->save();
                
                $result = $creditor;
            } else {
                // Create new creditor
                $result = Creditor::create([
                    'bill_no' => $request->bill_no,
                    'supplier_code' => $request->supplier_code,
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
                'message' => 'Creditor record created successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating creditor record', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Update payment for creditor (when supplier gets paid via cash/cheque/bank_transfer)
    public function updateCreditorPayment(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:cash,cheque,bank_transfer,adjustment'
        ]);

        try {
            DB::beginTransaction();

            $creditor = Creditor::where('bill_no', $request->bill_no)->first();
            
            if (!$creditor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Creditor record not found for this bill'
                ], 404);
            }

            // Update paid amount and remaining amount
            $creditor->paid_amount += $request->payment_amount;
            $creditor->remaining_amount -= $request->payment_amount;
            $creditor->settled_way = $request->payment_method;
            
            // Update status
            if ($creditor->remaining_amount <= 0) {
                $creditor->status = 'paid';
                $creditor->remaining_amount = 0;
            } elseif ($creditor->paid_amount > 0) {
                $creditor->status = 'partial';
            }
            
            $creditor->save();

            \Log::info('Creditor payment updated', [
                'bill_no' => $request->bill_no,
                'payment_amount' => $request->payment_amount,
                'payment_method' => $request->payment_method,
                'remaining_amount' => $creditor->remaining_amount,
                'status' => $creditor->status
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Creditor payment updated successfully',
                'data' => $creditor
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get creditor by bill number and supplier code
    public function getCreditor($billNo, $supplierCode = null)
    {
        try {
            $query = Creditor::where('bill_no', $billNo);
            
            if ($supplierCode) {
                $query->where('supplier_code', $supplierCode);
            }
            
            $creditor = $query->first();
            
            return response()->json([
                'success' => true,
                'data' => $creditor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get all creditors for a supplier
    public function getSupplierCreditors($supplierCode)
    {
        try {
            $creditors = Creditor::where('supplier_code', $supplierCode)
                            ->orderBy('created_at', 'desc')
                            ->get();
            
            // Calculate summary
            $summary = [
                'total_credit' => $creditors->sum('credit_amount'),
                'total_paid' => $creditors->sum('paid_amount'),
                'total_remaining' => $creditors->sum('remaining_amount'),
                'total_count' => $creditors->count()
            ];
            
            return response()->json([
                'success' => true,
                'data' => $creditors,
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get all pending creditors
    public function getPendingCreditors()
    {
        try {
            $creditors = Creditor::where('status', '!=', 'paid')
                            ->orderBy('created_at', 'desc')
                            ->get();
            
            $summary = [
                'total_credit' => $creditors->sum('credit_amount'),
                'total_paid' => $creditors->sum('paid_amount'),
                'total_remaining' => $creditors->sum('remaining_amount'),
                'total_count' => $creditors->count()
            ];
            
            return response()->json([
                'success' => true,
                'data' => $creditors,
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