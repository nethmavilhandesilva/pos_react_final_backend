<?php

namespace App\Http\Controllers;

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

class SalesEntryController extends Controller
{
    public function index(): JsonResponse
    {
        Log::info('--- SalesEntryController@index: Function was called. ---');
        try {
            // --- FIX ---
            // Removed 'grnEntry' because the relationship does not exist in the Sale.php model
            $sales = Sale::with(['customer'])->get(); 
            // --- END FIX ---

            $printedSales = $sales->where('bill_printed', 'Y')->values();
            $unprintedSales = $sales->where('bill_printed', 'N')->values();
            
            Log::info('SalesEntryController@index: Query successful. Found ' . $sales->count() . ' sales.');

            return response()->json([
                'sales' => $sales,
                'printed_sales' => $printedSales,
                'unprinted_sales' => $unprintedSales,
            ]);

        } catch (\Exception $e) {
            Log::error('SalesEntryController@index: FAILED to fetch sales', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => 'Failed to fetch sales data.'], 500);
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
        'customer_name' => 'nullable',
        'item_code' => 'required|string|exists:items,no',
        'item_name' => 'required',
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

        // --- COMMISSION RULE LOOKUP ---
        $commissionAmount = 0.00;
        $pricePerKg = $validated['price_per_kg'];

        $commissionRule = Commission::where('starting_price', '<=', $pricePerKg)
            ->where('end_price', '>=', $pricePerKg)
            ->first();

        if ($commissionRule) {
            $commissionAmount = $commissionRule->commission_amount;
        }

        // --- FETCH ITEM PACK COST & PACK LABOUR ---
        $item = Item::where('no', $validated['item_code'])->first();

        if (!$item) {
            return response()->json([
                'error' => 'Item not found for the given item_code.'
            ], 422);
        }

        $customerPackCost = $item->pack_cost ?? 0;
        $customerPackLabour = $item->pack_due ?? 0;

        // --- OTHER META DATA ---
        $settingDate = Setting::value('value') ?? now()->toDateString();
        $loggedInUserId = auth()->user()->user_id;
        $uniqueCode = $validated['customer_code'] . '-' . $loggedInUserId;

        $billPrintedStatus = $validated['bill_printed'] ?? null;
        $billNo = $validated['bill_no'] ?? null;

        // --- CREATE SALE RECORD ---
        $sale = Sale::create([
            'supplier_code' => $validated['supplier_code'],
            'customer_code' => strtoupper($validated['customer_code']),
            'customer_name' => $validated['customer_name'],

            'code' => $validated['item_code'],
            'item_code' => $validated['item_code'],
            'item_name' => $validated['item_name'],
            'weight' => $validated['weight'],
            'price_per_kg' => $validated['price_per_kg'],
            'pack_due' => $validated['pack_due'] ?? 0,
            'total' => $validated['total'],
            'packs' => $validated['packs'],

            // ⭐ CUSTOMER FIELDS
            'CustomerPackCost' => $customerPackCost,
            'CustomerPackLabour' => $customerPackLabour,

            // ⭐ SUPPLIER FIELDS (WITH COMMISSION SUBTRACTION)
            'SupplierWeight' => $validated['weight'],
            'SupplierPricePerKg' => $validated['price_per_kg'] - $commissionAmount,
            'SupplierTotal' => $validated['weight'] * ($validated['price_per_kg'] - $commissionAmount),
            'SupplierPackCost' => $customerPackCost,
            'SupplierPackLabour' => $customerPackLabour,

            'Processed' => 'N',
            'FirstTimeBillPrintedOn' => null,
            'BillChangedOn' => null,
            'CustomerBillEnteredOn' => now(),
            'UniqueCode' => $uniqueCode,
            'Date' => $settingDate,
            'ip_address' => $request->ip(),
            'given_amount' => $validated['given_amount'],

            'bill_printed' => $billPrintedStatus,
            'bill_no' => $billNo,

            'commission_amount' => $commissionAmount,
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'data' => $sale->fresh()->toArray()
        ]);

    } catch (\Exception | \Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error('Failed to add sales entry: ' . $e->getMessage());

        return response()->json([
            'error' => 'Failed to add sales entry: ' . $e->getMessage()
        ], 422);
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
        $validatedData = $request->validate([
            'customer_code' => 'required|string|max:255',
            'customer_name' => 'nullable|string|max:255',
            'code' => 'required|string|max:255',
            'supplier_code' => 'nullable|string|max:255',
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0',
            'price_per_kg' => 'required|numeric|min:0',
            'pack_due' => 'nullable|numeric|min:0', // ✅ ADDED
            'total' => 'required|numeric|min:0',
            'packs' => 'required|integer|min:0',
            'grn_entry_code' => 'nullable|string|max:255', // ✅ ADDED
            'original_weight' => 'nullable|numeric|min:0', // ✅ ADDED
            'original_packs' => 'nullable|integer|min:0', // ✅ ADDED
            'given_amount' => 'nullable|numeric|min:0', // ✅ ADDED
            'bill_no' => 'nullable|string|max:255', // ✅ ADDED
            'bill_printed' => 'nullable|string|in:N,Y', // ✅ ADDED
        ]);

        try {
            // Get the setting date value
            $settingDate = Setting::value('value');
            $formattedDate = Carbon::parse($settingDate)->format('Y-m-d');

            $oldPacks = $sale->packs;
            $oldWeight = $sale->weight;

            // --- Adjustment tracking for bill_printed ---
            if ($sale->bill_printed === 'Y') {
                $originalData = $sale->toArray();
                Salesadjustment::create([
                    'customer_code' => $originalData['customer_code'],
                    'supplier_code' => $originalData['supplier_code'] ?? null,
                    'code' => $originalData['code'],
                    'item_code' => $originalData['item_code'],
                    'item_name' => $originalData['item_name'],
                    'weight' => $originalData['weight'],
                    'price_per_kg' => $originalData['price_per_kg'],
                    'pack_due' => $originalData['pack_due'] ?? 0, // ✅ ADDED
                    'total' => $originalData['total'],
                    'packs' => $originalData['packs'],
                    'bill_no' => $originalData['bill_no'],
                    'user_id' => 'c11',
                    'type' => 'original',
                    'original_created_at' => Carbon::parse($sale->Date)
                        ->setTimeFrom(Carbon::parse($sale->created_at))
                        ->format('Y-m-d H:i:s'),
                    'original_updated_at' => $sale->updated_at,
                    'Date' => $formattedDate,
                ]);
            }

            // ✅ Update the sale safely with null coalescing
            $sale->update([
                'customer_code' => $validatedData['customer_code'],
                'customer_name' => $validatedData['customer_name'] ?? $sale->customer_name,
                'code' => $validatedData['code'],
                'supplier_code' => $validatedData['supplier_code'] ?? $sale->supplier_code,
                'item_code' => $validatedData['item_code'],
                'item_name' => $validatedData['item_name'],
                'weight' => $validatedData['weight'],
                'packs' => $validatedData['packs'],
                'price_per_kg' => $validatedData['price_per_kg'],
                'pack_due' => $validatedData['pack_due'] ?? $sale->pack_due, // ✅ ADDED
                'total' => $validatedData['total'],
                'grn_entry_code' => $validatedData['grn_entry_code'] ?? $sale->grn_entry_code, // ✅ ADDED
                'original_weight' => $validatedData['original_weight'] ?? $sale->original_weight, // ✅ ADDED
                'original_packs' => $validatedData['original_packs'] ?? $sale->original_packs, // ✅ ADDED
                'given_amount' => $validatedData['given_amount'] ?? $sale->given_amount, // ✅ ADDED
                'bill_no' => $validatedData['bill_no'] ?? $sale->bill_no, // ✅ ADDED
                'bill_printed' => $validatedData['bill_printed'] ?? $sale->bill_printed, // ✅ ADDED
                'updated' => 'Y',
                'BillChangedOn' => now(),
            ]);

            $this->updateGrnRemainingStock($validatedData['code']);

            // Save updated version as adjustment if needed
            if ($sale->bill_printed === 'Y') {
                $newData = $sale->fresh();
                Salesadjustment::create([
                    'customer_code' => $newData->customer_code,
                    'supplier_code' => $newData->supplier_code ?? null,
                    'code' => $newData->code,
                    'item_code' => $newData->item_code,
                    'item_name' => $newData->item_name,
                    'weight' => $newData->weight,
                    'price_per_kg' => $newData->price_per_kg,
                    'pack_due' => $newData->pack_due ?? 0, // ✅ ADDED
                    'total' => $newData->total,
                    'packs' => $newData->packs,
                    'bill_no' => $newData->bill_no,
                    'user_id' => 'c11',
                    'type' => 'updated',
                    'original_created_at' => $newData->created_at,
                    'original_updated_at' => $newData->updated_at,
                    'Date' => $formattedDate,
                ]);
            }

            return response()->json([
                'success' => true,
                'sale' => $sale->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update sales record: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Sale $sale)
    {
        try {
            // Get the setting date value
            $settingDate = Setting::value('value');
            $formattedDate = Carbon::parse($settingDate)->format('Y-m-d');

            if ($sale->bill_printed === 'Y') {
                // Always create an "original" record
                Salesadjustment::create([
                    'customer_code' => $sale->customer_code,
                    'supplier_code' => $sale->supplier_code,
                    'code' => $sale->code,
                    'item_code' => $sale->item_code,
                    'item_name' => $sale->item_name,
                    'weight' => $sale->weight,
                    'price_per_kg' => $sale->price_per_kg,
                    'total' => $sale->total,
                    'packs' => $sale->packs,
                    'bill_no' => $sale->bill_no,
                    'type' => 'original',
                    'original_created_at' => Carbon::parse($sale->Date)
                        ->setTimeFrom(Carbon::parse($sale->created_at))
                        ->format('Y-m-d H:i:s'),

                    'Date' => $formattedDate, // ✅ store setting date
                ]);

                // Always create a "deleted" record
                Salesadjustment::create([
                    'customer_code' => $sale->customer_code,
                    'supplier_code' => $sale->supplier_code,
                    'code' => $sale->code,
                    'item_code' => $sale->item_code,
                    'item_name' => $sale->item_name,
                    'weight' => $sale->weight,
                    'price_per_kg' => $sale->price_per_kg,
                    'total' => $sale->total,
                    'packs' => $sale->packs,
                    'bill_no' => $sale->bill_no,
                    'type' => 'deleted',
                    'original_created_at' => $sale->created_at,
                    'Date' => $formattedDate, // ✅ store setting date
                ]);
            }

            // Delete and update GRN stock
            $saleCode = $sale->code;
            $sale->delete();
            $this->updateGrnRemainingStock($saleCode);

            return response()->json([
                'success' => true,
                'message' => 'Sales record deleted successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting sale: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the sale.'
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

}