<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SupplierLoan;
use DB;
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
        'type' => 'required|string',
        'transaction_ids' => 'nullable|array',
        'notes' => 'nullable|string',
        // 🚀 NEW VALIDATION FOR CHEQUE
        'bank_name' => 'nullable|string',
        'cheque_no' => 'nullable|string',
        'realized_date' => 'nullable|date'
    ]);

    try {
        \DB::beginTransaction();

        /**
         * BACKEND CALCULATION
         * Remaining balance = Total Bill - Loan Amount
         */
        $remainingBalance = $validated['total_amount'] - $validated['loan_amount'];

        /**
         * Create or Update Supplier Loan
         */
        $loan = SupplierLoan::updateOrCreate(
            [
                'code' => $validated['code'],
                'bill_no' => $validated['bill_no']
            ],
            [
                'loan_amount' => $validated['loan_amount'],
                'total_amount' => $remainingBalance,
                'type' => $validated['type'],
                'bank_name' => $validated['bank_name'] ?? null,
                'cheque_no' => $validated['cheque_no'] ?? null,
                'realized_date' => $validated['realized_date'] ?? null,
                'notes' => $validated['notes'] ?? null
            ]
        );

        /**
         * Update all matching sales records
         */
        $salesQuery = Sale::where('supplier_code', $validated['code']);

        if (!empty($validated['bill_no'])) {
            $salesQuery->where('supplier_bill_no', $validated['bill_no']);
        }

        if (!empty($validated['transaction_ids'])) {
            $salesQuery->whereIn('id', $validated['transaction_ids']);
        }

        $salesQuery->update([
            'loan_taken' => 'Y'
        ]);

        \DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Loan saved and matching sales records updated.',
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
public function getSupplierBillStatusSummary2()
{
    \Log::info("Supplier loan summary called");

    $printedBills = Sale::select('supplier_code', 'supplier_bill_no')
    ->where('supplier_bill_printed', 'Y')
    ->whereNotNull('supplier_bill_no')
    ->where(function ($q) {
        $q->where('loan_taken', '!=', 'Y')
          ->orWhereNull('loan_taken');
    })
    ->groupBy('supplier_code', 'supplier_bill_no')
    ->get();

    \Log::info($printedBills);

    return response()->json([
        'printed' => $printedBills,
        'unprinted' => []
    ]);
}
public function getReport(Request $request): JsonResponse
{
    $query = SupplierLoan::query();

    // Filter by Type: Cash or Cheque
    $query->when($request->type, function ($q) use ($request) {
        return $q->where('type', $request->type);
    });

    // Filter by Payment Status
    // 'not_loan' -> total_amount = 0
    // 'loan'     -> total_amount > 0
    $query->when($request->status, function ($q) use ($request) {
        if ($request->status === 'not_loan') {
            return $q->where('total_amount', 0);
        } elseif ($request->status === 'loan') {
            return $q->where('total_amount', '>', 0);
        }
    });

    // Filter by Date Range (Optional but recommended)
    $query->when($request->start_date && $request->end_date, function ($q) use ($request) {
        return $q->whereBetween('created_at', [$request->start_date, $request->end_date]);
    });

    $data = $query->orderBy('created_at', 'desc')->get();

    return response()->json([
        'success' => true,
        'data' => $data,
        'summary' => [
            'total_loan_balance' => $data->sum('total_amount'),
            'total_paid' => $data->sum('loan_amount'),
            'count' => $data->count()
        ]
    ]);
}
public function deleteLoanRecord(Request $request)
    {
        // Validate the incoming request
        $validated = $request->validate([
            'code' => 'required|string',
            'bill_no' => 'nullable|string',
        ]);

        $supplierCode = $validated['code'];
        $billNo = $validated['bill_no'];

        // Use a database transaction to ensure data integrity
        DB::beginTransaction();

        try {
            // 1. Delete the entry from the supplier_loans table
            SupplierLoan::where('code', $supplierCode)
                ->where('bill_no', $billNo)
                ->delete();

            // 2. Update all records in the sales table matching this supplier and bill
            // Set the 'loan_taken' column back to NULL
            Sale::where('supplier_code', $supplierCode)
                ->where('supplier_bill_no', $billNo)
                ->update(['loan_taken' => null]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Loan record deleted and sales records reset successfully.'
            ], 200);

        } catch (\Exception $e) {
            // Rollback changes if something goes wrong
            DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete record: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getAllCodes() {
    return response()->json(\App\Models\Supplier::select('id', 'code', 'name')->get());
}
    public function getFarmerFullReport(Request $request) {
    $code = $request->query('code');
    $supplier = \App\Models\Supplier::where('code', $code)->first();
    if (!$supplier) return response()->json(['success' => false], 404);

    $loans = \App\Models\SupplierLoan::where('code', $code)->orderBy('created_at', 'desc')->get();
    $sales = \App\Models\Sale::where('supplier_code', $code)->orderBy('Date', 'desc')->get();

    return response()->json([
        'success' => true,
        'profile' => $supplier,
        'loans' => $loans,
        'sales' => $sales
    ]);
}

}