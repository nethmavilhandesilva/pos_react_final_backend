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
    $data = $request->validate([
        'code'         => 'required|unique:suppliers',
        'name'         => 'required|string',
        'address'      => 'required|string',
        'profile_pic'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_front'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_back'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    // Handle Profile Picture Upload
    if ($request->hasFile('profile_pic')) {
        // Stored in: storage/app/public/suppliers/profiles
        $data['profile_pic'] = $request->file('profile_pic')->store('suppliers/profiles', 'public');
    }

    // Handle NIC Front Upload
    if ($request->hasFile('nic_front')) {
        // Stored in: storage/app/public/suppliers/nic
        $data['nic_front'] = $request->file('nic_front')->store('suppliers/nic', 'public');
    }

    // Handle NIC Back Upload
    if ($request->hasFile('nic_back')) {
        // Stored in: storage/app/public/suppliers/nic
        $data['nic_back'] = $request->file('nic_back')->store('suppliers/nic', 'public');
    }

    // Ensure code is uppercase
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
    $data = $request->validate([
        'code'         => 'required|unique:suppliers,code,' . $supplier->id,
        'name'         => 'required|string',
        'address'      => 'required|string',
        'profile_pic'  => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_front'    => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_back'     => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
    ]);

    foreach (['profile_pic', 'nic_front', 'nic_back'] as $field) {
        if ($request->hasFile($field)) {
            if ($supplier->$field) {
                \Storage::disk('public')->delete($supplier->$field);
            }
            $data[$field] = $request->file($field)->store('suppliers', 'public');
        }
    }

    $data['code'] = strtoupper($data['code']);
    $supplier->update($data);

    return response()->json(['message' => 'Supplier updated successfully!', 'supplier' => $supplier]);
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
    // 1. Get all distinct PRINTED bills (where a bill number has been successfully assigned and printed)
    $printedBills = Sale::select('supplier_code', 'supplier_bill_no')
        ->where('supplier_bill_printed', 'Y')
        ->whereNotNull('supplier_bill_no') // Essential guard: must have a bill number
        ->groupBy('supplier_code', 'supplier_bill_no')
        ->get();
        
    $unprintedBills = Sale::select('supplier_code')
        ->where(function ($query) {
            $query->where('supplier_bill_printed', 'N')
                  ->orWhereNull('supplier_bill_printed'); // Includes records not yet processed
        })
        ->whereNotNull('supplier_code') // Only include records assigned to a supplier
        ->groupBy('supplier_code') // Group only by code, as there is no bill_no yet
        ->get(); 
        
    // 3. Return the data as JSON
    return response()->json([
        'printed' => $printedBills->toArray(),
        'unprinted' => $unprintedBills->toArray(),
    ]);
}
  public function getSupplierDetails($supplierCode)
{
    Log::info('getSupplierDetails METHOD TRIGGERED', [
        'supplierCode' => $supplierCode,
        'route' => request()->path(),
        'method' => request()->method(),
        'user_id' => auth()->id(),
    ]);

    try {
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
            'CustomerPackLabour',
            'supplier_bill_printed',
            'supplier_bill_no',
            'profile_pic',
            'nic_front',
            'nic_back',
            DB::raw('DATE(created_at) as Date')
        )
        ->where('supplier_code', $supplierCode)
        ->get();

        Log::info('getSupplierDetails QUERY SUCCESS', [
            'records' => $details->count()
        ]);

        return response()->json($details);

    } catch (\Throwable $e) {
        Log::error('getSupplierDetails FAILED', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        throw $e;
    }
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
    public function updateSupplier(Request $request, $id)
{
    $request->validate([
        'supplier_code' => 'required|string',
        'customer_code' => 'nullable|string' // 🚀 Allow optional customer code
    ]);

    $sale = Sale::findOrFail($id);
    
    // Update supplier_code
    $sale->supplier_code = $request->supplier_code;
    
    // 🚀 Only update customer_code if a value was sent
    if ($request->filled('customer_code')) {
        $sale->customer_code = $request->customer_code;
    }
    
    $sale->save();

    return response()->json([
        'message' => 'Record updated successfully',
        'data' => $sale
    ], 200);
}
public function store2(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'advance_amount' => 'required|numeric|min:0',
        ]);

        // Logic: Find by 'code', update or create with the 'advance_amount'
        $supplier = Supplier::updateOrCreate(
            ['code' => $validated['code']],
            ['advance_amount' => $validated['advance_amount']]
        );

        return response()->json([
            'message' => 'Supplier data saved successfully!',
            'data' => $supplier
        ], 200);
    }
    public function getByCode($code) {
    return Supplier::where('code', $code)->firstOrFail();
}

}