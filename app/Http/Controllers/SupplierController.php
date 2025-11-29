<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Models\SupplierBillNumber;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::all();
        return response()->json($suppliers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code'    => 'required|unique:suppliers',
            'name'    => 'required',
            'address' => 'required',
        ]);

        $data = $request->all();
        $data['code'] = strtoupper($data['code']);

        $supplier = Supplier::create($data);

        return response()->json([
            'message' => 'Supplier added successfully!',
            'supplier' => $supplier
        ], 201);
    }

    public function show(Supplier $supplier)
    {
        return response()->json($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $request->validate([
            'code' => 'required|unique:suppliers,code,' . $supplier->id,
            'name' => 'required',
            'address' => 'required',
        ]);

        $data = $request->all();
        $data['code'] = strtoupper($data['code']);

        $supplier->update($data);

        return response()->json([
            'message' => 'Supplier updated successfully!',
            'supplier' => $supplier
        ]);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return response()->json(['message' => 'Supplier deleted successfully!']);
    }

    public function search($query)
    {
        $suppliers = Supplier::where('code', 'LIKE', $query . '%')
                    ->orWhere('name', 'LIKE', $query . '%')
                    ->orWhere('address', 'LIKE', '%' . $query . '%')
                    ->get();
        
        return response()->json($suppliers);
    }
   public function getSupplierBillStatusSummary()
{
    // *** MODIFIED LOGIC ***
    
    // 1. Get all supplier codes and associated bill numbers where 'supplier_bill_printed' is 'Y'
    // We select both fields, grouping by them to get distinct bills (even if supplier_code is the same).
    $printedBills = Sale::select('supplier_code', 'supplier_bill_no')
        ->where('supplier_bill_printed', 'Y')
        ->groupBy('supplier_code', 'supplier_bill_no') // Ensure unique bills for a given supplier
        ->get(); // Returns a Collection of objects (or arrays, depending on Laravel setup)

    // 2. Get all supplier codes and associated bill numbers where 'supplier_bill_printed' is 'N'
    $unprintedBills = Sale::select('supplier_code', 'supplier_bill_no')
        ->where('supplier_bill_printed', 'N')
        ->groupBy('supplier_code', 'supplier_bill_no')
        ->get(); 
        
    // 3. Return the data as JSON, ensuring the output is an array of plain objects/arrays.
    return response()->json([
        'printed' => $printedBills->toArray(),
        'unprinted' => $unprintedBills->toArray(),
    ]);
}

    /**
     * Get detailed sales records for a specific supplier code.
     */
  public function getSupplierDetails($supplierCode)
{
    $details = Sale::select(
        'supplier_code',
         'id',
        'customer_code',
        'item_name',
        'weight',
        'price_per_kg',
        'commission_amount',
        'total',
        'packs',
        'bill_no',
        'SupplierTotal',
        'SupplierPricePerKg',
        'SupplierPackCost',
        'supplier_bill_printed',
        'supplier_bill_no',
        DB::raw('DATE(created_at) as Date')
    )
    ->where('supplier_code', $supplierCode)
    ->get();

    return response()->json($details);
}

public function generateFSeriesBill(): JsonResponse
    {
        try {
            $newBillNo = DB::transaction(function () {
                
                // --- CORRECTED CODE START ---
                // 1. Get the single counter row (ID 1), applying the lock, and immediately fetching the result.
                $counter = SupplierBillNumber::where('id', 1)->lockForUpdate()->first(); 
                // --- CORRECTED CODE END ---

                if (!$counter) {
                    throw new \Exception("Bill counter configuration missing. Please check the 'supplier_bill_numbers' table.");
                }

                // 2. Increment the number (This is now safe as $counter is a Model instance)
                $nextNumber = $counter->last_number + 1;
                $newBillNo = $counter->prefix . $nextNumber;

                // 3. Update the counter
                $counter->last_number = $nextNumber;
                $counter->save();

                return $newBillNo;
            });

            // 4. Respond with the new number
            return response()->json([
                'new_bill_no' => $newBillNo,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error generating sequential bill number: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate sequential bill number. ' . $e->getMessage()
            ], 500);
        }
    }
    public function getProfitBySupplier()
    {
        try {
            // Eloquent/Query Builder aggregation:
            // SELECT supplier_code, SUM(profit) AS total_profit FROM sales GROUP BY supplier_code
            $profitReport = Sale::select('supplier_code')
                ->selectRaw('SUM(profit) as total_profit')
                ->groupBy('supplier_code')
                ->orderByDesc('total_profit') // Optional: Order by highest profit
                ->get();

            // The resulting collection is automatically formatted as JSON:
            // [{"supplier_code": "SUP001", "total_profit": "1500.50"}, ...]
            return response()->json($profitReport);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error fetching profit by supplier:', ['exception' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to fetch profit report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
   public function marksuppliers(Request $request): JsonResponse
{
    // 1. Validate the incoming request data
    // NOTE: We only validate transaction_ids, as the bill_no will be generated here.
    $validated = $request->validate([
        'transaction_ids' => 'required|array',
        'transaction_ids.*' => 'integer|exists:sales,id',
    ]);

    $ids = $validated['transaction_ids'];
    $finalBillNo = null;

    try {
        // 2. Wrap the entire operation (Bill Generation + Records Update) in a database transaction for atomicity
        DB::beginTransaction();

        // --- BILL NUMBER GENERATION LOGIC (Moved from generateFSeriesBill) ---
        
        // 2a. Get the single counter row (ID 1), applying the lock.
        $counter = SupplierBillNumber::where('id', 1)->lockForUpdate()->first(); 
        
        if (!$counter) {
            DB::rollBack();
            throw new \Exception("Bill counter configuration missing. Please check the 'supplier_bill_numbers' table.");
        }

        // 2b. Increment and generate the new bill number
        $nextNumber = $counter->last_number + 1;
        $finalBillNo = $counter->prefix . $nextNumber;

        // 2c. Update the counter
        $counter->last_number = $nextNumber;
        $counter->save();
        
        // --- END BILL NUMBER GENERATION ---


        // 3. Use the generated $finalBillNo to update the selected records
        $updatedCount = Sale::whereIn('id', $ids)
                            // We check if they were not already processed (optional guard)
                            ->where(function ($query) {
                                $query->whereNull('supplier_bill_no')
                                      ->orWhere('supplier_bill_printed', 'N');
                            })
                            ->update([
                                'supplier_bill_no' => $finalBillNo, // Use the generated number
                                'supplier_bill_printed' => 'Y', 
                            ]);

        DB::commit();

        if ($updatedCount > 0) {
            Log::info("Supplier Bill $finalBillNo successfully generated and updated $updatedCount records.");
        } else {
            // Optional: If no records updated but bill number was generated, you might want to throw an error 
            // or revert the counter, depending on business logic. For now, we proceed.
        }

        // 4. Respond with success, including the generated bill number
        return response()->json([
            'message' => 'Records successfully marked as printed.',
            'updated_count' => $updatedCount,
            'new_bill_no' => $finalBillNo, // Send the new number back to the frontend
        ]);

    } catch (\Exception $e) {
        DB::rollBack(); // Revert changes if an error occurs
        Log::error('Error marking supplier records as printed: ' . $e->getMessage());
        return response()->json([
            'error' => 'Failed to mark records as printed. Check server logs.',
            'details' => $e->getMessage()
        ], 500);
    }
}
    

    /**
     * Get details for a specific bill number
     */
    public function getUnprintedDetails($supplierCode): JsonResponse
    {
        try {
            // Requirement: Records for the given supplier_code where supplier_bill_printed is 'N' or NULL.
            $details = Sale::select(
                'id', 
                'supplier_code',
                'customer_code',
                'item_name',
                'weight',
                'price_per_kg',
                'commission_amount',
                'total', // Assuming this is for customer sale total
                'packs',
                'bill_no', // Original customer bill no
                'SupplierTotal', // Supplier's gross total for the sale
                'SupplierPricePerKg',
                'SupplierPackCost',
                'supplier_bill_printed',
                'supplier_bill_no',
                DB::raw('DATE(created_at) as Date')
            )
            ->where('supplier_code', $supplierCode)
            ->where(function ($query) {
                $query->where('supplier_bill_printed', 'N')
                      ->orWhereNull('supplier_bill_printed');
            })
            ->whereNotNull('supplier_code') // Ensure a supplier code is present
            ->get();

            return response()->json($details);

        } catch (\Exception $e) {
            Log::error("Error fetching unprinted details for supplier {$supplierCode}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch unprinted details',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get details for a specific supplier bill number.
     * This is used when clicking a bill in the 'Printed Bills' section.
     */
    public function getBillDetails($billNo): JsonResponse
    {
        try {
            // Requirement: Records with the exact supplier_bill_no and marked 'Y'.
            $details = Sale::select(
                'id',
                'supplier_code',
                'customer_code',
                'item_name',
                'weight',
                'price_per_kg',
                'commission_amount',
                'total', // Assuming this is for customer sale total
                'packs',
                'bill_no', // Original customer bill no
                'SupplierTotal', // Supplier's gross total for the sale
                'SupplierPricePerKg',
                'SupplierPackCost',
                'supplier_bill_printed',
                'supplier_bill_no',
                DB::raw('DATE(created_at) as Date')
            )
            ->where('supplier_bill_no', $billNo)
            ->where('supplier_bill_printed', 'Y')
            ->get();

            return response()->json($details);

        } catch (\Exception $e) {
            Log::error("Error fetching details for bill {$billNo}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch bill details',
                'details' => $e->getMessage()
            ], 500);
        }
    }

}