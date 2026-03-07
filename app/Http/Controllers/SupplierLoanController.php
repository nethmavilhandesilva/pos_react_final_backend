<?php

namespace App\Http\Controllers;

use App\Models\Sale;
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
    Log::info('SupplierLoan store endpoint hit', ['request_data' => $request->all()]);

    $validated = $request->validate([
        'code' => 'required|string',
        'loan_amount' => 'required|numeric|min:0',
        'total_amount' => 'required|numeric',
        'bill_no' => 'nullable|string',
        'transaction_ids' => 'nullable|array',
        'notes' => 'nullable|string'
    ]);

    try {
        \DB::beginTransaction();

        // Check if loan already exists
        $loan = SupplierLoan::where('code', $validated['code'])->first();

        if (!$loan) {
            // Create new loan (bill_no saved only here)
            $loan = SupplierLoan::create([
                'code' => $validated['code'],
                'loan_amount' => $validated['loan_amount'],
                'total_amount' => $validated['total_amount'],
                'bill_no' => $validated['bill_no'], // only on create
                'notes' => $validated['notes'] ?? null
            ]);
        } else {
            // Update existing loan WITHOUT touching bill_no
            $loan->update([
                'loan_amount' => $validated['loan_amount'],
                'total_amount' => $validated['total_amount'],
                'notes' => $validated['notes'] ?? null
            ]);
        }

        // Update related sales records WITHOUT touching bill_no
        $salesQuery = Sale::where('supplier_code', $validated['code']);

        if (!empty($validated['transaction_ids'])) {
            $salesQuery->whereIn('id', $validated['transaction_ids']);
        } elseif (!empty($validated['bill_no'])) {
            $salesQuery->where('supplier_bill_no', $validated['bill_no']);
        }

        $salesQuery->update([
            'loan_taken' => 'Y' // only this column updates
            // NEVER update 'bill_no' here
        ]);

        \DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Loan saved and sales records updated.',
            'data' => $loan
        ], 200);

    } catch (\Exception $e) {
        \DB::rollBack();
        Log::error('Loan Store Error: ' . $e->getMessage());

        return response()->json([
            'success' => false,
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
    public function getLoanTakenSummary()
{
    try {
        $loans =Sale::where('loan_taken', 'Y')
            // You can add 'where supplier_bill_printed = No' if you only want unprinted loans
            ->select('supplier_code', 'supplier_bill_no', \DB::raw('count(*) as total_items'))
            ->groupBy('supplier_code', 'supplier_bill_no')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $loans
        ]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}
public function findLoan(Request $request)
{
    $code = $request->query('code');
    $billNo = $request->query('bill_no');

    // Find the loan record matching supplier code and bill_no
    $loan = SupplierLoan::where('code', $code)
        ->where('bill_no', $billNo)
        ->first();

    if (!$loan) {
        return response()->json(['message' => 'Not found'], 404);
    }

    return response()->json($loan);
}
}