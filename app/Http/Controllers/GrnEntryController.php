<?php

namespace App\Http\Controllers;

use App\Models\GrnEntry;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Sale;
use Illuminate\Http\Request;
use App\Models\GrnEntry2;
use App\Models\Setting; 
use Illuminate\Support\Facades\Log;

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
  public function getNotChangingGRNs()
{
    try {
        $notChangingGRNs = GrnEntry::select('code', 'item_code', 'item_name', 'grn_no', 'PerKGPrice')
            ->get();
        
        return response()->json($notChangingGRNs);
    } catch (\Exception $e) {
        Log::error('Failed to fetch not changing GRNs: ' . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch GRNs'], 500);
    }
}
    /**
     * Get GRN balance for a specific code
     */
    public function getGrnBalance($code)
    {
        try {
            $grn = GrnEntry::where('code', $code)->first();
            
            if (!$grn) {
                return response()->json([
                    'total_packs' => 0,
                    'total_weight' => 0
                ]);
            }
            
            return response()->json([
                'total_packs' => $grn->packs,
                'total_weight' => $grn->weight
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch GRN balance: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch balance'], 500);
        }
    }

   
    public function store2(Request $request)
    {
        Log::info('store2 method triggered. Request data:', $request->all());

        try {
            $validated = $request->validate([
                'code' => 'required|exists:grn_entries,code',
                'packs' => 'nullable|numeric',
                'weight' => 'nullable|numeric',
                'per_kg_price' => 'nullable|numeric',
            ]);
            Log::info('Validation passed.');

            // Find GRN entry
            $grn = GrnEntry::where('code', $request->code)->first();
            if (!$grn) {
                Log::warning('GRN entry not found for code: ' . $request->code);
                return response()->json(['success' => false, 'message' => 'GRN entry not found.'], 404);
            }
            Log::info('Found GRN entry:', $grn->toArray());

            // Save old values
            $oldPacks = $grn->packs;
            $oldWeight = $grn->weight;
            Log::info("Old values - Packs: {$oldPacks}, Weight: {$oldWeight}");

            // Update GRN entry
            $grn->packs += (float)$request->packs;
            $grn->weight += (float)$request->weight;
            $grn->original_packs += (float)$request->packs;
            $grn->original_weight += (float)$request->weight;
            $grn->PerKGPrice = (float)$request->per_kg_price;
            $grn->SalesKGPrice = (float)$request->per_kg_price;

            if ($grn->save()) {
                Log::info('GRN entry updated successfully.', $grn->toArray());
            } else {
                Log::error('GRN entry save failed.');
                return response()->json(['success' => false, 'message' => 'Failed to update GRN entry.'], 500);
            }

            // Fetch date from setting
            $settingDate = Setting::value('value');
            $formattedDate = \Carbon\Carbon::parse($settingDate)->format('Y-m-d');
            Log::info("Using Setting date: {$formattedDate}");

            // Insert backup record
            try {
                $backup = GrnEntry2::create([
                    'code' => $grn->code,
                    'supplier_code' => $grn->supplier_code,
                    'item_code' => $grn->item_code,
                    'item_name' => $grn->item_name,
                    'packs' => (float)$request->packs,
                    'weight' => (float)$request->weight,
                    'per_kg_price' => (float)$request->per_kg_price,
                    'txn_date' => $formattedDate,
                    'grn_no' => $grn->grn_no,
                    'type' => 'added',
                ]);
                Log::info('Backup record inserted successfully.', $backup->toArray());
            } catch (QueryException $e) {
                Log::error('Failed to insert backup record into GrnEntry2: ' . $e->getMessage());
            }

            // Call updateGrnRemainingStock if old values were 0
            if ($oldPacks == 0 && $oldWeight == 0) {
                Log::info('Old packs & weight were 0, calling updateGrnRemainingStock.');
             
            }

            // Return JSON for React
            return response()->json([
                'success' => true,
                'message' => 'GRN entry updated successfully',
                'entry' => [
                    'id' => $backup->id ?? null,
                    'code' => $grn->code,
                    'supplier_code' => $grn->supplier_code,
                    'item_code' => $grn->item_code,
                    'item_name' => $grn->item_name,
                    'packs' => (float)$request->packs,
                    'weight' => (float)$request->weight,
                    'per_kg_price' => (float)$request->per_kg_price,
                    'txn_date' => $formattedDate,
                    'grn_no' => $grn->grn_no,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('store2 method failed: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Something went wrong. Check logs.'
            ], 500);
        }
    }

    /**
     * Delete GRN update entry
     */
    public function destroyupdate(Request $request, $id)
    {
        try {
            if (!$id) {
                return response()->json(['success' => false, 'message' => 'ID is required']);
            }

            // Find the specific update entry by ID
            $updateEntry = GrnEntry2::find($id);

            if (!$updateEntry) {
                return response()->json(['success' => false, 'message' => 'Update entry not found']);
            }

            // Get the code, packs, and weight from this record
            $code = $updateEntry->code;
            $packsToSubtract = $updateEntry->packs;
            $weightToSubtract = $updateEntry->weight;

            // Find the original GRN entry by code
            $originalGrn = GrnEntry::where('code', $code)->first();

            if ($originalGrn) {
                $originalGrn->packs -= $packsToSubtract;
                $originalGrn->weight -= $weightToSubtract;
                $originalGrn->original_packs -= $packsToSubtract;
                $originalGrn->original_weight -= $weightToSubtract;

                // Ensure values don't go negative
                $originalGrn->packs = max($originalGrn->packs, 0);
                $originalGrn->weight = max($originalGrn->weight, 0);
                $originalGrn->original_packs = max($originalGrn->original_packs, 0);
                $originalGrn->original_weight = max($originalGrn->original_weight, 0);

                $originalGrn->save();
            }

            // Delete the specific update entry
            $updateEntry->delete();

            return response()->json(['success' => true, 'message' => 'Entry deleted successfully']);
        } catch (\Exception $e) {
            Log::error('destroyupdate method failed: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Error deleting entry: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getByCode($code)
{
    $entries = GrnEntry2::where('code', $code)->get();
    return response()->json($entries);
}
}