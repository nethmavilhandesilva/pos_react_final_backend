<?php

namespace App\Http\Controllers;

use App\Mail\DayEndWeightReportMail;
use App\Models\Bank;
use App\Models\Debtor;
use App\Models\GrnEntry;
use App\Models\Supplier;
use App\Models\Sale;
use App\Models\Item;
use App\Models\Customer;
use App\Models\SupplierLoan;
use App\Models\SupplierLoanHistory;
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
use Illuminate\Support\Str;
use Twilio\Rest\Client;
use TextLK\SMS\TextLKSMSMessage;
use Illuminate\Support\Facades\Mail;
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
            $bagWeightPerUnit = (float) ($item->bag_real_price ?? 0);
            $numPacks = (int) $validated['packs'];
            $pricePerKg = (float) $validated['price_per_kg'];

            // Total Bag Weight to subtract
            $totalBagWeight = $bagWeightPerUnit * $numPacks;

            // Net Weight for this specific entry
            $incomingNetWeight = (float) $validated['weight'] - $totalBagWeight;

            // Recalculate Total based on Net Weight (Price * Net Weight)
            // If price is 0 (unpriced items), total remains 0.
            $recalculatedIncomingTotal = $incomingNetWeight * $pricePerKg;
            // -------------------------

            $billPrinted = $validated['bill_printed'] ?? null;
            $currentEntry = [
                'time' => now('Asia/Colombo')->format('h:i A'),
                'weight' => (float) $validated['weight'],
                'packs' => $numPacks
            ];

            $shouldCheckForUpdate =
                ($billPrinted === null || $billPrinted === 'N') &&
                $pricePerKg == 0;

            if ($shouldCheckForUpdate) {
                $existingSale = Sale::where('customer_code', strtoupper($validated['customer_code']))
                    ->where('item_code', $validated['item_code'])
                    ->where('supplier_code', $validated['supplier_code'])
                    ->where(function ($query) {
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
                    if (is_string($history)) {
                        $history = json_decode($history, true);
                    }
                    if (!is_array($history)) {
                        $history = [['time' => $existingSale->created_at->format('h:i A'), 'weight' => (float) $existingSale->weight, 'packs' => (int) $existingSale->packs]];
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
            if ($commissionRule) {
                $commissionAmount = $commissionRule->commission_amount;
            }

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
        Log::info('markAsPrinted Request Data:', $request->all());

        $salesIds = $request->input('sales_ids');
        if (empty($salesIds)) {
            return response()->json(['status' => 'error', 'message' => 'No sales IDs provided.'], 400);
        }

        try {

            // 1. ✅ HANDLE BILL NUMBER GENERATION
            $existingBillNo = Sale::whereIn('id', $salesIds)
                ->where('processed', 'Y')
                ->whereNotNull('bill_no')
                ->first()?->bill_no;

            $billNoToUse = $existingBillNo ?: $this->generateNewBillNumber();

            $salesRecords = null;

            // 2. ✅ UPDATE SALES RECORDS IN TRANSACTION
            DB::transaction(function () use ($salesIds, $billNoToUse, &$salesRecords) {

                $salesRecords = Sale::whereIn('id', $salesIds)->get();

                foreach ($salesRecords as $sale) {

                    if ($sale->bill_printed === 'Y') {
                        $sale->BillReprintAfterChanges = now();
                    }

                    $sale->bill_printed = 'Y';
                    $sale->processed = 'Y';
                    $sale->bill_no = $billNoToUse;
                    $sale->FirstTimeBillPrintedOn = $sale->FirstTimeBillPrintedOn ?? now();
                    $sale->save();
                }
            });

            // 3. ✅ GENERATE ITEM SUMMARY + BILL FINAL TOTAL
            $itemsForSummary = Sale::whereIn('id', $salesIds)->get();

            // ✅ Bill Final Total (total + CustomerPackCost)
            $billFinalTotal = $itemsForSummary->sum(function ($item) {
                return (float) $item->total + (float) $item->CustomerPackCost;
            });

            // ✅ Group summary
            $summaryString = $itemsForSummary->groupBy('item_code')->map(function ($group) {

                $itemName = $group->first()->item_name;
                $itemCode = $group->first()->item_code;
                $totalWeight = $group->sum('weight');
                $totalPacks = $group->sum('packs');

                return "{$itemName}({$itemCode})={$totalWeight}/{$totalPacks}";
            })->implode("\n");

            // ✅ Prepare sales data from actual database records
            $formattedSalesData = [];
            foreach ($salesRecords as $sale) {
                $formattedSalesData[] = [
                    'id' => $sale->id,
                    'item_name' => $sale->item_name,
                    'item_code' => $sale->item_code,
                    'weight' => (float) $sale->weight,
                    'price_per_kg' => (float) $sale->price_per_kg,
                    'packs' => (int) $sale->packs,
                    'supplier_code' => $sale->supplier_code,
                    'customer_code' => $sale->customer_code,
                    'total' => (float) $sale->total,
                    'SupplierTotal' => (float) $sale->SupplierTotal,
                    'SupplierPricePerKg' => (float) $sale->SupplierPricePerKg,
                    'CustomerPackCost' => (float) ($sale->CustomerPackCost ?? 0),
                    'commission_amount' => (float) ($sale->commission_amount ?? 0),
                ];
            }

            // 4. ✅ CREATE PUBLIC BILL LINK WITH FORMATTED SALES DATA
            $token = Str::random(40);
            $baseUrl = env('APP_FRONTEND_URL', 'https://goviraju.lk/sms_new_frontend_50500/');
            $publicUrl = rtrim($baseUrl, '/') . "/view-bill/" . $token;

            DB::table('bill_links')->insert([
                'token' => $token,
                'bill_no' => $billNoToUse,
                'sales_data' => json_encode($formattedSalesData), // ✅ Using formatted data from DB instead of request
                'loan_amount' => $request->loan_amount ?? 0,
                'customer_name' => $request->customer_name,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 5. ✅ RESOLVE TELEPHONE NUMBER
            $to = $request->telephone_no;

            $customerCode = $request->customer_code
                ?? $request->customer_name
                ?? ($salesRecords->first()->customer_code ?? null);

            if (empty($to) && !empty($customerCode)) {

                $customer = Customer::where('short_name', $customerCode)->first();

                if ($customer) {
                    $to = $customer->telephone_no;
                }
            }

            // 6. ✅ SEND SMS VIA TEXT.LK
            if (!empty($to)) {

                try {

                    // ✅ Clean number: remove +, spaces, dashes
                    $to = preg_replace('/[^0-9]/', '', $to);

                    // ✅ Convert 07XXXXXXXX -> 947XXXXXXXX
                    if (str_starts_with($to, '0')) {
                        $to = '94' . substr($to, 1);
                    }

                    // ✅ UPDATED MESSAGE BODY WITH BILL FINAL TOTAL
                    $messageBody =
                        "Customer Bill,\n" .
                        "Hello {$customerCode},\n" .
                        "Bill #{$billNoToUse} Summary:\n" .
                        "{$summaryString}\n" .
                        "Bill Final Total: " . number_format($billFinalTotal, 2) . "\n" .
                        "View: {$publicUrl}";

                    $textLKSMS = new TextLKSMSMessage();

                    $result = $textLKSMS->recipient($to)
                        ->message($messageBody)
                        ->senderId(env('TEXTLK_SENDER_ID', 'TextLKDemo'))
                        ->apiKey(env('TEXTLK_API_KEY'))
                        ->send();

                    if ($result) {
                        Log::info("Text.lk SMS sent successfully to: " . $to);
                    } else {
                        Log::error("Text.lk SMS failed to send to: " . $to);
                    }

                } catch (\Exception $e) {

                    Log::error("=== TEXT.LK SMS SENDING FAILED ===");
                    Log::error("Target Number: " . $to);
                    Log::error("Error Message: " . $e->getMessage());
                    Log::error("=================================");
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Sales processed and SMS sent via Text.lk!',
                'bill_no' => $billNoToUse,
                'bill_link' => $publicUrl
            ]);

        } catch (\Exception $e) {

            Log::error('markAsPrinted Failed:', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update records.'
            ], 500);
        }
    }
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
        // --- 1. Validation ---
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
            'update_related_price' => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        $affectedSales = [];

        // ⭐ Capture original data BEFORE any updates
        $originalData = $sale->toArray();

        try {
            $settingDate = Setting::value('value');
            $formattedDate = \Carbon\Carbon::parse($settingDate)->format('Y-m-d');

            $newPricePerKg = $validatedData['price_per_kg'];
            $commissionAmount = 0.00;

            // --- 2. Fetch Fresh Item Data ---
            $item = Item::where('no', $validatedData['item_code'])->first();
            if (!$item) {
                throw new \Exception('Item not found for calculation.');
            }

            $newBagPrice = (float) ($item->pack_cost ?? 0);

            // --- 3. Commission Rule Logic ---
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

            // --- 4. Main Record Calculations ---
            $supplierPricePerKg = abs($newPricePerKg - $commissionAmount);
            $newSupplierTotal = $validatedData['weight'] * $supplierPricePerKg;
            $newTotal = ($validatedData['weight'] * $newPricePerKg) + ($validatedData['packs'] * $newBagPrice);
            $newProfit = $newTotal - $newSupplierTotal;

            // --- 5. Track Original for Adjustment Logs (Only if Printed) ---
            if ($originalData['bill_printed'] === 'Y') {
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

            // --- 6. Update Main Sale Record ---
            $sale->update([
                'customer_code' => $validatedData['customer_code'],
                'customer_name' => $validatedData['customer_name'] ?? $sale->customer_name,
                'code' => $validatedData['item_code'],
                'supplier_code' => $validatedData['supplier_code'] ?? $sale->supplier_code,
                'item_code' => $validatedData['item_code'],
                'item_name' => $validatedData['item_name'],
                'weight' => $validatedData['weight'],
                'packs' => $validatedData['packs'],
                'price_per_kg' => $newPricePerKg,
                'commission_amount' => $commissionAmount,
                'SupplierPricePerKg' => $supplierPricePerKg,
                'SupplierTotal' => $newSupplierTotal,
                'pack_due' => $newBagPrice,
                'CustomerPackLabour' => $newBagPrice,
                'CustomerPackCost' => $newBagPrice,
                'total' => $newTotal,
                'profit' => $newProfit,
                'given_amount' => $validatedData['given_amount'] ?? $sale->given_amount,
                'bill_no' => $validatedData['bill_no'] ?? $sale->bill_no,
                'bill_printed' => $validatedData['bill_printed'] ?? $sale->bill_printed,
                'updated' => 'Y',
                'BillChangedOn' => now(),
            ]);

            // --- 7. Bulk Update Logic ---
            if ($request->input('update_related_price') === true) {
                $customerCode = $validatedData['customer_code'];
                $itemCode = $validatedData['item_code'];
                $supplierCode = $validatedData['supplier_code'] ?? $sale->supplier_code;

                $updateQuery = Sale::where('customer_code', $customerCode)
                    ->where('item_code', $itemCode)
                    ->where('supplier_code', $supplierCode)
                    ->where('id', '!=', $sale->id);

                if ($sale->bill_printed === 'Y' && $sale->bill_no) {
                    $updateQuery->where('bill_printed', 'Y')->where('bill_no', $sale->bill_no);
                } else {
                    $updateQuery->where(function ($query) {
                        $query->where('bill_printed', 'N')->orWhereNull('bill_printed');
                    });
                }

                $updateQuery->update([
                    'item_code' => $itemCode,
                    'item_name' => $validatedData['item_name'],
                    'price_per_kg' => $newPricePerKg,
                    'commission_amount' => $commissionAmount,
                    'SupplierPricePerKg' => $supplierPricePerKg,
                    'pack_due' => $newBagPrice,
                    'CustomerPackLabour' => $newBagPrice,
                    'CustomerPackCost' => $newBagPrice,
                    'total' => DB::raw("weight * $newPricePerKg + packs * $newBagPrice"),
                    'SupplierTotal' => DB::raw("weight * $supplierPricePerKg"),
                    'profit' => DB::raw("(weight * $newPricePerKg + packs * $newBagPrice) - (weight * $supplierPricePerKg)"),
                    'updated' => 'Y',
                ]);

                $affectedSales = Sale::where('customer_code', $customerCode)
                    ->where('item_code', $itemCode)
                    ->where('supplier_code', $supplierCode);

                if ($sale->bill_printed === 'Y' && $sale->bill_no) {
                    $affectedSales->where('bill_printed', 'Y')->where('bill_no', $sale->bill_no);
                } else {
                    $affectedSales->where(function ($query) {
                        $query->where('bill_printed', 'N')->orWhereNull('bill_printed');
                    });
                }
                $affectedSales = $affectedSales->get();
            } else {
                $affectedSales = collect([$sale->fresh()]);
            }

            // --- 8. Finalize Adjustments & Stock ---
            $this->updateGrnRemainingStock($validatedData['item_code']);

            if ($originalData['bill_printed'] === 'Y') {
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
            return response()->json(['success' => true, 'sales' => $affectedSales->toArray()]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sales record: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Sale $sale)
    {
        try {
            // 1. Get setting date safely
            $settingDate = Setting::value('value') ?? now();
            $formattedDate = Carbon::parse($settingDate)->format('Y-m-d');

            // Check if the bill was printed to handle adjustments and SMS
            if ($sale->bill_printed === 'Y') {

                // --- A. Log to Salesadjustment Table ---
                $adjustmentData = [
                    'customer_code' => $sale->customer_code,
                    'supplier_code' => $sale->supplier_code,
                    'code' => $sale->item_code,
                    'item_code' => $sale->item_code,
                    'item_name' => $sale->item_name,
                    'weight' => $sale->weight,
                    'price_per_kg' => $sale->price_per_kg,
                    'total' => $sale->total,
                    'packs' => $sale->packs,
                    'bill_no' => $sale->bill_no,
                    'original_created_at' => $sale->created_at,
                    'Date' => $formattedDate,
                ];

                Salesadjustment::create($adjustmentData + ['type' => 'original']);
                Salesadjustment::create($adjustmentData + ['type' => 'deleted']);

            }

            // 2. Perform actual deletion and update stock
            $saleCode = $sale->code;
            $sale->delete();
            $this->updateGrnRemainingStock($saleCode);

            return response()->json([
                'success' => true,
                'message' => 'Sales record deleted successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting sale', [
                'sale_id' => $sale->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the sale.',
                'error' => $e->getMessage(),
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
            // Validate that credit_transaction is either Y or N
            'credit_transaction' => 'sometimes|string|in:Y,N',
        ]);

        // 🔹 Update the sale with both the amount and the credit flag
        $sale->update([
            'given_amount' => $validated['given_amount'],
            // Fallback to 'N' if the frontend doesn't send it for some reason
            'credit_transaction' => $request->get('credit_transaction', 'N'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Given amount and credit status updated successfully',
            'sale' => $sale->fresh() // .fresh() ensures you return the latest DB state
        ]);
    }
    public function processDay(Request $request)
    {
        $recipientEmails = ['nethmavilhan@gmail.com', 'thrcorner@gmail.com'];

        // Use selected date from frontend
        $processLogDate = $request->input('date') ?? now()->toDateString();
        $startDate = $request->input('start_date', $processLogDate);
        $endDate = $request->input('end_date', $processLogDate);

        // Get last stored date from settings.value (ONLY for adjustments)
        $lastSetting = Setting::where('key', 'last_day_started_date')->first();
        $adjustmentDate = $lastSetting ? $lastSetting->value : $processLogDate;

        // 1️⃣ Fetch Current Sales
        $allSales = Sale::where('bill_printed', 'Y')
            ->whereNotNull('bill_no')
            ->where('bill_no', '!=', '')
            ->get();

        $totalRecordsToMove = $allSales->count();

        if ($totalRecordsToMove === 0) {
            return response()->json([
                'success' => false,
                'message' => "Sales table is empty."
            ], 404);
        }

        // 2️⃣ Fetch Adjustments using PREVIOUS stored date
        $adjustments = Salesadjustment::whereDate('Date', $adjustmentDate)
            ->orderBy('created_at', 'desc')
            ->get();

        Supplier::whereDate('advance_created_date', $processLogDate)
            ->update([
                'advance_amount' => 0,
                'advance_created_date' => null
            ]);

        // 3️⃣ Fetch Supplier Loans to move
        $supplierLoans = SupplierLoan::all();

        // 3️⃣ Summarize Sales
        $summarizedSales = Sale::selectRaw("
        item_code, 
        item_name,
        SUM(packs) AS packs,
        SUM(weight) AS weight,
        SUM(total) AS total
    ")
            ->where('bill_printed', 'Y')
            ->whereNotNull('bill_no')
            ->groupBy('item_code', 'item_name')
            ->orderBy('item_name', 'asc')
            ->get();

        // Add pack_due
        $summarizedSales = $summarizedSales->map(function ($sale) {
            $item = Item::where('no', $sale->item_code)->first();
            $sale->pack_cost = $item ? $item->pack_due : 0;
            return $sale;
        });

        // 4️⃣ Group Sales by Customer → Bill
        $groupedSales = $allSales->groupBy('customer_code')->map(function ($customerSales) {
            return $customerSales->groupBy('bill_no');
        });

        // 5️⃣ Supplier Report
        $supplierReport = Sale::select([
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
            'Date',
            'created_at'
        ])
            ->where('bill_printed', 'Y')
            ->whereNotNull('supplier_code')
            ->orderBy('Date', 'desc')
            ->get()
            ->groupBy('supplier_code');

        // Totals for sales report
        $totals = $summarizedSales->reduce(function ($acc, $sale) {
            $acc['total_weight'] += (float) $sale->weight;
            $acc['total_net_total'] += ((float) $sale->total - ((float) $sale->packs * (float) $sale->pack_cost));
            return $acc;
        }, [
            'total_weight' => 0.0,
            'total_net_total' => 0.0
        ]);

        // ========== PAYMENT COLLECTION REPORT DATA ==========
        // Group by customer_code and bill_no for payment report
        $groupedBills = [];

        foreach ($allSales as $sale) {
            $key = ($sale->customer_code ?: 'Unknown') . '/' . ($sale->bill_no ?: 'N/A');

            if (!isset($groupedBills[$key])) {
                $groupedBills[$key] = [
                    'customer_bill_no' => $key,
                    'cash_collection' => 0,
                    'cheques_collection' => 0,
                    'bag_box_total' => 0,
                    'bag_total' => 0,
                    'box_total' => 0,
                    'banks_transfer' => 0,
                    'bad_debt' => 0
                ];
            }

            $paymentType = $sale->payment_adjustment_type;
            $givenAmount = floatval($sale->given_amount ?? 0);
            $adjustmentAmount = floatval($sale->adjustment_amount ?? 0);

            // Cash payments
            if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                $groupedBills[$key]['cash_collection'] += $givenAmount;
            }
            // Cheque payments
            elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                $groupedBills[$key]['cheques_collection'] += $givenAmount;
            }
            // Bank Transfer payments
            elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                $groupedBills[$key]['banks_transfer'] += $givenAmount;
            }
            // Bag to Box adjustments
            elseif ($paymentType === 'bag_to_box') {
                $groupedBills[$key]['bag_box_total'] += $adjustmentAmount;
                $groupedBills[$key]['bag_total'] += floatval($sale->bag_count ?? 0);
                $groupedBills[$key]['box_total'] += floatval($sale->box_count ?? 0);
            }
            // Bill to Bill adjustments - treat as Bag Box Total
            elseif ($paymentType === 'bill_to_bill') {
                $groupedBills[$key]['bag_box_total'] += $adjustmentAmount;
            }
            // Bad Debt adjustments
            elseif ($paymentType === 'bad_debt') {
                $groupedBills[$key]['bad_debt'] += $adjustmentAmount;
            }
        }

        // Calculate payment totals
        $paymentTotals = [
            'cash_collection' => 0,
            'cheques_collection' => 0,
            'bag_box_total' => 0,
            'bag_total' => 0,
            'box_total' => 0,
            'banks_transfer' => 0,
            'bad_debt' => 0
        ];

        $paymentData = [];
        foreach ($groupedBills as $bill) {
            $paymentData[] = $bill;
            $paymentTotals['cash_collection'] += $bill['cash_collection'];
            $paymentTotals['cheques_collection'] += $bill['cheques_collection'];
            $paymentTotals['bag_box_total'] += $bill['bag_box_total'];
            $paymentTotals['bag_total'] += $bill['bag_total'];
            $paymentTotals['box_total'] += $bill['box_total'];
            $paymentTotals['banks_transfer'] += $bill['banks_transfer'];
            $paymentTotals['bad_debt'] += $bill['bad_debt'];
        }

        // Email Data
        $reportData = [
            'processLogDate' => $processLogDate,
            'adjustmentDate' => $adjustmentDate,
            'totalRecordsMoved' => $totalRecordsToMove,
            'sales' => $summarizedSales,
            'raw_sales' => $allSales,
            'grouped_sales' => $groupedSales,
            'adjustments' => $adjustments,
            'supplier_report' => $supplierReport,
            'totals' => $totals,
            // Payment collection data
            'payment_data' => $paymentData,
            'payment_totals' => $paymentTotals,
            // Supplier loans data
            'supplier_loans_moved' => $supplierLoans->count()
        ];

        DB::beginTransaction();

        try {
            // Move Sales → SalesHistory
            $historyData = [];
            $allowedColumns = (new Sale())->getFillable();

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

            SalesHistory::insert($historyData);
            Sale::query()->delete();

            // Move Supplier Loans → SupplierLoanHistory
            if ($supplierLoans->count() > 0) {
                $loanHistoryData = [];
                $loanAllowedColumns = (new SupplierLoan())->getFillable();

                foreach ($supplierLoans as $loan) {
                    $loanData = $loan->only($loanAllowedColumns);
                    unset($loanData['id']); // Remove the ID to let history table auto-generate its own

                    // Handle any array/json fields if needed
                    foreach ($loanData as $key => $value) {
                        if (is_array($value)) {
                            $loanData[$key] = json_encode($value);
                        }
                    }

                    $loanHistoryData[] = $loanData;
                }

                SupplierLoanHistory::insert($loanHistoryData);
                SupplierLoan::query()->delete();
            }

            // SAVE SELECTED DATE TO SETTINGS
            Setting::updateOrCreate(
                ['key' => 'last_day_started_date'],
                ['value' => $processLogDate]
            );

            DB::commit();

            // Send Email to multiple recipients
            try {
                foreach ($recipientEmails as $recipient) {
                    Mail::to($recipient)->send(new DayEndWeightReportMail($reportData));
                }
                Log::info("Day end report sent successfully to: " . implode(', ', $recipientEmails));
            } catch (\Exception $e) {
                Log::error("Mail Error: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Process complete. Reports emailed to " . count($recipientEmails) . " recipients. Moved " . $supplierLoans->count() . " supplier loan records.",
                'adjustment_date_used' => $adjustmentDate,
                'saved_date' => $processLogDate,
                'payment_totals' => $paymentTotals,
                'supplier_loans_moved' => $supplierLoans->count()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function viewPublicBill($token)
    {
        $bill = DB::table('bill_links')->where('token', $token)->first();

        if (!$bill) {
            return response()->json(['message' => 'Bill not found'], 404);
        }

        return response()->json($bill);
    }
    // Add this method to your SalesEntryController.php
    public function getAllSales2()
    {
        try {
            $currentUser = auth()->user();

            $query = Sale::query();

            // Filter by user if not admin
            if ($currentUser && $currentUser->role === 'User') {
                $query->where('UniqueCode', $currentUser->user_id);
            }

            $sales = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'sales' => $sales
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch all sales: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sales data'
            ], 500);
        }
    }

    public function updateGivenAmountApplied(Request $request)
    {
        \Log::info('updateGivenAmountApplied called', $request->all());

        $request->validate([
            'bill_no' => 'required|string',
            'given_amount' => 'nullable|numeric|min:0',
            'given_amount_applied' => 'required|in:Y,N',
            'credit_transaction' => 'nullable|in:Y,N',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'cheq_date' => 'nullable|date',
            'cheq_no' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|integer|exists:banks,id',
            'bank_name' => 'nullable|string|max:255',
            'transfer_reference_no' => 'nullable|string|max:255',
            'transfer_date' => 'nullable|date',
            'transfer_notes' => 'nullable|string',
            'bag_count' => 'nullable|integer',
            'box_count' => 'nullable|integer',
            'bag_value' => 'nullable|numeric',
            'box_value' => 'nullable|numeric',
            'target_customer_code' => 'nullable|string',
            'target_bill_no' => 'nullable|string',
            'target_bill_value' => 'nullable|numeric',
            'target_supplier_code' => 'nullable|string',
            'target_supplier_bill_no' => 'nullable|string',
            'target_supplier_bill_value' => 'nullable|numeric',
            'bad_debt_name' => 'nullable|string',
            'bad_debt_amount' => 'nullable|numeric'
        ]);

        try {
            DB::beginTransaction();

            // Get the current sale record
            $sale = DB::table('sales')
                ->where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->first();

            if (!$sale) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No sales found with this bill_no',
                    'bill_no' => $request->bill_no
                ], 404);
            }

            // Get existing payment history or initialize empty array
            $paymentHistory = [];
            if ($sale->payment_history) {
                // Check if it's already an array or needs decoding
                if (is_string($sale->payment_history)) {
                    $paymentHistory = json_decode($sale->payment_history, true) ?: [];
                } elseif (is_array($sale->payment_history)) {
                    $paymentHistory = $sale->payment_history;
                }
            }

            // Calculate new running total
            $previousTotal = (float) ($sale->given_amount ?? 0);
            $paymentAmount = (float) $request->payment_amount;
            $newTotal = $previousTotal + $paymentAmount;
            $isFullyPaid = $request->given_amount_applied === 'Y';

            // Create payment record
            $paymentRecord = [
                'id' => uniqid(),
                'date' => now()->toDateTimeString(),
                'amount' => $paymentAmount,
                'method' => $request->payment_method,
                'running_balance' => $newTotal,
                'is_fully_paid' => $isFullyPaid,
                'reference' => null,
                'details' => []
            ];

            // Add specific payment details based on method
            if ($request->payment_method === 'Cheque') {
                $paymentRecord['reference'] = $request->cheq_no;
                $paymentRecord['details'] = [
                    'cheq_no' => $request->cheq_no,
                    'cheq_date' => $request->cheq_date,
                    'bank_account_id' => $request->bank_account_id,
                    'bank_name' => $request->bank_name
                ];
            } elseif ($request->payment_method === 'Bank Transfer') {
                $paymentRecord['reference'] = $request->transfer_reference_no;
                $paymentRecord['details'] = [
                    'transfer_reference_no' => $request->transfer_reference_no,
                    'transfer_date' => $request->transfer_date,
                    'transfer_notes' => $request->transfer_notes,
                    'bank_account_id' => $request->bank_account_id,
                    'bank_name' => $request->bank_name
                ];
            } elseif ($request->payment_method === 'bag_to_box') {
                $adjustmentAmount = ((int) $request->bag_count * (float) $request->bag_value) - ((int) $request->box_count * (float) $request->box_value);
                $paymentRecord['reference'] = "{$request->bag_count} bags to {$request->box_count} boxes";
                $paymentRecord['details'] = [
                    'bag_count' => (int) $request->bag_count,
                    'box_count' => (int) $request->box_count,
                    'bag_value' => (float) $request->bag_value,
                    'box_value' => (float) $request->box_value,
                    'adjustment_amount' => $adjustmentAmount
                ];
            } elseif ($request->payment_method === 'bill_to_bill') {
                $paymentRecord['reference'] = $request->target_bill_no;
                $paymentRecord['details'] = [
                    'target_customer_code' => $request->target_customer_code,
                    'target_bill_no' => $request->target_bill_no,
                    'target_bill_value' => (float) $request->target_bill_value,
                    'target_supplier_code' => $request->target_supplier_code,
                    'target_supplier_bill_no' => $request->target_supplier_bill_no,
                    'target_supplier_bill_value' => (float) $request->target_supplier_bill_value
                ];
            } elseif ($request->payment_method === 'bad_debt') {
                $paymentRecord['reference'] = $request->bad_debt_name;
                $paymentRecord['details'] = [
                    'bad_debt_name' => $request->bad_debt_name,
                    'bad_debt_amount' => (float) $request->bad_debt_amount
                ];
            } else { // Cash
                $paymentRecord['reference'] = 'Cash';
                $paymentRecord['details'] = [];
            }

            // Add to payment history array
            $paymentHistory[] = $paymentRecord;

            // Build update data for sales table
            $updateData = [
                'given_amount' => $newTotal,
                'given_amount_applied' => $request->given_amount_applied,
                'credit_transaction' => $request->credit_transaction ?? 'N',
                'payment_adjustment_type' => $request->payment_method,
                'adjustment_amount' => $paymentAmount,
                'payment_history' => json_encode($paymentHistory),
                'updated_at' => now()
            ];

            // Add latest payment details to main columns for quick reference (optional)
            if ($request->payment_method === 'Bank Transfer') {
                if ($request->has('bank_account_id') && $request->bank_account_id) {
                    $updateData['bank_account_id'] = $request->bank_account_id;
                    $bank = Bank::find($request->bank_account_id);
                    if ($bank) {
                        $updateData['bank_name'] = $bank->bank_name;
                    }
                }
                $updateData['transfer_reference_no'] = $request->transfer_reference_no;
                $updateData['transfer_date'] = $request->transfer_date;
                $updateData['transfer_notes'] = $request->transfer_notes;
                $updateData['cheq_date'] = null;
                $updateData['cheq_no'] = null;
            } elseif ($request->payment_method === 'Cheque') {
                $updateData['cheq_date'] = $request->cheq_date;
                $updateData['cheq_no'] = $request->cheq_no;
                if ($request->has('bank_account_id') && $request->bank_account_id) {
                    $updateData['bank_account_id'] = $request->bank_account_id;
                    $bank = Bank::find($request->bank_account_id);
                    if ($bank) {
                        $updateData['bank_name'] = $bank->bank_name;
                    }
                }
                $updateData['transfer_reference_no'] = null;
                $updateData['transfer_date'] = null;
                $updateData['transfer_notes'] = null;
            } elseif ($request->payment_method === 'bag_to_box') {
                $updateData['bag_count'] = $request->bag_count;
                $updateData['box_count'] = $request->box_count;
                $updateData['bag_value'] = $request->bag_value;
                $updateData['box_value'] = $request->box_value;
            } elseif ($request->payment_method === 'bill_to_bill') {
                $updateData['target_customer_code'] = $request->target_customer_code;
                $updateData['target_bill_no'] = $request->target_bill_no;
                $updateData['target_bill_value'] = $request->target_bill_value;
                $updateData['target_supplier_code'] = $request->target_supplier_code;
                $updateData['target_supplier_bill_no'] = $request->target_supplier_bill_no;
                $updateData['target_supplier_bill_value'] = $request->target_supplier_bill_value;
            } elseif ($request->payment_method === 'bad_debt') {
                $updateData['bad_debt_name'] = $request->bad_debt_name;
                $updateData['bad_debt_amount'] = $request->bad_debt_amount;
            } else { // Cash
                $updateData['cheq_date'] = null;
                $updateData['cheq_no'] = null;
                $updateData['bank_account_id'] = null;
                $updateData['transfer_reference_no'] = null;
                $updateData['transfer_date'] = null;
                $updateData['transfer_notes'] = null;
            }

            // Update the sales record
            $updated = DB::table('sales')
                ->where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->update($updateData);

            if ($updated === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update sales record',
                    'bill_no' => $request->bill_no
                ], 404);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully updated with {$request->payment_method} payment",
                'data' => [
                    'bill_no' => $request->bill_no,
                    'given_amount' => $newTotal,
                    'given_amount_applied' => $request->given_amount_applied,
                    'payment_method' => $request->payment_method,
                    'payment_history' => $paymentHistory,
                    'affected_rows' => $updated
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating given amount applied:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update single sale record
     */
    public function updateSaleGivenAmount(Request $request, $saleId)
    {
        $request->validate([
            'given_amount' => 'nullable|numeric|min:0',
            'given_amount_applied' => 'nullable|in:Y,N',
            'credit_transaction' => 'nullable|in:Y,N',
            'cheq_date' => 'nullable|date',
            'cheq_no' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'transfer_reference_no' => 'nullable|string|max:255',
            'transfer_date' => 'nullable|date',
            'transfer_notes' => 'nullable|string'
        ]);

        try {
            $sale = Sale::findOrFail($saleId);

            $sale->given_amount = $request->given_amount ?? $sale->given_amount;
            $sale->given_amount_applied = $request->given_amount_applied ?? $sale->given_amount_applied;
            $sale->credit_transaction = $request->credit_transaction ?? $sale->credit_transaction;

            // Update cheque details if provided
            if ($request->has('cheq_date')) {
                $sale->cheq_date = $request->cheq_date;
            }
            if ($request->has('cheq_no')) {
                $sale->cheq_no = $request->cheq_no;
            }
            if ($request->has('bank_name')) {
                $sale->bank_name = $request->bank_name;
            }

            // Update bank transfer details if provided
            if ($request->has('transfer_reference_no')) {
                $sale->transfer_reference_no = $request->transfer_reference_no;
            }
            if ($request->has('transfer_date')) {
                $sale->transfer_date = $request->transfer_date;
            }
            if ($request->has('transfer_notes')) {
                $sale->transfer_notes = $request->transfer_notes;
            }

            // Determine payment type based on provided fields
            if ($request->has('transfer_reference_no') && !empty($request->transfer_reference_no)) {
                $sale->payment_adjustment_type = 'Bank Transfer';
            } elseif ($request->has('cheq_no') && !empty($request->cheq_no)) {
                $sale->payment_adjustment_type = 'Cheque';
            } else {
                $sale->payment_adjustment_type = 'Cash';
            }

            $sale->save();

            return response()->json([
                'success' => true,
                'message' => 'Sale updated successfully',
                'data' => $sale
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found or update failed'
            ], 404);
        }
    }
    public function getBanks()
    {
        try {
            $banks = Bank::all(['id', 'bank_name', 'branch', 'account_no']);
            return response()->json([
                'success' => true,
                'data' => $banks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch banks'
            ], 500);
        }
    }

    /**
     * Get pending bills for bill-to-bill adjustment (Customer)
     */
    public function getPendingCustomerBills(Request $request)
    {
        try {
            $request->validate([
                'customer_code' => 'required|string'
            ]);

            $customerCode = $request->customer_code;

            // Fix: Use DB::raw in having clause and properly reference columns
            $pendingBills = DB::table('sales')
                ->select(
                    'bill_no',
                    'customer_code',
                    DB::raw('SUM(total + (packs * CustomerPackCost)) as total_amount'),
                    DB::raw('MAX(given_amount) as given_amount')
                )
                ->where('customer_code', $customerCode)
                ->where('bill_printed', 'Y')
                ->where('given_amount_applied', 'N')
                ->groupBy('bill_no', 'customer_code')
                ->havingRaw('SUM(total + (packs * CustomerPackCost)) > COALESCE(MAX(given_amount), 0)')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pendingBills
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch pending customer bills: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending bills: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending farmer bills (Supplier) - Fixed version
     */
    public function getPendingFarmerBills(Request $request)
    {
        try {
            $request->validate([
                'supplier_code' => 'required|string'
            ]);

            $supplierCode = $request->supplier_code;

            $pendingBills = DB::table('sales')
                ->select(
                    'supplier_bill_no',
                    'supplier_code',
                    DB::raw('SUM(SupplierTotal) as total_amount')
                )
                ->where('supplier_code', $supplierCode)
                ->where('supplier_bill_printed', 'Y')
                ->where('supplier_paid_status', 'N')
                ->whereNotNull('supplier_bill_no')
                ->groupBy('supplier_bill_no', 'supplier_code')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pendingBills
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch pending farmer bills: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending farmer bills: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply payment adjustment and update remaining amount
     */
    public function applyPaymentAdjustment(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
            'adjustment_type' => 'required|in:bag_to_box,bill_to_bill,bad_debt',
            'original_bill_total' => 'required|numeric'
        ]);

        try {
            DB::beginTransaction();

            // Get the original bill total
            $totalBillAmount = Sale::where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->select(DB::raw('SUM(total + (packs * CustomerPackCost)) as total'))
                ->value('total');

            $adjustmentAmount = 0;
            $updateData = [
                'payment_adjustment_type' => $request->adjustment_type,
                'updated_at' => now()
            ];

            if ($request->adjustment_type === 'bag_to_box') {
                $request->validate([
                    'bag_count' => 'required|integer|min:0',
                    'box_count' => 'required|integer|min:0',
                    'bag_value' => 'required|numeric|min:0',
                    'box_value' => 'required|numeric|min:0'
                ]);

                $totalBagValue = $request->bag_count * $request->bag_value;
                $totalBoxValue = $request->box_count * $request->box_value;
                $adjustmentAmount = $totalBagValue - $totalBoxValue;

                $updateData['bag_count'] = $request->bag_count;
                $updateData['box_count'] = $request->box_count;
                $updateData['bag_value'] = $request->bag_value;
                $updateData['box_value'] = $request->box_value;
                $updateData['adjustment_amount'] = $adjustmentAmount;
            }

            if ($request->adjustment_type === 'bill_to_bill') {
                $request->validate([
                    'customer_code' => 'required|string',
                    'customer_bill_no' => 'required|string',
                    'customer_bill_value' => 'required|numeric|min:0'
                ]);

                $adjustmentAmount = $request->customer_bill_value;

                $updateData['target_customer_code'] = $request->customer_code;
                $updateData['target_bill_no'] = $request->customer_bill_no;
                $updateData['target_bill_value'] = $request->customer_bill_value;
                $updateData['adjustment_amount'] = $adjustmentAmount;

                // Update the target customer bill
                Sale::where('bill_no', $request->customer_bill_no)
                    ->where('bill_printed', 'Y')
                    ->update([
                        'given_amount' => DB::raw('COALESCE(given_amount, 0) + ' . $request->customer_bill_value),
                        'given_amount_applied' => DB::raw('CASE WHEN COALESCE(given_amount, 0) + ' . $request->customer_bill_value . ' >= total + (packs * CustomerPackCost) THEN "Y" ELSE "N" END'),
                        'updated_at' => now()
                    ]);
            }

            if ($request->adjustment_type === 'bad_debt') {
                $request->validate([
                    'bad_debt_name' => 'required|string',
                    'bad_debt_amount' => 'required|numeric|min:0'
                ]);

                $adjustmentAmount = $request->bad_debt_amount;
                $updateData['bad_debt_name'] = $request->bad_debt_name;
                $updateData['bad_debt_amount'] = $request->bad_debt_amount;
                $updateData['adjustment_amount'] = $adjustmentAmount;
            }

            // Update all sales in the bill with the adjustment
            Sale::where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->update($updateData);

            // Get current given amount and update
            $currentGiven = Sale::where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->value('given_amount');

            $newGivenAmount = ($currentGiven ?? 0) + $adjustmentAmount;

            Sale::where('bill_no', $request->bill_no)
                ->where('bill_printed', 'Y')
                ->update([
                    'given_amount' => $newGivenAmount,
                    'given_amount_applied' => $newGivenAmount >= $totalBillAmount ? 'Y' : 'N',
                    'credit_transaction' => $newGivenAmount >= $totalBillAmount ? 'N' : 'Y'
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment adjustment applied successfully',
                'data' => [
                    'adjustment_amount' => $adjustmentAmount,
                    'new_given_amount' => $newGivenAmount,
                    'remaining' => max(0, $totalBillAmount - $newGivenAmount)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment adjustment failed:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply adjustment: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get payment history for a specific bill
     */
    public function getPaymentHistory($billNo)
    {
        try {
            $sale = DB::table('sales')
                ->where('bill_no', $billNo)
                ->where('bill_printed', 'Y')
                ->first();

            if (!$sale) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bill not found'
                ], 404);
            }

            // Get payment history - handle both string and array cases
            $paymentHistory = [];
            if ($sale->payment_history) {
                if (is_string($sale->payment_history)) {
                    $paymentHistory = json_decode($sale->payment_history, true) ?: [];
                } elseif (is_array($sale->payment_history)) {
                    $paymentHistory = $sale->payment_history;
                }
            }

            // Calculate totals
            $totalPaid = (float) ($sale->given_amount ?? 0);
            $totalBill = 0;

            // Get total bill amount from sales table
            $totalBillRecords = DB::table('sales')
                ->where('bill_no', $billNo)
                ->where('bill_printed', 'Y')
                ->select(DB::raw('SUM(total + (packs * CustomerPackCost)) as total'))
                ->first();

            if ($totalBillRecords) {
                $totalBill = (float) ($totalBillRecords->total ?? 0);
            }

            $remaining = max(0, $totalBill - $totalPaid);

            // Format payments for display
            $formattedPayments = array_map(function ($payment) {
                return [
                    'date' => $payment['date'],
                    'amount' => (float) $payment['amount'],
                    'method' => $payment['method'],
                    'reference' => $payment['reference'] ?? null,
                    'running_balance' => $payment['running_balance'] ?? null,
                    'is_fully_paid' => $payment['is_fully_paid'] ?? false,
                    'details' => $payment['details'] ?? []
                ];
            }, $paymentHistory);

            return response()->json([
                'success' => true,
                'payments' => $formattedPayments,
                'total_paid' => $totalPaid,
                'total_bill' => $totalBill,
                'remaining' => $remaining
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch payment history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getPaymentCollectionReport(Request $request)
    {
        try {
            // Get date range filter if provided
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Determine which table(s) to query
            $hasDateRange = ($startDate || $endDate);

            $allSales = collect(); // Use collection to merge results

            if ($hasDateRange) {
                // If date range is selected, fetch from SalesHistory (archived data)
                $query = SalesHistory::where('bill_printed', 'Y')
                    ->whereNotNull('bill_no')
                    ->where('bill_no', '!=', '');

                // Apply date filter
                if ($startDate) {
                    $query->whereDate('Date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('Date', '<=', $endDate);
                }

                $allSales = $query->get();
            } else {
                // If no date range, fetch from Sales (current data)
                $query = Sale::where('bill_printed', 'Y')
                    ->whereNotNull('bill_no')
                    ->where('bill_no', '!=', '');

                $allSales = $query->get();
            }

            // If no data found, return empty report
            if ($allSales->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'totals' => [
                        'cash_collection' => 0,
                        'cheques_collection' => 0,
                        'bag_box_total' => 0,
                        'bag_total' => 0,
                        'box_total' => 0,
                        'banks_transfer' => 0,
                        'bad_debt' => 0
                    ],
                    'filters' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ],
                    'source_table' => $hasDateRange ? 'sales_history' : 'sales'
                ]);
            }

            // Group by customer_code and bill_no
            $groupedBills = [];

            foreach ($allSales as $sale) {
                $key = ($sale->customer_code ?? 'Unknown') . '/' . ($sale->bill_no ?? 'N/A');

                if (!isset($groupedBills[$key])) {
                    $groupedBills[$key] = [
                        'customer_code' => $sale->customer_code,
                        'bill_no' => $sale->bill_no,
                        'cash_collection' => 0,
                        'cheques_collection' => 0,
                        'bag_box_total' => 0,
                        'bag_total' => 0,
                        'box_total' => 0,
                        'banks_transfer' => 0,
                        'bad_debt' => 0,
                        'sales_records' => []
                    ];
                }

                // Determine payment type and add amounts
                $paymentType = $sale->payment_adjustment_type;
                $givenAmount = floatval($sale->given_amount ?? 0);
                $adjustmentAmount = floatval($sale->adjustment_amount ?? 0);

                // Cash payments
                if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                    $groupedBills[$key]['cash_collection'] += $givenAmount;
                }
                // Cheque payments
                elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                    $groupedBills[$key]['cheques_collection'] += $givenAmount;
                }
                // Bank Transfer payments
                elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                    $groupedBills[$key]['banks_transfer'] += $givenAmount;
                }
                // Bag to Box adjustments
                elseif ($paymentType === 'bag_to_box') {
                    $groupedBills[$key]['bag_box_total'] += $adjustmentAmount;
                    $groupedBills[$key]['bag_total'] += floatval($sale->bag_count ?? 0);
                    $groupedBills[$key]['box_total'] += floatval($sale->box_count ?? 0);
                }
                // Bill to Bill adjustments - treat as Bag Box Total
                elseif ($paymentType === 'bill_to_bill') {
                    $groupedBills[$key]['bag_box_total'] += $adjustmentAmount;
                }
                // Bad Debt adjustments
                elseif ($paymentType === 'bad_debt') {
                    $groupedBills[$key]['bad_debt'] += $adjustmentAmount;
                }

                $groupedBills[$key]['sales_records'][] = $sale;
            }

            // Calculate totals
            $totals = [
                'cash_collection' => 0,
                'cheques_collection' => 0,
                'bag_box_total' => 0,
                'bag_total' => 0,
                'box_total' => 0,
                'banks_transfer' => 0,
                'bad_debt' => 0
            ];

            $reportData = [];
            foreach ($groupedBills as $key => $bill) {
                $reportData[] = [
                    'customer_bill_no' => $key,
                    'cash_collection' => $bill['cash_collection'],
                    'cheques_collection' => $bill['cheques_collection'],
                    'bag_box_total' => $bill['bag_box_total'],
                    'bag_total' => $bill['bag_total'],
                    'box_total' => $bill['box_total'],
                    'banks_transfer' => $bill['banks_transfer'],
                    'bad_debt' => $bill['bad_debt']
                ];

                $totals['cash_collection'] += $bill['cash_collection'];
                $totals['cheques_collection'] += $bill['cheques_collection'];
                $totals['bag_box_total'] += $bill['bag_box_total'];
                $totals['bag_total'] += $bill['bag_total'];
                $totals['box_total'] += $bill['box_total'];
                $totals['banks_transfer'] += $bill['banks_transfer'];
                $totals['bad_debt'] += $bill['bad_debt'];
            }

            return response()->json([
                'success' => true,
                'data' => $reportData,
                'totals' => $totals,
                'filters' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'source_table' => $hasDateRange ? 'sales_history' : 'sales',
                'total_records' => $allSales->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed payment breakdown by customer
     */
    public function getPaymentBreakdown(Request $request)
    {
        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $hasDateRange = ($startDate || $endDate);

            if ($hasDateRange) {
                // If date range is selected, fetch from SalesHistory
                $query = SalesHistory::where('bill_printed', 'Y')
                    ->whereNotNull('bill_no')
                    ->select(
                        'customer_code',
                        'bill_no',
                        'payment_adjustment_type',
                        'given_amount',
                        'adjustment_amount',
                        'bag_count',
                        'box_count',
                        'bag_value',
                        'box_value',
                        'cheq_no',
                        'cheq_date',
                        'bank_name',
                        'transfer_reference_no',
                        'bad_debt_name',
                        'bad_debt_amount',
                        'Date'
                    );
            } else {
                // If no date range, fetch from Sales
                $query = Sale::where('bill_printed', 'Y')
                    ->whereNotNull('bill_no')
                    ->select(
                        'customer_code',
                        'bill_no',
                        'payment_adjustment_type',
                        'given_amount',
                        'adjustment_amount',
                        'bag_count',
                        'box_count',
                        'bag_value',
                        'box_value',
                        'cheq_no',
                        'cheq_date',
                        'bank_name',
                        'transfer_reference_no',
                        'bad_debt_name',
                        'bad_debt_amount',
                        'Date'
                    );
            }

            if ($startDate) {
                $query->whereDate('Date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('Date', '<=', $endDate);
            }

            $sales = $query->get();

            return response()->json([
                'success' => true,
                'data' => $sales,
                'source_table' => $hasDateRange ? 'sales_history' : 'sales',
                'total_records' => $sales->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment breakdown: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get comprehensive payment report with summaries
     */
    public function getPaymentReport(Request $request)
    {
        try {
            $period = $request->input('period', 'today'); // today, week, month, custom
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Determine date range based on period
            $today = Carbon::today();
            switch ($period) {
                case 'today':
                    $startDate = $today->format('Y-m-d');
                    $endDate = $today->format('Y-m-d');
                    break;
                case 'week':
                    $startDate = $today->copy()->startOfWeek()->format('Y-m-d');
                    $endDate = $today->copy()->endOfWeek()->format('Y-m-d');
                    break;
                case 'month':
                    $startDate = $today->copy()->startOfMonth()->format('Y-m-d');
                    $endDate = $today->copy()->endOfMonth()->format('Y-m-d');
                    break;
                case 'custom':
                    // Use provided dates
                    break;
            }

            // Query current sales table
            $query = Sale::where('bill_printed', 'Y')
                ->whereNotNull('bill_no');

            if ($startDate) {
                $query->whereDate('Date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('Date', '<=', $endDate);
            }

            $sales = $query->get();

            // Initialize report data
            $report = [
                'summary' => [
                    'cash_collection' => 0,
                    'cheque_collection' => 0,
                    'bank_transfer_collection' => 0,
                    'bag_to_box_total' => 0,
                    'bill_to_bill_total' => 0,
                    'bad_debt_total' => 0,
                    'total_given_amount' => 0,
                    'total_bill_amount' => 0,
                    'total_remaining' => 0,
                    'total_customers' => 0,
                    'total_bills' => 0,
                ],
                'breakdown_by_customer' => [],
                'breakdown_by_date' => [],
                'breakdown_by_payment_type' => [],
                'graph_data' => [
                    'labels' => [],
                    'cash' => [],
                    'cheque' => [],
                    'bank_transfer' => [],
                    'adjustments' => [],
                ]
            ];

            $customerSummary = [];
            $dateSummary = [];
            $paymentTypeSummary = [
                'Cash' => 0,
                'Cheque' => 0,
                'Bank Transfer' => 0,
                'bag_to_box' => 0,
                'bill_to_bill' => 0,
                'bad_debt' => 0,
            ];

            foreach ($sales as $sale) {
                $paymentType = $sale->payment_adjustment_type;
                $givenAmount = floatval($sale->given_amount ?? 0);
                $adjustmentAmount = floatval($sale->adjustment_amount ?? 0);
                $billTotal = $sale->total_payable;
                $dateKey = $sale->Date ?? $sale->created_at->format('Y-m-d');
                $customerCode = $sale->customer_code ?? 'Unknown';

                // Update totals
                $report['summary']['total_given_amount'] += $givenAmount;
                $report['summary']['total_bill_amount'] += $billTotal;

                // Categorize by payment type
                if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                    $report['summary']['cash_collection'] += $givenAmount;
                    $paymentTypeSummary['Cash'] += $givenAmount;
                } elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                    $report['summary']['cheque_collection'] += $givenAmount;
                    $paymentTypeSummary['Cheque'] += $givenAmount;
                } elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                    $report['summary']['bank_transfer_collection'] += $givenAmount;
                    $paymentTypeSummary['Bank Transfer'] += $givenAmount;
                } elseif ($paymentType === 'bag_to_box') {
                    $report['summary']['bag_to_box_total'] += $adjustmentAmount;
                    $paymentTypeSummary['bag_to_box'] += $adjustmentAmount;
                } elseif ($paymentType === 'bill_to_bill') {
                    $report['summary']['bill_to_bill_total'] += $adjustmentAmount;
                    $paymentTypeSummary['bill_to_bill'] += $adjustmentAmount;
                } elseif ($paymentType === 'bad_debt') {
                    $report['summary']['bad_debt_total'] += $adjustmentAmount;
                    $paymentTypeSummary['bad_debt'] += $adjustmentAmount;
                }

                // Customer breakdown
                if (!isset($customerSummary[$customerCode])) {
                    $customerSummary[$customerCode] = [
                        'customer_code' => $customerCode,
                        'total_bill' => 0,
                        'total_given' => 0,
                        'cash_paid' => 0,
                        'cheque_paid' => 0,
                        'bank_transfer_paid' => 0,
                        'adjustments' => 0,
                        'remaining' => 0,
                        'bill_count' => 0,
                    ];
                    $report['summary']['total_customers']++;
                }

                $customerSummary[$customerCode]['total_bill'] += $billTotal;
                $customerSummary[$customerCode]['total_given'] += $givenAmount;
                $customerSummary[$customerCode]['bill_count']++;

                if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                    $customerSummary[$customerCode]['cash_paid'] += $givenAmount;
                } elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                    $customerSummary[$customerCode]['cheque_paid'] += $givenAmount;
                } elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                    $customerSummary[$customerCode]['bank_transfer_paid'] += $givenAmount;
                } elseif (in_array($paymentType, ['bag_to_box', 'bill_to_bill', 'bad_debt'])) {
                    $customerSummary[$customerCode]['adjustments'] += $adjustmentAmount;
                }

                $customerSummary[$customerCode]['remaining'] = $customerSummary[$customerCode]['total_bill'] - $customerSummary[$customerCode]['total_given'];

                // Date breakdown
                if (!isset($dateSummary[$dateKey])) {
                    $dateSummary[$dateKey] = [
                        'date' => $dateKey,
                        'cash' => 0,
                        'cheque' => 0,
                        'bank_transfer' => 0,
                        'adjustments' => 0,
                        'total' => 0,
                    ];
                }

                if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                    $dateSummary[$dateKey]['cash'] += $givenAmount;
                } elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                    $dateSummary[$dateKey]['cheque'] += $givenAmount;
                } elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                    $dateSummary[$dateKey]['bank_transfer'] += $givenAmount;
                } elseif (in_array($paymentType, ['bag_to_box', 'bill_to_bill', 'bad_debt'])) {
                    $dateSummary[$dateKey]['adjustments'] += $adjustmentAmount;
                }
                $dateSummary[$dateKey]['total'] += $givenAmount + $adjustmentAmount;
            }

            // Calculate total remaining
            $report['summary']['total_remaining'] = $report['summary']['total_bill_amount'] - $report['summary']['total_given_amount'];
            $report['summary']['total_bills'] = $sales->unique('bill_no')->count();

            // Prepare breakdown arrays
            $report['breakdown_by_customer'] = array_values($customerSummary);
            $report['breakdown_by_date'] = array_values($dateSummary);
            $report['breakdown_by_payment_type'] = $paymentTypeSummary;

            // Prepare graph data (last 7 days)
            $graphLabels = [];
            $graphCash = [];
            $graphCheque = [];
            $graphBankTransfer = [];
            $graphAdjustments = [];

            for ($i = 6; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i)->format('Y-m-d');
                $graphLabels[] = $today->copy()->subDays($i)->format('M d');

                $dayData = $dateSummary[$date] ?? ['cash' => 0, 'cheque' => 0, 'bank_transfer' => 0, 'adjustments' => 0];
                $graphCash[] = $dayData['cash'];
                $graphCheque[] = $dayData['cheque'];
                $graphBankTransfer[] = $dayData['bank_transfer'];
                $graphAdjustments[] = $dayData['adjustments'];
            }

            $report['graph_data'] = [
                'labels' => $graphLabels,
                'cash' => $graphCash,
                'cheque' => $graphCheque,
                'bank_transfer' => $graphBankTransfer,
                'adjustments' => $graphAdjustments,
            ];

            return response()->json([
                'success' => true,
                'report' => $report,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate payment report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(Request $request)
    {
        try {
            $today = Carbon::today();

            // Today's stats
            $todaySales = Sale::where('bill_printed', 'Y')
                ->whereDate('Date', $today)
                ->get();

            // This week's stats
            $weekStart = $today->copy()->startOfWeek();
            $weekEnd = $today->copy()->endOfWeek();
            $weekSales = Sale::where('bill_printed', 'Y')
                ->whereBetween('Date', [$weekStart, $weekEnd])
                ->get();

            // This month's stats
            $monthStart = $today->copy()->startOfMonth();
            $monthEnd = $today->copy()->endOfMonth();
            $monthSales = Sale::where('bill_printed', 'Y')
                ->whereBetween('Date', [$monthStart, $monthEnd])
                ->get();

            // Helper function to calculate stats
            $calculateStats = function ($sales) {
                $cash = 0;
                $cheque = 0;
                $bankTransfer = 0;
                $bagToBox = 0;
                $billToBill = 0;
                $badDebt = 0;
                $totalGiven = 0;

                foreach ($sales as $sale) {
                    $paymentType = $sale->payment_adjustment_type;
                    $givenAmount = floatval($sale->given_amount ?? 0);
                    $adjustmentAmount = floatval($sale->adjustment_amount ?? 0);
                    $totalGiven += $givenAmount;

                    if ($paymentType === 'cash' || $paymentType === 'Cash' || ($paymentType === null && $givenAmount > 0)) {
                        $cash += $givenAmount;
                    } elseif ($paymentType === 'cheque' || $paymentType === 'Cheque') {
                        $cheque += $givenAmount;
                    } elseif ($paymentType === 'Bank Transfer' || $paymentType === 'bank_transfer') {
                        $bankTransfer += $givenAmount;
                    } elseif ($paymentType === 'bag_to_box') {
                        $bagToBox += $adjustmentAmount;
                    } elseif ($paymentType === 'bill_to_bill') {
                        $billToBill += $adjustmentAmount;
                    } elseif ($paymentType === 'bad_debt') {
                        $badDebt += $adjustmentAmount;
                    }
                }

                return [
                    'cash' => $cash,
                    'cheque' => $cheque,
                    'bank_transfer' => $bankTransfer,
                    'bag_to_box' => $bagToBox,
                    'bill_to_bill' => $billToBill,
                    'bad_debt' => $badDebt,
                    'total_given' => $totalGiven,
                    'transactions' => $sales->count(),
                    'bills' => $sales->unique('bill_no')->count(),
                ];
            };

            return response()->json([
                'success' => true,
                'today' => $calculateStats($todaySales),
                'week' => $calculateStats($weekSales),
                'month' => $calculateStats($monthSales),
                'pending_bills' => Sale::where('bill_printed', 'Y')
                    ->where('given_amount_applied', 'N')
                    ->distinct('bill_no')
                    ->count('bill_no'),
                'total_customers' => Customer::count(),
                'debtors_count' => Customer::where('Debtor', 'Y')->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard stats: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Delete all payments for a specific bill (reverse the payment operation)
     */
    /**
     * Delete all payments for a specific bill (reverse the payment operation)
     */
    public function deleteBillPayments($billNo)
    {
        try {
            DB::beginTransaction();

            // Find all sales records for this bill
            $sales = Sale::where('bill_no', $billNo)
                ->where('bill_printed', 'Y')
                ->get();

            if ($sales->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sales found for this bill number'
                ], 404);
            }

            // Get the customer_code from the first sale (all sales for same bill will have same customer_code)
            $customerCode = $sales->first()->customer_code;

            // Delete debtor records for this bill_no and customer_code
            $deletedDebtors = Debtor::where('bill_no', $billNo)
                ->where('customer_code', $customerCode)
                ->delete();

            // Reset all payment-related fields to their original state (before any payments)
            foreach ($sales as $sale) {
                $sale->update([
                    'given_amount' => 0,
                    'given_amount_applied' => 'N',
                    'credit_transaction' => 'Y',
                    'payment_adjustment_type' => null,
                    'adjustment_amount' => 0,
                    'payment_history' => null,
                    'cheq_date' => null,
                    'cheq_no' => null,
                    'bank_account_id' => null,
                    'bank_name' => null,
                    'transfer_reference_no' => null,
                    'transfer_date' => null,
                    'transfer_notes' => null,
                    'bag_count' => null,
                    'box_count' => null,
                    'bag_value' => null,
                    'box_value' => null,
                    'target_customer_code' => null,
                    'target_bill_no' => null,
                    'target_bill_value' => null,
                    'target_supplier_code' => null,
                    'target_supplier_bill_no' => null,
                    'target_supplier_bill_value' => null,
                    'bad_debt_name' => null,
                    'bad_debt_amount' => null,
                ]);
            }

            DB::commit();

            $debtorMessage = $deletedDebtors > 0
                ? " and {$deletedDebtors} debtor record(s) deleted"
                : "";

            return response()->json([
                'success' => true,
                'message' => "All payments for Bill #{$billNo} have been reversed successfully{$debtorMessage}",
                'affected_records' => $sales->count(),
                'deleted_debtors' => $deletedDebtors
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete bill payments: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reverse payments: ' . $e->getMessage()
            ], 500);
        }
    }
}