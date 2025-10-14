<?php

namespace App\Http\Controllers;

use App\Models\GrnEntry;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Sale;
use Illuminate\Http\Request;

class GrnEntryController extends Controller
{
    // API: Get all GRN entries
    public function index()
    {
        $entries = GrnEntry::latest()->get();
        return response()->json($entries);
    }

    // API: Get data for creating GRN
    public function createData()
    {
        $items = Item::all();
        $suppliers = Supplier::all();
        return response()->json([
            'items' => $items,
            'suppliers' => $suppliers
        ]);
    }

    // API: Store new GRN entry
    public function store(Request $request)
    {
        $request->validate([
            'item_code' => 'required|string',
            'supplier_name' => 'required|string|max:255',
            'packs' => 'required|integer|min:1',
            'weight' => 'required|numeric|min:0.01',
            'txn_date' => 'required|date',
            'grn_no' => 'required|string',
            'warehouse_no' => 'required|string',
            'total_grn' => 'nullable|numeric|min:0',
            'per_kg_price' => 'nullable|numeric|min:0',
        ]);

        // Fetch item
        $item = Item::where('no', $request->item_code)->first();
        if (!$item) {
            return response()->json(['error' => 'Invalid item selected.'], 422);
        }
        $itemName = $item->type;

        // Find or create supplier
        $supplierName = $request->supplier_name;
        $supplier = Supplier::firstOrCreate(
            ['code' => $supplierName],
            ['name' => '']
        );
        $supplierCode = $supplier->code;

        // Auto generate values
        $last = GrnEntry::latest()->first();
        $autoNo = $last ? $last->id + 1 : 1;
        $autoPurchaseNo = str_pad($autoNo, 4, '0', STR_PAD_LEFT);

        $lastGrnEntry = GrnEntry::orderBy('sequence_no', 'desc')->first();
        $nextSequentialNumber = $lastGrnEntry ? $lastGrnEntry->sequence_no + 1 : 1000;

        // Build code
        $code = strtoupper($request->item_code . '-' . $supplierCode . '-' . $nextSequentialNumber);

        // Create GRN entry
        $grnEntry = GrnEntry::create([
            'auto_purchase_no' => $autoPurchaseNo,
            'code' => $code,
            'supplier_code' => strtoupper($supplierCode),
            'item_code' => $request->item_code,
            'item_name' => $itemName,
            'packs' => $request->packs,
            'weight' => $request->weight,
            'txn_date' => $request->txn_date,
            'grn_no' => $request->grn_no,
            'warehouse_no' => $request->warehouse_no,
            'original_packs' => $request->packs,
            'original_weight' => $request->weight,
            'sequence_no' => $nextSequentialNumber,
            'total_grn' => $request->total_grn,
            'PerKGPrice' => $request->per_kg_price,
            'show_status' => 1,
        ]);

        return response()->json([
            'message' => 'GRN Entry added successfully.',
            'entry' => $grnEntry
        ], 201);
    }

    // API: Get single GRN entry
    public function show($id)
    {
        $entry = GrnEntry::findOrFail($id);
        return response()->json($entry);
    }

    // API: Update GRN entry
    public function update(Request $request, $id)
    {
        $request->validate([
            'item_code' => 'required',
            'item_name' => 'required|string',
            'supplier_code' => 'required',
            'packs' => 'required|integer|min:1',
            'weight' => 'required|numeric|min:0.01',
            'txn_date' => 'required|date',
            'grn_no' => 'required|string',
            'warehouse_no' => 'required|string',
            'total_grn' => 'nullable|numeric|min:0',
            'per_kg_price' => 'nullable|numeric|min:0',
        ]);

        $entry = GrnEntry::findOrFail($id);
        $entry->update($request->all());

        return response()->json([
            'message' => 'Entry updated successfully.',
            'entry' => $entry
        ]);
    }

    // API: Delete GRN entry
    public function destroy($id)
    {
        $entry = GrnEntry::findOrFail($id);
        $entry->delete();

        return response()->json(['message' => 'Entry deleted successfully.']);
    }
}