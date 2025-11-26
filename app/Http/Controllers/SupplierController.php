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

}