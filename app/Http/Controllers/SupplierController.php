<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Models\SupplierBillNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        'dob'          => 'required|date', // Added validation for DOB
        'address'      => 'required|string',
        'profile_pic'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_front'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_back'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    // Handle Profile Picture Upload
    if ($request->hasFile('profile_pic')) {
        $data['profile_pic'] = $request->file('profile_pic')->store('suppliers/profiles', 'public');
    }

    // Handle NIC Front Upload
    if ($request->hasFile('nic_front')) {
        $data['nic_front'] = $request->file('nic_front')->store('suppliers/nic', 'public');
    }

    // Handle NIC Back Upload
    if ($request->hasFile('nic_back')) {
        $data['nic_back'] = $request->file('nic_back')->store('suppliers/nic', 'public');
    }

    // Ensure code is uppercase
    $data['code'] = strtoupper($data['code']);
    
    // This will now include 'dob' in the creation process
    $supplier = Supplier::create($data);

    return response()->json([
        'message' => 'Supplier added successfully!', 
        'supplier' => $supplier
    ], 201);
}    public function show(Supplier $supplier)
    {
        return response()->json($supplier);
    }

   public function update(Request $request, Supplier $supplier)
{
    $data = $request->validate([
        'code'         => 'required|unique:suppliers,code,' . $supplier->id,
        'name'         => 'required|string',
        'dob'          => 'required|date', // Added DOB validation
        'address'      => 'required|string',
        'profile_pic'  => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_front'    => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_back'     => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
    ]);

    // Handle File Uploads (Profile and NIC)
    foreach (['profile_pic', 'nic_front', 'nic_back'] as $field) {
        if ($request->hasFile($field)) {
            // Delete old file if it exists
            if ($supplier->$field) {
                \Storage::disk('public')->delete($supplier->$field);
            }
            
            // Set storage path based on field type
            $path = ($field === 'profile_pic') ? 'suppliers/profiles' : 'suppliers/nic';
            $data[$field] = $request->file($field)->store($path, 'public');
        }
    }

    // Ensure code is always uppercase
    $data['code'] = strtoupper($data['code']);
    
    // Update the supplier record
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
   public function marksuppliers(Request $request)
{
    \Log::info('marksuppliers endpoint hit', [
        'request_data' => $request->all(),
        'headers' => $request->headers->all()
    ]);
    
    $validated = $request->validate([
        'transaction_ids' => 'required|array',
        'telephone_no'   => 'nullable|string',
        'advance_amount' => 'required|numeric',
        'supplier_code'  => 'required|string'
    ]);

    \Log::info('Validation passed', ['validated' => $validated]);

    $ids = $validated['transaction_ids'];

    try {
        DB::beginTransaction();
        \Log::info('Transaction started', ['ids' => $ids]);

        // 1. Generate Bill Number
        $counter = SupplierBillNumber::where('id', 1)->lockForUpdate()->first();
        if (!$counter) {
            \Log::error('SupplierBillNumber not found');
            throw new \Exception('Supplier bill counter not found');
        }
        
        $finalBillNo = $counter->prefix . ($counter->last_number + 1);
        $counter->increment('last_number');
        \Log::info('Bill number generated', ['bill_no' => $finalBillNo]);

        // 2. Snapshot current data for the public link
        $salesRecords = Sale::whereIn('id', $ids)->get();
        \Log::info('Sales records fetched', ['count' => $salesRecords->count()]);

        // 3. Mark records as printed
        $updated = Sale::whereIn('id', $ids)->update([
            'supplier_bill_no' => $finalBillNo,
            'supplier_bill_printed' => 'Y',
        ]);
        \Log::info('Records updated', ['updated_count' => $updated]);

        // 4. Create Public Link Token
        $token = Str::random(40);
        DB::table('supplier_bill_links')->insert([
            'token'         => $token,
            'bill_no'       => $finalBillNo,
            'sales_data'    => $salesRecords->toJson(),
            'advance_amount'=> $validated['advance_amount'],
            'supplier_code' => $validated['supplier_code'],
            'created_at'    => now(),
        ]);
        \Log::info('Public link created', ['token' => $token]);

        DB::commit();
        \Log::info('Transaction committed');

        // 5. SEND SMS VIA TEXT.LK
        if (!empty($validated['telephone_no'])) {
            \Log::info('Attempting to send SMS', [
                'telephone' => $validated['telephone_no'],
                'bill_no' => $finalBillNo
            ]);
            
            try {
                $smsResult = $this->sendTextLKSMS($validated, $finalBillNo, $salesRecords, $token);
                \Log::info('SMS function completed', ['result' => $smsResult]);
            } catch (\Exception $e) {
                \Log::error('SMS sending failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            \Log::warning('No telephone number provided, SMS not sent');
        }

        return response()->json(['new_bill_no' => $finalBillNo, 'token' => $token]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('marksuppliers error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

private function sendTextLKSMS($data, $billNo, $records, $token)
{
    // 1. Calculate totals for the message
    $total = $records->sum('SupplierTotal');
    $net = $total - $data['advance_amount'];
    
    // 2. Build the Public View URL
    $baseUrl = rtrim(env('APP_FRONTEND_URL'), '/');
    $url = "{$baseUrl}/view-supplier-bill/{$token}";

    // 3. Build Item Summary String
    $summary = $records->groupBy('item_name')->map(function ($group) {
        $weight = number_format($group->sum('weight'), 2);
        return $group->first()->item_name . ":" . $weight . "kg/" . $group->sum('packs');
    })->implode("\n");

    // 4. Construct the Final Message
    $message = "supplirer Bill\n" .
               "Bill #{$billNo}\n" .
               "{$summary}\n" .
               "Total: " . number_format($total, 2) . "\n" .
               "Net: " . number_format($net, 2) . "\n" .
               "View Bill: {$url}";

    // 5. Clean the phone number
    $recipient = preg_replace('/[^0-9]/', '', $data['telephone_no']);
    
    // Log the SMS attempt for debugging
    \Log::info('Attempting to send SMS', [
        'bill_no' => $billNo,
        'recipient' => $recipient,
        'message' => $message,
        'api_key_present' => !empty(env('TEXTLK_SMS_API_KEY')),
        'sender_id' => env('TEXTLK_SMS_SENDER_ID')
    ]);

    // 6. Execute Text.lk API Call
    try {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . env('TEXTLK_SMS_API_KEY'),
            'Accept'        => 'application/json',
        ])->post('https://app.text.lk/api/v3/sms/send', [
            'recipient' => $recipient,
            'sender_id' => env('TEXTLK_SMS_SENDER_ID'),
            'type'      => 'plain',
            'message'   => $message,
        ]);
        
        \Log::info('Text.lk API Response', [
            'status' => $response->status(),
            'body' => $response->json()
        ]);
        
        return $response;
    } catch (\Exception $e) {
        \Log::error('Text.lk API Error', [
            'error' => $e->getMessage()
        ]);
        throw $e;
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
// SupplierController.php

public function dobreport(Request $request)
{
    $query = Supplier::query();

    // 1. Filter by Today's Birthdays
    if ($request->has('today_birthday') && $request->today_birthday == 'true') {
        $today = now()->format('m-d'); // Get current Month and Day
        $query->whereRaw("DATE_FORMAT(dob, '%m-%d') = ?", [$today]);
    } 
    // 2. Filter by Date Range (if provided)
    elseif ($request->has('start_date') && $request->has('end_date')) {
        $query->whereBetween('dob', [$request->start_date, $request->end_date]);
    }

    // Select only the requested columns
    $suppliers = $query->select('id', 'code', 'name', 'dob')->get();

    return response()->json($suppliers);
}
// app/Http/Controllers/SupplierController.php

public function updatePhone(Request $request) {
    $validated = $request->validate([
        'code' => 'required|string',
        'telephone_no' => 'required|string',
    ]);

    // Finds by 'code', updates or creates with 'telephone_no'
    $supplier = Supplier::updateOrCreate(
        ['code' => $validated['code']],
        ['telephone_no' => $validated['telephone_no']]
    );

    return response()->json([
        'message' => 'සැපයුම්කරුගේ දුරකථන අංකය යාවත්කාලීන විය!',
        'supplier' => $supplier
    ]);
}

}