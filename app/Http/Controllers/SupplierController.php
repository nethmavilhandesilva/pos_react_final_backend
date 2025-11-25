<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

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
        DB::raw('DATE(created_at) as Date')
    )
    ->where('supplier_code', $supplierCode)
    ->get();

    return response()->json($details);
}

}