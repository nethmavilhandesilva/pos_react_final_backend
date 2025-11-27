<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Models\SupplierBillNumber;

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
        // Get distinct supplier_codes where 'bill_printed' is 'Y'
        $printedSuppliers = Sale::select('supplier_code')
            ->where('bill_printed', 'Y')
            ->distinct()
            ->pluck('supplier_code')
            ->all();

        // Get distinct supplier_codes where 'bill_printed' is 'N'
        $unprintedSuppliers = Sale::select('supplier_code')
            ->where('bill_printed', 'N')
            ->distinct()
            ->pluck('supplier_code')
            ->all();

        return response()->json([
            'printed' => $printedSuppliers,
            'unprinted' => $unprintedSuppliers,
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
        $validated = $request->validate([
            'bill_no' => 'required|string|max:255',
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:sales,id', // !!! ADJUST TABLE NAME 'sales' IF NEEDED !!!
        ]);

        $billNo = $validated['bill_no'];
        $ids = $validated['transaction_ids'];

        try {
            // 2. Wrap the update operation in a database transaction for atomicity
            DB::beginTransaction();

            // Use Sale::whereIn to update all selected records
            $updatedCount = Sale::whereIn('id', $ids)
                                // We check if they were not already processed (optional guard)
                                ->where(function ($query) {
                                    $query->whereNull('supplier_bill_no')
                                          ->orWhere('supplier_bill_printed', 'N');
                                })
                                ->update([
                                    'supplier_bill_no' => $billNo,
                                    'supplier_bill_printed' => 'Y', 
                                ]);

            DB::commit();

            if ($updatedCount > 0) {
                 \Log::info("Supplier Bill $billNo successfully updated $updatedCount records.");
            }

            // 3. Respond with success
            return response()->json([
                'message' => 'Records successfully marked as printed.',
                'updated_count' => $updatedCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack(); // Revert changes if an error occurs
            \Log::error('Error marking supplier records as printed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to mark records as printed. Check server logs.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}