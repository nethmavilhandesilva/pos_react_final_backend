<?php

namespace App\Http\Controllers;

use App\Mail\DayEndWeightReportMail;
use App\Models\GrnEntry;
use App\Models\Supplier;
use App\Models\Sale;
use App\Models\Item;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Make sure Log is imported
use App\Models\Salesadjustment;
use App\Models\SalesHistory;
use Carbon\Carbon;
use App\Models\Setting;
use App\Models\CustomersLoan;
use Illuminate\Http\JsonResponse;
use App\Models\Commission;
/* 
    SalesEntryController handles CRUD operations for sales entries,
    including complex logic for commission calculation, price updates,
    and maintaining sales adjustment history.
*/
class SalesEntryController extends Controller
{
 public function index(Request $request): JsonResponse
{
    try {
        $currentUser = auth()->user();

        Log::info('SalesEntryController@index called', [
            'db_id' => $currentUser?->id,
            'user_id' => $currentUser?->user_id, // This is the important one!
            'name' => $currentUser?->name,
            'role' => $currentUser?->role,
        ]);

        // Base query
        $query = Sale::with(['customer']);

        // 🔐 Apply User filter
        if ($currentUser && $currentUser->role === 'User') {
            // Filter by user_id field (which should be 'pos12345')
            $query->where('UniqueCode', $currentUser->user_id);
        }

        $sales = $query->get();

        Log::info('SalesEntryController@index: Query successful', [
            'total_sales' => $sales->count(),
            'filtered_by' => $currentUser?->role === 'User' ? $currentUser->user_id : 'none',
            'matching_records_found' => $sales->where('UniqueCode', $currentUser?->user_id)->count(),
        ]);

        $printedSales = $sales->where('bill_printed', 'Y')->values();
        $unprintedSales = $sales->where('bill_printed', 'N')->values();

        return response()->json([
            'sales' => $sales,
            'printed_sales' => $printedSales,
            'unprinted_sales' => $unprintedSales,
        ]);

    } catch (\Exception $e) {
        Log::error('SalesEntryController@index FAILED', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'error' => 'Failed to fetch sales data.'
        ], 500);
    }
}
    public function create()
    {
        $suppliers = Supplier::all();
        $items = GrnEntry::select('item_name', 'item_code', 'code')
            ->where('is_hidden', 0) // Add the condition here
            ->distinct()
            ->get();
        $entries = GrnEntry::where('is_hidden', 0)->get();

        // Fetch all items with pack_cost to create a lookup array
        $itemsWithPackCost = Item::select('no', 'pack_due')->get();
        $itemPackCosts = [];
        foreach ($itemsWithPackCost as $item) {
            $itemPackCosts[$item->no] = $item->pack_due;
        }

        // Fetch ALL sales records to display
        $sales = Sale::where('Processed', 'N')->get();

        // Add pack_cost to each sale
        foreach ($sales as $sale) {
            $sale->pack_due = $itemPackCosts[$sale->item_code] ?? 0;
        }

        $customers = Customer::all();
        $totalSum = $sales->sum('total'); // Sum will now be for all displayed sales

        $unprocessedSales = Sale::whereIn('Processed', ['Y', 'N']) // Include both processed and unprocessed
            ->get();

        // Add pack_cost to each unprocessed sale
        foreach ($unprocessedSales as $sale) {
            $sale->pack_due = $itemPackCosts[$sale->item_code] ?? 0;
        }

        $salesPrinted = Sale::where('bill_printed', 'Y')
            ->orderBy('created_at', 'desc')
            ->orderBy('bill_no') // Or ->orderBy('created_at') for chronological order
            ->get()
            ->groupBy('customer_code');

        // Add pack_cost to each printed sale
        foreach ($salesPrinted as $customerSales) {
            foreach ($customerSales as $sale) {
                $sale->pack_due = $itemPackCosts[$sale->item_code] ?? 0;
            }
        }

        $totalUnprocessedSum = $unprocessedSales->sum('total');

        $salesNotPrinted = Sale::where('bill_printed', 'N')
            ->orderBy('customer_code')
            ->get()
            ->groupBy('customer_code');

        // Add pack_cost to each not printed sale
        foreach ($salesNotPrinted as $customerSales) {
            foreach ($customerSales as $sale) {
                $sale->pack_due = $itemPackCosts[$sale->item_code] ?? 0;
            }
        }

        $billDate = Setting::value('value');

        // Calculate total for unprocessed sales
        $totalUnprintedSum = Sale::where('bill_printed', 'N')->sum('total');

        $lastDayStartedSetting = Setting::where('key', 'last_day_started_date')->first();
        $lastDayStartedDate = $lastDayStartedSetting ? Carbon::parse($lastDayStartedSetting->value) : null;

        $nextDay = $lastDayStartedDate ? $lastDayStartedDate->addDay() : Carbon::now();

        $codes = Sale::select('code')
            ->distinct()
            ->orderBy('code')
            ->get();

        // Create salesArray with pack_cost for JavaScript
        $salesArray = Sale::all();
        foreach ($salesArray as $sale) {
            $sale->pack_due = $itemPackCosts[$sale->item_code] ?? 0;
        }

        return view('dashboard', compact(
            'suppliers',
            'items',
            'entries',
            'sales',
            'customers',
            'totalSum',
            'unprocessedSales',
            'salesPrinted',
            'totalUnprocessedSum',
            'salesNotPrinted',
            'totalUnprintedSum',
            'nextDay',
            'codes',
            'billDate',
            'salesArray',
            'itemsWithPackCost'
        ));
    }
