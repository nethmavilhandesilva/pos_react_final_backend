<?php

namespace App\Http\Controllers;

use App\Models\SupplierLoan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class SupplierLoanController extends Controller
{
    /**
     * Store a new supplier loan record
     */
  public function store(Request $request): JsonResponse
{
    Log::info('SupplierLoan store endpoint hit', [
        'request_data' => $request->all()
    ]);

    $validated = $request->validate([
        'code' => 'required|string',
        'loan_amount' => 'required|numeric|min:0',
        'total_amount' => 'required|numeric',
        'bill_no' => 'nullable|string',
        'notes' => 'nullable|string'
    ]);

    try {
        // Check if a record exists with the same code and bill_no
        $existingLoan = SupplierLoan::where('code', $validated['code'])
            ->first();

        if ($existingLoan) {
            // Update the existing record
            $existingLoan->update([
                'loan_amount' => $validated['loan_amount'],
                'total_amount' => $validated['total_amount'],
                'notes' => $validated['notes'] ?? $existingLoan->notes
            ]);
            
            $loan = $existingLoan;
            
            Log::info('SupplierLoan updated successfully', [
                'loan_id' => $loan->id,
                'code' => $loan->code,
                'loan_amount' => $loan->loan_amount,
                'bill_no' => $loan->bill_no
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Loan record updated successfully',
                'data' => $loan
            ], 200);
        } else {
            // Create new loan record
            $loan = SupplierLoan::create($validated);

            Log::info('SupplierLoan created successfully', [
                'loan_id' => $loan->id,
                'code' => $loan->code,
                'loan_amount' => $loan->loan_amount,
                'bill_no' => $loan->bill_no
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Loan record saved successfully',
                'data' => $loan
            ], 201);
        }

    } catch (\Exception $e) {
        Log::error('Failed to create/update supplier loan', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to save loan record',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Get all loans for a specific supplier
     */
    public function getBySupplier($code): JsonResponse
    {
        try {
            $loans = SupplierLoan::where('code', $code)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $loans
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch supplier loans', [
                'code' => $code,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get total loan amount for a supplier
     */
    public function getTotalLoan($code): JsonResponse
    {
        try {
            $totalLoan = SupplierLoan::where('code', $code)->sum('loan_amount');

            return response()->json([
                'success' => true,
                'code' => $code,
                'total_loan' => $totalLoan
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to calculate total loan', [
                'code' => $code,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate total loan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get loans for a specific bill
     */
    public function getByBillNo($billNo): JsonResponse
    {
        try {
            $loans = SupplierLoan::where('bill_no', $billNo)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $loans
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch loans for bill', [
                'bill_no' => $billNo,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a loan record
     */
    public function destroy($id): JsonResponse
    {
        try {
            $loan = SupplierLoan::findOrFail($id);
            $loan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Loan record deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete loan', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete loan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a loan record
     */
    public function update(Request $request, $id): JsonResponse
    {
        Log::info('SupplierLoan update endpoint hit', [
            'id' => $id,
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'loan_amount' => 'sometimes|numeric|min:0',
            'total_amount' => 'sometimes|numeric',
            'bill_no' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        try {
            $loan = SupplierLoan::findOrFail($id);
            $loan->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Loan record updated successfully',
                'data' => $loan
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update loan', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update loan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}