public function store(Request $request)
{
    $validated = $request->validate([
        'supplier_code' => 'required|string|max:255',
        'customer_code' => 'required|string|max:255',
        'customer_name' => 'nullable|string|max:255',
        'item_code' => 'required|string|exists:items,no',
        'item_name' => 'required|string|max:255',
        'weight' => 'required|numeric',
        'price_per_kg' => 'required|numeric',
        'pack_due' => 'nullable|numeric',
        'total' => 'required|numeric',
        'packs' => 'required|numeric',
        'given_amount' => 'nullable|numeric',
        'bill_no' => 'nullable|string|max:255',
        'bill_printed' => 'nullable|string|in:N,Y',
    ]);

    try {
        DB::beginTransaction();

        // 1. Fetch Item details
        $item = Item::where('no', $validated['item_code'])->first();
        if (!$item) {
            return response()->json(['error' => 'Item not found.'], 422);
        }

        // --- CALCULATION LOGIC ---
        $bagWeightPerUnit = (float)($item->bag_real_price ?? 0); 
        $numPacks = (int)$validated['packs'];
        $pricePerKg = (float)$validated['price_per_kg'];
        
        // Total Bag Weight to subtract
        $totalBagWeight = $bagWeightPerUnit * $numPacks;
        
        // Net Weight for this specific entry
        $incomingNetWeight = (float)$validated['weight'] - $totalBagWeight;

        // Recalculate Total based on Net Weight (Price * Net Weight)
        // If price is 0 (unpriced items), total remains 0.
        $recalculatedIncomingTotal = $incomingNetWeight * $pricePerKg;
        // -------------------------

        $billPrinted = $validated['bill_printed'] ?? null;
        $currentEntry = [
            'time' => now('Asia/Colombo')->format('h:i A'),
            'weight' => (float)$validated['weight'], 
            'packs' => $numPacks
        ];

        $shouldCheckForUpdate = 
            ($billPrinted === null || $billPrinted === 'N') &&
            $pricePerKg == 0;

        if ($shouldCheckForUpdate) {
            $existingSale = Sale::where('customer_code', strtoupper($validated['customer_code']))
                ->where('item_code', $validated['item_code'])
                ->where('supplier_code', $validated['supplier_code'])
                ->where(function($query) {
                    $query->where('bill_printed', 'N')->orWhereNull('bill_printed');
                })
                ->where('price_per_kg', 0)
                ->where('Processed', 'N')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($existingSale) {
                $newWeight = $existingSale->weight + $incomingNetWeight;
                $newPacks = $existingSale->packs + $numPacks;
                
                // Since price_per_kg is 0 for these updates, total remains 0
                $newTotal = $newWeight * 0; 

                $history = $existingSale->breakdown_history;
                if (is_string($history)) { $history = json_decode($history, true); }
                if (!is_array($history)) {
                    $history = [['time' => $existingSale->created_at->format('h:i A'), 'weight' => (float)$existingSale->weight, 'packs' => (int)$existingSale->packs]];
                }
                $history[] = $currentEntry;

                $existingSale->update([
                    'weight' => $newWeight,
                    'packs' => $newPacks,
                    'total' => $newTotal, // Updated Total
                    'SupplierTotal' => 0,
                    'profit' => 0,
                    'breakdown_history' => $history,
                    'bag_real_weight' => $bagWeightPerUnit,
                    'updated_at' => now(),
                ]);

                DB::commit();
                return response()->json(['success' => true, 'message' => 'Existing record updated', 'data' => $existingSale->fresh()]);
            }
        }

        // ---------- COMMISSION & PROFIT LOGIC ----------
        $commissionAmount = 0.00;
        $commissionRule = Commission::where('item_code', $validated['item_code'])
            ->where('starting_price', '<=', $pricePerKg)
            ->where('end_price', '>=', $pricePerKg)
            ->first();

        if (!$commissionRule) {
            $commissionRule = Commission::where('supplier_code', $validated['supplier_code'])->where('starting_price', '<=', $pricePerKg)->where('end_price', '>=', $pricePerKg)->first();
        }
        if (!$commissionRule) {
            $commissionRule = Commission::where('type', 'Z')->where('starting_price', '<=', $pricePerKg)->where('end_price', '>=', $pricePerKg)->first();
        }
        if ($commissionRule) { $commissionAmount = $commissionRule->commission_amount; }

        $customerPackCost = $item->pack_cost ?? 0;
        $customerPackLabour = $item->pack_due ?? 0;

        $supplierPricePerKg = abs($pricePerKg - $commissionAmount);
        
        // Final calculations for NEW sale
        $supplierTotal = $incomingNetWeight * $supplierPricePerKg;
        $profit = $recalculatedIncomingTotal - $supplierTotal;

        $settingDate = Setting::value('value') ?? now()->toDateString();

        // ---------- CREATE NEW SALE ----------
        $sale = Sale::create([
            'supplier_code' => $validated['supplier_code'],
            'customer_code' => strtoupper($validated['customer_code']),
            'customer_name' => $validated['customer_name'],
            'item_code' => $validated['item_code'],
            'item_name' => $validated['item_name'],
            'weight' => $incomingNetWeight, 
            'price_per_kg' => $pricePerKg,
            'pack_due' => $validated['pack_due'] ?? 0,
            'total' => $recalculatedIncomingTotal, // RECACLULATED TOTAL HERE
            'packs' => $numPacks,
            'CustomerPackCost' => $customerPackCost,
            'CustomerPackLabour' => $customerPackLabour,
            'SupplierWeight' => $incomingNetWeight,
            'SupplierPricePerKg' => $supplierPricePerKg,
            'SupplierTotal' => $supplierTotal,
            'SupplierPackCost' => $customerPackCost,
            'SupplierPackLabour' => $customerPackLabour,
            'profit' => $profit,
            'breakdown_history' => [$currentEntry],
            'Processed' => 'N',
            'CustomerBillEnteredOn' => now(),
            'UniqueCode' => auth()->user()->user_id,
            'Date' => $settingDate,
            'ip_address' => $request->ip(),
            'given_amount' => $validated['given_amount'],
            'bill_printed' => $billPrinted,
            'bill_no' => $validated['bill_no'] ?? null,
            'commission_amount' => $commissionAmount,
            'bag_real_weight' => $bagWeightPerUnit, 
        ]);

        DB::commit();
        return response()->json(['success' => true, 'message' => 'New record created with updated total', 'data' => $sale->fresh()]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Sales Entry Failed: ' . $e->getMessage());
        return response()->json(['error' => 'Failed: ' . $e->getMessage()], 422);
    }
}
    public function markAllAsProcessed(Request $request)
    {
        try {
            DB::beginTransaction();

            Sale::where('Processed', 'N')->update([
                'Processed' => 'Y',
                'bill_printed' => DB::raw("IFNULL(bill_printed, 'N')") // Set to 'N' only if currently NULL
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'All sales with Processed = N are now marked as processed, and NULL bill_printed values set to N.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Error marking all sales as processed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark sales as processed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function markAsPrinted(Request $request)
    {
        \Log::info('markAsPrinted Request Data:', $request->all());

        $salesIds = $request->input('sales_ids');

        if (empty($salesIds)) {
            return response()->json(['status' => 'error', 'message' => 'No sales IDs provided.'], 400);
        }

        try {
            $existingBillNo = Sale::whereIn('id', $salesIds)
                ->where('processed', 'Y')
                ->whereNotNull('bill_no')
                ->first()?->bill_no;
            $billNoToUse = $existingBillNo;
            if (empty($billNoToUse)) {
                $billNoToUse = $this->generateNewBillNumber();
            }

            // Step 3: Update all sales records with the determined bill number.
            // We do this in a single transaction for reliability.
            \DB::transaction(function () use ($salesIds, $billNoToUse) {
                $salesRecords = Sale::whereIn('id', $salesIds)->get();

                foreach ($salesRecords as $sale) {
                    // If it's a reprint, update the timestamp for reprint history.
                    if ($sale->bill_printed === 'Y') {
                        $sale->BillReprintAfterChanges = now();
                    }

                    // Update the main fields for all selected records.
                    $sale->bill_printed = 'Y';
                    $sale->processed = 'Y';
                    $sale->bill_no = $billNoToUse;

                    // Set the first print date only if it hasn't been set before.
                    $sale->FirstTimeBillPrintedOn = $sale->FirstTimeBillPrintedOn ?? now();

                    $sale->save();
                }
            });

            \Log::info('Sales records updated successfully for IDs:', ['sales_ids' => $salesIds, 'bill_no' => $billNoToUse]);

            return response()->json([
                'status' => 'success',
                'message' => 'Sales marked as printed and reprint timestamp updated if needed!',
                'bill_no' => $billNoToUse
            ]);

        } catch (\Exception $e) {
            \Log::error('Error updating sales records:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'sales_ids' => $salesIds
            ]);
            return response()->json(['status' => 'error', 'message' => 'Failed to update sales records.'], 500);
        }
    }

    // Helper method to generate a new bill number
    private function generateNewBillNumber()
    {
        return \DB::transaction(function () {
            $bill = \App\Models\BillNumber::lockForUpdate()->first();
            if (!$bill) {
                $bill = \App\Models\BillNumber::create(['last_bill_no' => 999]);
            }
            $bill->last_bill_no += 1;
            $bill->save();
            return $bill->last_bill_no;
        });
    }

public function update(Request $request, Sale $sale)
{
    // --- 1. Validation including new optional flag for price sync ---
    $validatedData = $request->validate([
        'customer_code' => 'required|string|max:255',
        'customer_name' => 'nullable|string|max:255',
        'supplier_code' => 'nullable|string|max:255',
        'item_code' => 'required|string|max:255',
        'item_name' => 'required|string|max:255',
        'weight' => 'required|numeric|min:0',
        'price_per_kg' => 'required|numeric|min:0',
        'pack_due' => 'nullable|numeric|min:0',
        'total' => 'required|numeric|min:0',
        'packs' => 'required|integer|min:0',
        'given_amount' => 'nullable|numeric|min:0',
        'bill_no' => 'nullable|string|max:255',
        'bill_printed' => 'nullable|string|in:N,Y',
        
        // New optional field to trigger bulk price update
        'update_related_price' => 'nullable|boolean', 
    ]);

    DB::beginTransaction();
    $affectedSales = [];
    $originalData = []; // FIX: Initialize for proper scope management

    try {
        $settingDate = Setting::value('value');
        $formattedDate = Carbon::parse($settingDate)->format('Y-m-d');
        
        // =============================================================
        // ⭐ COMMISSION AND PRICE RE-CALCULATION BLOCK
        // =============================================================
        $newPricePerKg = $validatedData['price_per_kg'];
        $commissionAmount = 0.00;
        
        // 1. Find Commission Rule (Replicating logic from store method)
        $commissionRule = Commission::where('item_code', $validatedData['item_code'])
            ->where('starting_price', '<=', $newPricePerKg)
            ->where('end_price', '>=', $newPricePerKg)
            ->orWhere(function ($query) use ($validatedData, $sale, $newPricePerKg) {
                $query->where('supplier_code', $validatedData['supplier_code'] ?? $sale->supplier_code)
                      ->where('starting_price', '<=', $newPricePerKg)
                      ->where('end_price', '>=', $newPricePerKg);
            })
            ->orWhere(function ($query) use ($newPricePerKg) {
                $query->where('type', 'Z')
                      ->where('starting_price', '<=', $newPricePerKg)
                      ->where('end_price', '>=', $newPricePerKg);
            })
            ->first();

        if ($commissionRule) {
            $commissionAmount = $commissionRule->commission_amount;
        }

        // 2. Calculate Supplier Price and Total
        $supplierPricePerKg = abs($newPricePerKg - $commissionAmount);
        
        // Fetch the Item to get CustomerPackLabour for Total calculation
        $item = Item::where('no', $validatedData['item_code'])->first();
        if (!$item) {
             throw new \Exception('Item not found for calculation.');
        }
        $customerPackLabour = $item->pack_due ?? 0;
        
        // 3. Re-calculate all total fields for the main updated record
        $newSupplierTotal = $validatedData['weight'] * $supplierPricePerKg;
        // Customer Total: weight * price_per_kg + packs * CustomerPackLabour
        $newTotal = $validatedData['weight'] * $newPricePerKg + $validatedData['packs'] * $customerPackLabour; 
        $newProfit = $newTotal - $newSupplierTotal;
        // =============================================================

        // --- Track original if bill_printed ('Y' status) ---
        if ($sale->bill_printed === 'Y') {
            $originalData = $sale->toArray(); 

            Salesadjustment::create([
                 'customer_code' => $originalData['customer_code'],
                 'supplier_code' => $originalData['supplier_code'] ?? null,
                 'code' => $originalData['item_code'],
                 'item_code' => $originalData['item_code'],
                 'item_name' => $originalData['item_name'],
                 'weight' => $originalData['weight'],
                 'price_per_kg' => $originalData['price_per_kg'],
                 'pack_due' => $originalData['pack_due'] ?? 0,
                 'total' => $originalData['total'],
                 'packs' => $originalData['packs'],
                 'bill_no' => $originalData['bill_no'],
                 'user_id' => 'c11',
                 'type' => 'original',
                 'original_created_at' => \Carbon\Carbon::parse($sale->Date)
                     ->setTimeFrom(\Carbon\Carbon::parse($sale->created_at))
                     ->format('Y-m-d H:i:s'),
                 'original_updated_at' => $sale->updated_at,
                 'Date' => $formattedDate,
            ]);
        }

        // --- Update the sale (main record) ---
        $sale->update([
            'customer_code' => $validatedData['customer_code'],
            'customer_name' => $validatedData['customer_name'] ?? $sale->customer_name,
            'code' => $validatedData['item_code'],
            'supplier_code' => $validatedData['supplier_code'] ?? $sale->supplier_code,
            'item_code' => $validatedData['item_code'],
            'item_name' => $validatedData['item_name'],
            'weight' => $validatedData['weight'],
            'packs' => $validatedData['packs'],
            
            // ⭐ UPDATED FIELDS WITH NEW CALCULATIONS
            'price_per_kg' => $newPricePerKg, 
            'commission_amount' => $commissionAmount, 
            'SupplierPricePerKg' => $supplierPricePerKg, 
            'SupplierTotal' => $newSupplierTotal, 
            'total' => $newTotal, // Use calculated Customer Total
            'profit' => $newProfit, // Use calculated Profit
            // ⭐ END UPDATED FIELDS

            'pack_due' => $validatedData['pack_due'] ?? $sale->pack_due,
            'given_amount' => $validatedData['given_amount'] ?? $sale->given_amount,
            'bill_no' => $validatedData['bill_no'] ?? $sale->bill_no,
            'bill_printed' => $validatedData['bill_printed'] ?? $sale->bill_printed,
            'updated' => 'Y',
            'BillChangedOn' => now(),
        ]);
        
        // --- 2. Bulk Price Update Logic (Based on Print Status) ---
        if ($request->input('update_related_price') === true) {
            $customerCode = $validatedData['customer_code'];
            $itemCode = $validatedData['item_code'];
            $supplierCode = $validatedData['supplier_code'] ?? $sale->supplier_code;

            // Start building the query to find related sales
            $updateQuery = Sale::where('customer_code', $customerCode)
                ->where('item_code', $itemCode)
                ->where('supplier_code', $supplierCode)
                ->where('id', '!=', $sale->id); // Exclude the current sale

            $currentBillPrinted = $sale->bill_printed === 'Y';
            $currentBillNo = $sale->bill_no;

            if ($currentBillPrinted && $currentBillNo) {
                // Scenario 1: Update a PRINTED record. Sync price ONLY within the same bill.
                $updateQuery->where('bill_printed', 'Y')->where('bill_no', $currentBillNo);
            } else {
                // Scenario 2: Update an UNPRINTED ('N' or null) record. Sync price across all UNPRINTED sales.
                $updateQuery->where(function ($query) {
                    $query->where('bill_printed', 'N')->orWhereNull('bill_printed');
                });
            }

            // --- BULK UPDATE with DB::raw for calculated fields ---
            $updatedSalesCount = $updateQuery->update([
                'price_per_kg' => $newPricePerKg,
                'commission_amount' => $commissionAmount,
                'SupplierPricePerKg' => $supplierPricePerKg,
                
                // Recalculate Customer Total
                // Assuming 'CustomerPackLabour' and 'weight' are columns on the 'sales' table
                'total' => DB::raw("weight * $newPricePerKg + packs * CustomerPackLabour"), 
                
                // Recalculate Supplier Total
                'SupplierTotal' => DB::raw("weight * $supplierPricePerKg"), 
                
                // Recalculate Profit: Customer Total - Supplier Total
                'profit' => DB::raw(" (weight * $newPricePerKg + packs * CustomerPackLabour) - (weight * $supplierPricePerKg) "), 
                
                'updated' => 'Y',
            ]);

            // Fetch the list of ALL affected sales (the main one + the bulk updated ones)
            $affectedSales = Sale::where('customer_code', $customerCode)
                ->where('item_code', $itemCode)
                ->where('supplier_code', $supplierCode);

            if ($currentBillPrinted && $currentBillNo) {
                $affectedSales->where('bill_printed', 'Y')->where('bill_no', $currentBillNo);
            } else {
                $affectedSales->where(function ($query) {
                    $query->where('bill_printed', 'N')->orWhereNull('bill_printed');
                });
            }
            
            $affectedSales = $affectedSales->get();

        } else {
            // Only the main record was updated
            $affectedSales = collect([$sale->fresh()]); 
        }
        
        // --- Update GRN remaining stock safely (existing logic) ---
        $this->updateGrnRemainingStock($validatedData['item_code']);

        // --- Track updated sale if bill_printed (FIXED LOGIC) ---
        // Create an 'updated' adjustment record if an 'original' one was created
        if (!empty($originalData)) { 
            $newData = $sale->fresh(); 

            Salesadjustment::create([
                 'customer_code' => $newData['customer_code'],
                 'supplier_code' => $newData['supplier_code'] ?? null,
                 'code' => $newData['item_code'],
                 'item_code' => $newData['item_code'],
                 'item_name' => $newData['item_name'],
                 'weight' => $newData['weight'],
                 'price_per_kg' => $newData['price_per_kg'],
                 'pack_due' => $newData['pack_due'] ?? 0,
                 'total' => $newData['total'],
                 'packs' => $newData['packs'],
                 'bill_no' => $newData['bill_no'],
                 'user_id' => 'c11',
                 'type' => 'updated',
                 'original_created_at' => \Carbon\Carbon::parse($sale->Date)
                     ->setTimeFrom(\Carbon\Carbon::parse($sale->created_at))
                     ->format('Y-m-d H:i:s'),
                 'original_updated_at' => $sale->updated_at,
                 'Date' => $formattedDate,
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'sales' => $affectedSales->toArray(), 
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Failed to update sales record: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to update sales record: ' . $e->getMessage(),
        ], 500);
    }
}

   public function destroy(Sale $sale)
{
    try {
        // ✅ Get setting date safely
        $settingDate = Setting::value('value') ?? now();
        $formattedDate = Carbon::parse($settingDate)->format('Y-m-d');

        if ($sale->bill_printed === 'Y') {

            // ✅ Common adjustment data
            $adjustmentData = [
                'customer_code'       => $sale->customer_code,
                'supplier_code'       => $sale->supplier_code,
                'code'                => $sale->item_code,
                'item_code'           => $sale->item_code,
                'item_name'           => $sale->item_name,
                'weight'              => $sale->weight,
                'price_per_kg'        => $sale->price_per_kg,
                'total'               => $sale->total,
                'packs'               => $sale->packs,
                'bill_no'             => $sale->bill_no,
                'original_created_at' => $sale->created_at, // ✅ SAFE
                'Date'                => $formattedDate,     // ✅ Setting date
            ];

            // ✅ Original record
            Salesadjustment::create(
                $adjustmentData + ['type' => 'original']
            );

            // ✅ Deleted record
            Salesadjustment::create(
                $adjustmentData + ['type' => 'deleted']
            );
        }

        // ✅ Delete sale and update GRN stock
        $saleCode = $sale->code;
        $sale->delete();
        $this->updateGrnRemainingStock($saleCode);

        return response()->json([
            'success' => true,
            'message' => 'Sales record deleted successfully.'
        ]);

    } catch (\Exception $e) {

        // ✅ Helpful error logging
        Log::error('Error deleting sale', [
            'sale_id' => $sale->id ?? null,
            'error'   => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'An error occurred while deleting the sale.',
            'error'   => $e->getMessage(), // remove in production if needed
        ], 500);
    }
}


    public function updateGrnRemainingStock(): void
    {
        // Fetch all GRN entries and group them by their unique 'code'
        $grnEntriesByCode = GrnEntry::all()->groupBy('code');

        // Fetch all sales and sales history entries
        $currentSales = Sale::all()->groupBy('code');
        $historicalSales = SalesHistory::all()->groupBy('code');

        foreach ($grnEntriesByCode as $grnCode => $entries) {
            // Calculate the total original packs and weight for the current GRN code
            $totalOriginalPacks = $entries->sum('original_packs');
            $totalOriginalWeight = $entries->sum('original_weight');
            $totalWastedPacks = $entries->sum('wasted_packs');
            $totalWastedWeight = $entries->sum('wasted_weight');

            // Sum up packs and weight from sales for this specific GRN code
            $totalSoldPacks = 0;
            if (isset($currentSales[$grnCode])) {
                $totalSoldPacks += $currentSales[$grnCode]->sum('packs');
            }
            if (isset($historicalSales[$grnCode])) {
                $totalSoldPacks += $historicalSales[$grnCode]->sum('packs');
            }

            $totalSoldWeight = 0;
            if (isset($currentSales[$grnCode])) {
                $totalSoldWeight += $currentSales[$grnCode]->sum('weight');
            }
            if (isset($historicalSales[$grnCode])) {
                $totalSoldWeight += $historicalSales[$grnCode]->sum('weight');
            }

            // Calculate remaining stock based on all original, sold, and wasted amounts
            $remainingPacks = $totalOriginalPacks - $totalSoldPacks - $totalWastedPacks;
            $remainingWeight = $totalOriginalWeight - $totalSoldWeight - $totalWastedWeight;

            // Update each individual GRN entry with the new remaining values
            foreach ($entries as $grnEntry) {
                $grnEntry->packs = max($remainingPacks, 0);
                $grnEntry->weight = max($remainingWeight, 0);
                $grnEntry->save();
            }
        }
    }


    public function saveAsUnprinted(Request $request)
    {

        $validated = $request->validate([
            'sale_ids' => 'required|array',
            'sale_ids.*' => 'integer|exists:sales,id', // Check that each ID exists
        ]);

        if (!empty($validated['sale_ids'])) {
            Sale::whereIn('id', $validated['sale_ids'])->update(['is_printed' => 0]);
        }

        return response()->json(['success' => true]);
    }

    public function getUnprintedSales($customer_code)
    {
        $sales = Sale::where('customer_code', $customer_code)
            ->where('bill_printed', 'N')
            ->get();

        // Return the sales records as a JSON response
        return response()->json($sales);
    }
    public function getAllSalesData()
    {
        try {
            // Fetch all sales records from the database
            $allSales = Sale::all();

            // Return the sales records as a JSON response
            return response()->json($allSales);

        } catch (\Exception $e) {
            // Log the full error for server-side debugging
            Log::error('Failed to retrieve sales data: ' . $e->getMessage(), [
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            // Return a detailed error response to the client
            return response()->json([
                'error' => 'Failed to retrieve sales data.',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
    public function getAllSales()
    {
        $sales = Sale::all(); // or your logic
        return response()->json(['sales' => $sales]);
    }

    public function getLoanAmount(Request $request)
    {
        // Validate the request to ensure a customer_short_name is present.
        $request->validate(['customer_short_name' => 'required|string']);

        $customerShortName = $request->input('customer_short_name');

        // Sum of 'old' loan_type amounts
        $oldSum = CustomersLoan::where('customer_short_name', $customerShortName)
            ->where('loan_type', 'old')
            ->sum('amount');

        // Sum of 'today' loan_type amounts
        $todaySum = CustomersLoan::where('customer_short_name', $customerShortName)
            ->where('loan_type', 'today')
            ->sum('amount');

        // Calculate total loan amount based on your logic
        if ($todaySum == 0) {
            $totalLoanAmount = $oldSum;
        } else {
            $totalLoanAmount = $todaySum - $oldSum;
        }

        // Return the sum as a JSON response.
        return response()->json(['total_loan_amount' => $totalLoanAmount]);
    }


    public function updateGivenAmount(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'given_amount' => 'required|numeric|min:0',
        ]);

        // 🔹 Update this specific sale's given_amount with the full amount
        $sale->update([
            'given_amount' => $validated['given_amount'] // Store the original full amount
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Given amount updated successfully',
            'sale' => $sale
        ]);
    }
   public function processDay(Request $request)
{
    $recipientEmail = 'nethmavilhan@gmail.com';

    // ✅ Use selected date from frontend
    $processLogDate = $request->input('date') ?? now()->toDateString();

    // ✅ Get last stored date from settings.value (ONLY for adjustments)
    $lastSetting = \App\Models\Setting::where('key', 'last_day_started_date')->first();
    $adjustmentDate = $lastSetting ? $lastSetting->value : $processLogDate;

    // 1️⃣ Fetch Current Sales
    $allSales = \App\Models\Sale::all();
    $totalRecordsToMove = $allSales->count();

    if ($totalRecordsToMove === 0) {
        return response()->json([
            'success' => false,
            'message' => "Sales table is empty."
        ], 404);
    }

    // 2️⃣ Fetch Adjustments using PREVIOUS stored date
    $adjustments = \App\Models\Salesadjustment::whereDate('Date', $adjustmentDate)
        ->orderBy('created_at', 'desc')
        ->get();

    // 3️⃣ Summarize Sales
    $summarizedSales = \App\Models\Sale::selectRaw("
        item_code, item_name,
        SUM(packs) AS packs,
        SUM(weight) AS weight,
        SUM(total) AS total
    ")
    ->groupBy('item_code', 'item_name')
    ->orderBy('item_name', 'asc')
    ->get();

    // Add pack_due
    $summarizedSales = $summarizedSales->map(function ($sale) {
        $item = \App\Models\Item::where('no', $sale->item_code)->first();
        $sale->pack_due = $item ? $item->pack_due : 0;
        return $sale;
    });

    // 4️⃣ Group Sales by Customer → Bill
    $groupedSales = $allSales->groupBy('customer_code')->map(function ($customerSales) {
        return $customerSales->groupBy('bill_no');
    });

    // 5️⃣ Supplier Report
    $supplierReport = \App\Models\Sale::select([
        'supplier_code',
        'customer_code',
        'item_code',
        'item_name',
        'SupplierWeight',
        'SupplierPricePerKg',
        'SupplierTotal',
        'SupplierPackCost',
        'SupplierPackLabour',
        'profit',
        'supplier_bill_printed',
        'supplier_bill_no',
        'Date'
    ])
    ->orderBy('Date', 'desc')
    ->get()
    ->groupBy('supplier_code');

    // Totals
    $totals = $summarizedSales->reduce(function ($acc, $sale) {
        $acc['total_weight'] += (float) $sale->weight;
        $acc['total_net_total'] += ((float) $sale->total - ((float) $sale->packs * (float) $sale->pack_due));
        return $acc;
    }, [
        'total_weight' => 0.0,
        'total_net_total' => 0.0
    ]);

    // Email Data
    $reportData = [
        'processLogDate'    => $processLogDate,
        'adjustmentDate'    => $adjustmentDate,
        'totalRecordsMoved' => $totalRecordsToMove,
        'sales'             => $summarizedSales,
        'raw_sales'         => $allSales,
        'grouped_sales'     => $groupedSales,
        'adjustments'       => $adjustments,
        'supplier_report'   => $supplierReport,
        'totals'            => $totals,
    ];

    \DB::beginTransaction();

    try {

        // Move Sales → SalesHistory
        $historyData = [];
        $allowedColumns = (new \App\Models\Sale())->getFillable();

        foreach ($allSales as $sale) {
            $data = $sale->only($allowedColumns);
            unset($data['id']);
            $data['bag_real_weight'] = $sale->bag_real_weight ?? 0;

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = json_encode($value);
                }
            }

            $historyData[] = $data;
        }

        \App\Models\SalesHistory::insert($historyData);
        \App\Models\Sale::query()->delete();

        // ✅ SAVE SELECTED DATE TO SETTINGS
        \App\Models\Setting::updateOrCreate(
            ['key' => 'last_day_started_date'],
            ['value' => $processLogDate]
        );

        \DB::commit();

        // Send Email
        try {
            \Mail::to($recipientEmail)->send(new DayEndWeightReportMail($reportData));
        } catch (\Exception $e) {
            \Log::error("Mail Error: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Process complete. Reports emailed.",
            'adjustment_date_used' => $adjustmentDate,
            'saved_date' => $processLogDate
        ]);

    } catch (\Exception $e) {
        \DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}



}