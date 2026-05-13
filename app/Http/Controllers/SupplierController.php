<?php

namespace App\Http\Controllers;

use App\Helpers\CreditorNumberHelper;
use App\Models\Creditor;
use App\Models\Customer;
use App\Models\SalesHistory;
use App\Models\Supplier;
use App\Models\SupplierLoan;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Models\SupplierBillNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::all();
        return response()->json($suppliers);
    }

   // In SupplierController.php - Update the store method
// In SupplierController.php - Update the store method
public function store(Request $request)
{
    $data = $request->validate([
        'code' => 'required|unique:suppliers',
        'name' => 'required|string',
        'dob' => 'required|date',
        'address' => 'required|string',
        'telephone_no' => 'required|string|max:20',
        'advance_amount' => 'nullable|numeric',
        'bill_no' => 'nullable|string',  // Add this
        'credit_amount' => 'nullable|numeric',  // Add this
        'profile_pic' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_front' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_back' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    if ($request->hasFile('profile_pic')) {
        $data['profile_pic'] = $request->file('profile_pic')->store('suppliers/profiles', 'public');
    }

    if ($request->hasFile('nic_front')) {
        $data['nic_front'] = $request->file('nic_front')->store('suppliers/nic', 'public');
    }

    if ($request->hasFile('nic_back')) {
        $data['nic_back'] = $request->file('nic_back')->store('suppliers/nic', 'public');
    }

    $data['code'] = strtoupper($data['code']);
    $data['Creditor'] = 'Y';
    
    // Generate creditor number
    $data['Creditor_no'] = CreditorNumberHelper::generateCreditorNumber();
    
    \Log::info('Creating new supplier with creditor no', [
        'supplier_code' => $data['code'],
        'creditor_no' => $data['Creditor_no']
    ]);

    $supplier = Supplier::create($data);

    // Create creditor record if bill_no is provided
    if ($request->has('bill_no') && $request->bill_no) {
        $creditor = Creditor::create([
            'bill_no' => $request->bill_no,
            'supplier_code' => $supplier->code,
            'credit_amount' => $request->credit_amount ?? 0,
            'paid_amount' => 0,
            'remaining_amount' => $request->credit_amount ?? 0,
            'status' => 'pending',
            'settled_way' => Creditor::SETTLED_WAY_REGISTRATION,
            'Creditor_no' => $supplier->Creditor_no
        ]);
        
        \Log::info('Creditor record created for new supplier', [
            'creditor_id' => $creditor->id,
            'creditor_no' => $creditor->Creditor_no,
            'bill_no' => $creditor->bill_no
        ]);
    }

    return response()->json([
        'message' => 'Supplier added successfully!',
        'supplier' => $supplier,
        'Creditor_no' => $supplier->Creditor_no
    ], 201);
}

    public function checkOrCreateCreditor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_code' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $supplierCode = strtoupper($request->supplier_code);
        $supplier = Supplier::where('code', $supplierCode)->first();

        if ($supplier) {
            // Update existing supplier as creditor
            $supplier->Creditor = 'Y';
            $supplier->save();

            return response()->json([
                'exists' => true,
                'supplier' => $supplier,
                'message' => 'Supplier marked as creditor'
            ]);
        } else {
            return response()->json([
                'exists' => false,
                'message' => 'Supplier not found. Please create new supplier.'
            ]);
        }
    }

    public function getSupplierByCode($code)
    {
        $supplier = Supplier::where('code', strtoupper($code))->first();

        if ($supplier) {
            return response()->json([
                'success' => true,
                'supplier' => $supplier
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Supplier not found'
        ], 404);
    }
    public function show(Supplier $supplier)
    {
        return response()->json($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'code' => 'required|unique:suppliers,code,' . $supplier->id,
            'name' => 'required|string',
            'dob' => 'required|date', // Added DOB validation
            'address' => 'required|string',
            'profile_pic' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_front' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_back' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
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
            'telephone_no' => 'nullable|string',
            'advance_amount' => 'required|numeric',
            'supplier_code' => 'required|string'
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
                'token' => $token,
                'bill_no' => $finalBillNo,
                'sales_data' => $salesRecords->toJson(),
                'advance_amount' => $validated['advance_amount'],
                'supplier_code' => $validated['supplier_code'],
                'created_at' => now(),
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

        // 4. Construct the Final Message - NOW INCLUDING ADVANCE AMOUNT EXPLICITLY
        $message = "Supplier Bill\n" .  // Fixed typo from "supplirer" to "Supplier"
            "Bill #{$billNo}\n" .
            "{$summary}\n" .
            "Total: Rs. " . number_format($total, 2) . "\n" .
            "Advance: Rs. " . number_format($data['advance_amount'], 2) . "\n" .  // Explicitly showing advance
            "Net Payable: Rs. " . number_format($net, 2) . "\n" .
            "View Bill: {$url}";

        // 5. Clean the phone number
        $recipient = preg_replace('/[^0-9]/', '', $data['telephone_no']);

        // Log the SMS attempt for debugging
        \Log::info('Attempting to send SMS', [
            'bill_no' => $billNo,
            'recipient' => $recipient,
            'advance_amount' => $data['advance_amount'],
            'total' => $total,
            'net' => $net,
            'message' => $message,
            'api_key_present' => !empty(env('TEXTLK_SMS_API_KEY')),
            'sender_id' => env('TEXTLK_SMS_SENDER_ID')
        ]);

        // 6. Execute Text.lk API Call
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . env('TEXTLK_SMS_API_KEY'),
                'Accept' => 'application/json',
            ])->post('https://app.text.lk/api/v3/sms/send', [
                        'recipient' => $recipient,
                        'sender_id' => env('TEXTLK_SMS_SENDER_ID'),
                        'type' => 'plain',
                        'message' => $message,
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
                'loan_taken',
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
                'details' => $e->getMessage()
            ], 500);
        }
    }
    public function getUnprintedDetails2($supplierCode): JsonResponse
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
                'loan_taken',
                DB::raw('DATE(created_at) as Date')
            )
                ->where('supplier_code', $supplierCode)
                ->where('supplier_bill_printed', 'Y')
                ->where('loan_taken', 'Y')
                ->whereNotNull('supplier_code') // Ensure a supplier code is present
                ->get();

            return response()->json($details);

        } catch (\Exception $e) {
            Log::error("Error fetching unprinted details for supplier {$supplierCode}: " . $e->getMessage());
            return response()->json([
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
            'supplier_code' => 'nullable|string',
            'customer_code' => 'nullable|string'
        ]);

        $sale = Sale::findOrFail($id);

        // Store old values
        $oldCustomerCode = $sale->customer_code;
        $hasExistingBillNo = !is_null($sale->bill_no) && $sale->bill_no !== '';

        // Update supplier_code
        $sale->supplier_code = $request->supplier_code;

        // Check if customer_code is being changed
        $customerCodeChanged = $request->filled('customer_code') && $request->customer_code !== $oldCustomerCode;

        if ($request->filled('customer_code')) {
            $sale->customer_code = $request->customer_code;
        }

        // 🚀 Generate new bill number ONLY if:
        // 1. Customer code changed AND
        // 2. Record already has an existing bill_no (printed bill)
        $newBillNo = null;
        if ($customerCodeChanged && $hasExistingBillNo) {
            $newBillNo = $this->generateUniqueBillNumber();
            $sale->bill_no = $newBillNo;
            $sale->supplier_bill_no = $newBillNo;

            // Also update all records with the same old bill_no
            Sale::where('bill_no', $sale->bill_no)
                ->where('id', '!=', $id)
                ->update(['bill_no' => $newBillNo, 'supplier_bill_no' => $newBillNo]);
        }

        $sale->save();

        return response()->json([
            'message' => 'Record updated successfully',
            'data' => $sale,
            'bill_updated' => ($customerCodeChanged && $hasExistingBillNo),
            'new_bill_no' => $newBillNo,
            'had_existing_bill' => $hasExistingBillNo
        ], 200);
    }

    /**
     * Generate a unique 4-digit bill number
     */
    private function generateUniqueBillNumber()
    {
        do {
            // Generate random 4-digit number (1000-9999)
            $newBillNo = rand(1000, 9999);

            // Check if this bill number already exists in sales table
            $exists = Sale::where('bill_no', $newBillNo)->exists();

            // Also check in sales_history if needed
            if (!$exists) {
                $exists = SalesHistory::where('bill_no', $newBillNo)->exists();
            }

        } while ($exists);

        return $newBillNo;
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
    public function getByCode($code)
    {
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

    public function updatePhone(Request $request)
    {
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
    public function resendSupplierSMS(Request $request)
    {
        \Log::info('resendSupplierSMS endpoint hit', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        $validated = $request->validate([
            'bill_no' => 'required|string',
            'telephone_no' => 'required|string',
            'supplier_code' => 'required|string',
            'transaction_ids' => 'required|array',
            'advance_amount' => 'required|numeric',
            'is_reprint' => 'boolean'
        ]);

        try {
            // Fetch the CURRENT sales records for this bill with updated data
            $salesRecords = Sale::whereIn('id', $validated['transaction_ids'])->get();

            if ($salesRecords->isEmpty()) {
                return response()->json(['error' => 'No records found'], 404);
            }

            \Log::info('Sales records fetched for reprint', [
                'count' => $salesRecords->count(),
                'bill_no' => $validated['bill_no']
            ]);

            // Check if there's an existing token for this bill
            $existingLink = DB::table('supplier_bill_links')
                ->where('bill_no', $validated['bill_no'])
                ->first();

            // IMPORTANT: Update the existing link with new data or create new one
            if ($existingLink) {
                // Update the existing link with current data
                DB::table('supplier_bill_links')
                    ->where('bill_no', $validated['bill_no'])
                    ->update([
                        'sales_data' => $salesRecords->toJson(),
                        'advance_amount' => $validated['advance_amount'],
                        'supplier_code' => $validated['supplier_code'],
                        'updated_at' => now(),
                    ]);

                $token = $existingLink->token;
                \Log::info('Updated existing bill link with current data', [
                    'bill_no' => $validated['bill_no'],
                    'token' => $token
                ]);
            } else {
                // Create new link if doesn't exist
                $token = $this->createNewBillLink($validated, $salesRecords);
            }

            // Send SMS with updated data
            $smsResult = $this->sendReprintSMS($validated, $salesRecords, $token);

            return response()->json([
                'success' => true,
                'message' => 'SMS sent successfully with updated bill data',
                'token' => $token
            ]);

        } catch (\Exception $e) {
            \Log::error('resendSupplierSMS error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function createNewBillLink($data, $records)
    {
        $token = Str::random(40);

        DB::table('supplier_bill_links')->insert([
            'token' => $token,
            'bill_no' => $data['bill_no'],
            'sales_data' => $records->toJson(),
            'advance_amount' => $data['advance_amount'],
            'supplier_code' => $data['supplier_code'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function sendReprintSMS($data, $records, $token)
    {
        // Calculate totals with current data
        $total = $records->sum('SupplierTotal');
        $net = $total - $data['advance_amount'];

        // Build the Public View URL
        $baseUrl = rtrim(env('APP_FRONTEND_URL'), '/');
        $url = "{$baseUrl}/view-supplier-bill/{$token}";

        // Build Item Summary String with current data
        $summary = $records->groupBy('item_name')->map(function ($group) {
            $weight = number_format($group->sum('weight'), 2);
            $packs = $group->sum('packs');
            return $group->first()->item_name . ":" . $weight . "kg/" . $packs;
        })->implode("\n");

        // Message with clear indication of updated data
        if (isset($data['is_reprint']) && $data['is_reprint']) {
            $message = "🔄 REPRINT - Supplier Bill (UPDATED)\n" .
                "Bill #{$data['bill_no']} (Reprinted)\n" .
                "{$summary}\n" .
                "Total: Rs. " . number_format($total, 2) . "\n" .
                "Advance: Rs. " . number_format($data['advance_amount'], 2) . "\n" .
                "Net: Rs. " . number_format($net, 2) . "\n" .
                "View Updated Bill: {$url}\n" .
                "Please check the updated details.";
        } else {
            $message = "Supplier Bill\n" .
                "Bill #{$data['bill_no']}\n" .
                "{$summary}\n" .
                "Total: Rs. " . number_format($total, 2) . "\n" .
                "Advance: Rs. " . number_format($data['advance_amount'], 2) . "\n" .
                "Net: Rs. " . number_format($net, 2) . "\n" .
                "View Bill: {$url}";
        }

        // Clean the phone number
        $recipient = preg_replace('/[^0-9]/', '', $data['telephone_no']);

        \Log::info('Attempting to send reprint SMS with updated data', [
            'bill_no' => $data['bill_no'],
            'recipient' => $recipient,
            'advance_amount' => $data['advance_amount'],
            'total' => $total,
            'net' => $net,
            'is_reprint' => $data['is_reprint'] ?? false
        ]);

        // Execute Text.lk API Call
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . env('TEXTLK_SMS_API_KEY'),
                'Accept' => 'application/json',
            ])->post('https://app.text.lk/api/v3/sms/send', [
                        'recipient' => $recipient,
                        'sender_id' => env('TEXTLK_SMS_SENDER_ID'),
                        'type' => 'plain',
                        'message' => $message,
                    ]);

            \Log::info('Text.lk API Response for reprint', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            return $response;
        } catch (\Exception $e) {
            \Log::error('Text.lk API Error for reprint', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    // In your SupplierController.php
    public function getSuppliersWithBills()
    {
        $suppliers = DB::table('sales')
            ->select('supplier_code', 'supplier_bill_no')
            ->whereNotNull('supplier_bill_no')
            ->distinct()
            ->get();

        return response()->json(['success' => true, 'data' => $suppliers]);
    }
    public function getDetailedReport($supplierCode)
    {
        // Get all sales for this supplier
        $sales = Sale::where('supplier_code', $supplierCode)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get all loan payments for this supplier
        $loans = SupplierLoan::where('code', $supplierCode)
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate summary statistics
        $summary = [
            'total_sales_value' => $sales->sum('SupplierTotal'),
            'total_sales_count' => $sales->count(),
            'unique_bills' => $sales->whereNotNull('supplier_bill_no')->unique('supplier_bill_no')->count(),
            'total_paid' => $loans->sum('loan_amount'),
            'total_remaining' => $sales->sum('SupplierTotal') - $loans->sum('loan_amount'),
            'total_cheque_payments' => $loans->where('type', 'Cheque')->sum('loan_amount'),
            'total_cash_payments' => $loans->where('type', 'Cash')->sum('loan_amount'),
            'total_bank_transfers' => $loans->where('type', 'Bank Transfer')->sum('loan_amount'),
            'total_bag_to_box' => $loans->where('type', 'bag_to_box')->sum('loan_amount'),
            'total_bill_to_bill' => $loans->where('type', 'bill_to_bill')->sum('loan_amount'),
            'total_bad_debt' => $loans->where('type', 'bad_debt')->sum('loan_amount'),
        ];

        // Group sales by bill number
        $bills = [];
        foreach ($sales as $sale) {
            $billNo = $sale->supplier_bill_no ?? 'No Bill';
            if (!isset($bills[$billNo])) {
                $bills[$billNo] = [
                    'bill_no' => $billNo,
                    'date' => $sale->created_at,
                    'items' => [],
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'payments' => []
                ];
            }
            $bills[$billNo]['items'][] = $sale;
            $bills[$billNo]['total_amount'] += $sale->SupplierTotal;
        }

        // Add payment information to each bill
        foreach ($loans as $loan) {
            if ($loan->bill_no && isset($bills[$loan->bill_no])) {
                $bills[$loan->bill_no]['paid_amount'] += $loan->loan_amount;
                $bills[$loan->bill_no]['payments'][] = $loan;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'supplier_code' => $supplierCode,
                'summary' => $summary,
                'bills' => array_values($bills),
                'all_payments' => $loans,
                'all_sales' => $sales
            ]
        ]);
    }
    public function getDebtorReport(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $limit = $request->get('limit', 50);

            // Get debtor customers
            $debtors = Customer::where('Debtor', 'Y')
                ->when($search, function ($query, $search) {
                    return $query->where(function ($q) use ($search) {
                        $q->where('short_name', 'LIKE', "%{$search}%")
                            ->orWhere('name', 'LIKE', "%{$search}%")
                            ->orWhere('telephone_no', 'LIKE', "%{$search}%");
                    });
                })
                ->get();

            $debtorData = [];

            foreach ($debtors as $debtor) {
                // Get all sales for this customer
                $sales = Sale::where('customer_code', $debtor->short_name)
                    ->where('bill_printed', 'Y')
                    ->get();

                // Calculate totals
                $totalSales = 0;
                $totalGiven = 0;
                $totalPackCost = 0;
                $bills = [];

                // Group by bill number
                $billGroups = $sales->groupBy('bill_no');

                foreach ($billGroups as $billNo => $billSales) {
                    $billTotal = 0;
                    $billGiven = 0;
                    $billItems = [];

                    foreach ($billSales as $sale) {
                        $itemTotal = (float) $sale->total + ((float) $sale->packs * (float) $sale->CustomerPackCost);
                        $billTotal += $itemTotal;
                        $totalSales += $itemTotal;
                        $totalPackCost += (float) $sale->packs * (float) $sale->CustomerPackCost;

                        $billItems[] = [
                            'supplier_code' => $sale->supplier_code,
                            'item_name' => $sale->item_name,
                            'weight' => (float) $sale->weight,
                            'price_per_kg' => (float) $sale->price_per_kg,
                            'packs' => (int) $sale->packs,
                            'total' => $itemTotal,
                            'date' => $sale->created_at
                        ];
                    }

                    // Get given amount and payment history for this bill
                    $billGiven = (float) ($billSales->first()->given_amount ?? 0);
                    $totalGiven += $billGiven;
                    $paymentHistory = $billSales->first()->payment_history ?? [];

                    $bills[] = [
                        'bill_no' => $billNo ?: 'N/A',
                        'total_amount' => $billTotal,
                        'given_amount' => $billGiven,
                        'remaining' => max(0, $billTotal - $billGiven),
                        'status' => ($billTotal - $billGiven) <= 0 ? 'Paid' : 'Pending',
                        'items' => $billItems,
                        'payment_history' => $paymentHistory,
                        'created_at' => $billSales->first()->created_at
                    ];
                }

                $debtorData[] = [
                    'code' => $debtor->short_name,
                    'name' => $debtor->name,
                    'telephone' => $debtor->telephone_no,
                    'address' => $debtor->address,
                    'id_no' => $debtor->ID_NO,
                    'credit_limit' => (float) $debtor->credit_limit,
                    'profile_pic' => $debtor->profile_pic,
                    'nic_front' => $debtor->nic_front,
                    'nic_back' => $debtor->nic_back,
                    'total_sales' => $totalSales,
                    'total_paid' => $totalGiven,
                    'total_remaining' => max(0, $totalSales - $totalGiven),
                    'pack_cost_total' => $totalPackCost,
                    'bill_count' => count($bills),
                    'bills' => $bills,
                    'status' => ($totalSales - $totalGiven) <= 0 ? 'Fully Paid' : 'Has Balance'
                ];
            }

            // Sort by remaining amount (highest first)
            usort($debtorData, function ($a, $b) {
                return $b['total_remaining'] <=> $a['total_remaining'];
            });

            // Apply limit
            if ($limit) {
                $debtorData = array_slice($debtorData, 0, $limit);
            }

            $summary = [
                'total_debtors' => count($debtorData),
                'total_sales_amount' => array_sum(array_column($debtorData, 'total_sales')),
                'total_paid_amount' => array_sum(array_column($debtorData, 'total_paid')),
                'total_remaining_amount' => array_sum(array_column($debtorData, 'total_remaining')),
                'total_bills' => array_sum(array_column($debtorData, 'bill_count'))
            ];

            return response()->json([
                'success' => true,
                'data' => $debtorData,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Debtor report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch debtor report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get creditor report (Suppliers with Creditor = 'Y')
     */
    public function getCreditorReport(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $limit = $request->get('limit', 50);

            // Get creditor suppliers
            $creditors = Supplier::where('Creditor', 'Y')
                ->when($search, function ($query, $search) {
                    return $query->where(function ($q) use ($search) {
                        $q->where('code', 'LIKE', "%{$search}%")
                            ->orWhere('name', 'LIKE', "%{$search}%")
                            ->orWhere('telephone_no', 'LIKE', "%{$search}%");
                    });
                })
                ->get();

            $creditorData = [];

            foreach ($creditors as $creditor) {
                // Get supplier loans
                $loans = SupplierLoan::where('code', $creditor->code)
                    ->orderBy('created_at', 'desc')
                    ->get();

                // Get sales where this supplier is involved
                $sales = Sale::where('supplier_code', $creditor->code)
                    ->whereNotNull('supplier_bill_no')
                    ->get();

                $totalSupplierAmount = $sales->sum('SupplierTotal');
                $totalPaid = $loans->sum('loan_amount');
                $totalRemaining = max(0, $totalSupplierAmount - $totalPaid);

                $bills = [];
                $billGroups = $sales->groupBy('supplier_bill_no');

                foreach ($billGroups as $billNo => $billSales) {
                    $billTotal = $billSales->sum('SupplierTotal');
                    $billPaid = $loans->where('bill_no', $billNo)->sum('loan_amount');

                    $bills[] = [
                        'bill_no' => $billNo ?: 'N/A',
                        'total_amount' => (float) $billTotal,
                        'paid_amount' => (float) $billPaid,
                        'remaining' => max(0, $billTotal - $billPaid),
                        'status' => ($billTotal - $billPaid) <= 0 ? 'Settled' : 'Pending',
                        'created_at' => $billSales->first()->created_at
                    ];
                }

                // Get payment details
                $payments = [];
                foreach ($loans as $loan) {
                    $payments[] = [
                        'id' => $loan->id,
                        'date' => $loan->created_at,
                        'amount' => (float) $loan->loan_amount,
                        'type' => $loan->type,
                        'payment_method_display' => $loan->payment_method_display,
                        'icon' => $loan->payment_icon,
                        'bill_no' => $loan->bill_no,
                        'cheque_no' => $loan->cheque_no,
                        'transfer_reference_no' => $loan->transfer_reference_no,
                        'bad_debt_name' => $loan->bad_debt_name
                    ];
                }

                $creditorData[] = [
                    'code' => $creditor->code,
                    'name' => $creditor->name,
                    'telephone' => $creditor->telephone_no,
                    'address' => $creditor->address,
                    'dob' => $creditor->dob,
                    'profile_pic' => $creditor->profile_pic,
                    'nic_front' => $creditor->nic_front,
                    'nic_back' => $creditor->nic_back,
                    'advance_amount' => (float) $creditor->advance_amount,
                    'total_supplier_amount' => $totalSupplierAmount,
                    'total_paid' => $totalPaid,
                    'total_remaining' => $totalRemaining,
                    'bill_count' => count($bills),
                    'payment_count' => count($payments),
                    'bills' => $bills,
                    'payments' => $payments,
                    'status' => $totalRemaining <= 0 ? 'Fully Settled' : 'Has Balance'
                ];
            }

            // Sort by remaining amount
            usort($creditorData, function ($a, $b) {
                return $b['total_remaining'] <=> $a['total_remaining'];
            });

            if ($limit) {
                $creditorData = array_slice($creditorData, 0, $limit);
            }

            $summary = [
                'total_creditors' => count($creditorData),
                'total_supplier_amount' => array_sum(array_column($creditorData, 'total_supplier_amount')),
                'total_paid_amount' => array_sum(array_column($creditorData, 'total_paid')),
                'total_remaining_amount' => array_sum(array_column($creditorData, 'total_remaining')),
                'total_bills' => array_sum(array_column($creditorData, 'bill_count')),
                'total_payments' => array_sum(array_column($creditorData, 'payment_count'))
            ];

            return response()->json([
                'success' => true,
                'data' => $creditorData,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Creditor report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch creditor report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get combined debtor and creditor report
     */
 public function getCombinedReport(Request $request)
    {
        try {
            $debtorResponse = $this->getDebtorReport($request);
            $creditorResponse = $this->getCreditorReport($request);

            $debtorData = $debtorResponse->getData(true);
            $creditorData = $creditorResponse->getData(true);

            return response()->json([
                'success' => true,
                'debtors' => $debtorData['data'] ?? [],
                'debtor_summary' => $debtorData['summary'] ?? [],
                'creditors' => $creditorData['data'] ?? [],
                'creditor_summary' => $creditorData['summary'] ?? [],
                'combined_summary' => [
                    'total_debtors' => $debtorData['summary']['total_debtors'] ?? 0,
                    'total_creditors' => $creditorData['summary']['total_creditors'] ?? 0,
                    'total_debtor_outstanding' => $debtorData['summary']['total_remaining_amount'] ?? 0,
                    'total_creditor_outstanding' => $creditorData['summary']['total_remaining_amount'] ?? 0
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Combined report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch combined report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get debtor details by code
     */
    public function getDebtorDetails($code)
    {
        try {
            $debtor = Customer::where('short_name', $code)
                ->where('Debtor', 'Y')
                ->first();

            if (!$debtor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Debtor not found'
                ], 404);
            }

            $sales = Sale::where('customer_code', $code)
                ->where('bill_printed', 'Y')
                ->orderBy('created_at', 'desc')
                ->get();

            $bills = [];
            $billGroups = $sales->groupBy('bill_no');

            foreach ($billGroups as $billNo => $billSales) {
                $billTotal = 0;
                foreach ($billSales as $sale) {
                    $billTotal += (float) $sale->total + ((float) $sale->packs * (float) $sale->CustomerPackCost);
                }

                $bills[] = [
                    'bill_no' => $billNo ?: 'N/A',
                    'total_amount' => $billTotal,
                    'given_amount' => (float) ($billSales->first()->given_amount ?? 0),
                    'payment_history' => $billSales->first()->payment_history ?? [],
                    'items' => $billSales->map(function ($sale) {
                        return [
                            'supplier_code' => $sale->supplier_code,
                            'item_name' => $sale->item_name,
                            'weight' => (float) $sale->weight,
                            'price_per_kg' => (float) $sale->price_per_kg,
                            'packs' => (int) $sale->packs,
                            'total' => (float) $sale->total + ((float) $sale->packs * (float) $sale->CustomerPackCost)
                        ];
                    }),
                    'created_at' => $billSales->first()->created_at
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'code' => $debtor->short_name,
                    'name' => $debtor->name,
                    'telephone' => $debtor->telephone_no,
                    'address' => $debtor->address,
                    'id_no' => $debtor->ID_NO,
                    'credit_limit' => (float) $debtor->credit_limit,
                    'profile_pic' => $debtor->profile_pic,
                    'nic_front' => $debtor->nic_front,
                    'nic_back' => $debtor->nic_back,
                    'bills' => $bills
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch debtor details'
            ], 500);
        }
    }

    /**
     * Get creditor details by code
     */
    public function getCreditorDetails($code)
    {
        try {
            $creditor = Supplier::where('code', $code)
                ->where('Creditor', 'Y')
                ->first();

            if (!$creditor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Creditor not found'
                ], 404);
            }

            $loans = SupplierLoan::where('code', $code)
                ->orderBy('created_at', 'desc')
                ->get();

            $sales = Sale::where('supplier_code', $code)
                ->whereNotNull('supplier_bill_no')
                ->get();

            $bills = [];
            $billGroups = $sales->groupBy('supplier_bill_no');

            foreach ($billGroups as $billNo => $billSales) {
                $bills[] = [
                    'bill_no' => $billNo ?: 'N/A',
                    'total_amount' => (float) $billSales->sum('SupplierTotal'),
                    'created_at' => $billSales->first()->created_at,
                    'items' => $billSales->map(function ($sale) {
                        return [
                            'customer_code' => $sale->customer_code,
                            'item_name' => $sale->item_name,
                            'weight' => (float) $sale->weight,
                            'price_per_kg' => (float) $sale->SupplierPricePerKg,
                            'total' => (float) $sale->SupplierTotal
                        ];
                    })
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'code' => $creditor->code,
                    'name' => $creditor->name,
                    'telephone' => $creditor->telephone_no,
                    'address' => $creditor->address,
                    'dob' => $creditor->dob,
                    'advance_amount' => (float) $creditor->advance_amount,
                    'profile_pic' => $creditor->profile_pic,
                    'nic_front' => $creditor->nic_front,
                    'nic_back' => $creditor->nic_back,
                    'bills' => $bills,
                    'payments' => $loans->map(function ($loan) {
                        return [
                            'id' => $loan->id,
                            'date' => $loan->created_at,
                            'amount' => (float) $loan->loan_amount,
                            'type' => $loan->type,
                            'payment_method_display' => $loan->payment_method_display,
                            'icon' => $loan->payment_icon,
                            'bill_no' => $loan->bill_no,
                            'cheque_no' => $loan->cheque_no
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch creditor details'
            ], 500);
        }
    }
    // Add this method to handle creditor status update
 public function updateCreditorStatus(Request $request)
{
    \Log::info('========== START updateCreditorStatus ==========');

    \Log::info('Incoming Request Data', [
        'request' => $request->all()
    ]);

    $request->validate([
        'code' => 'required|string',
        'Creditor' => 'required|in:Y,N',
        'bill_no' => 'nullable|string'
    ]);

    DB::beginTransaction();

    try {

        \Log::info('Searching supplier', [
            'search_code' => strtoupper($request->code)
        ]);

        $supplier = Supplier::where('code', strtoupper($request->code))->first();

        if (!$supplier) {

            \Log::error('Supplier not found', [
                'code' => strtoupper($request->code)
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Supplier not found'
            ], 404);
        }

        \Log::info('Supplier found successfully', [
            'supplier_id' => $supplier->id,
            'supplier_code' => $supplier->code,
            'existing_creditor_status' => $supplier->Creditor,
            'existing_creditor_no' => $supplier->Creditor_no
        ]);

        $updateData = [
            'Creditor' => $request->Creditor
        ];

        $creditorNumber = null;

        // ==========================================
        // IF SETTING AS CREDITOR
        // ==========================================
        if ($request->Creditor === 'Y') {

            \Log::info('Processing Creditor = Y');

            // Generate creditor number if missing
            if (empty($supplier->Creditor_no)) {

                \Log::info('Supplier has no Creditor_no. Generating new one...');

                $creditorNumber = CreditorNumberHelper::generateCreditorNumber();

                \Log::info('Generated creditor number result', [
                    'generated_creditor_no' => $creditorNumber
                ]);

                if (!$creditorNumber) {

                    \Log::error('Creditor number generation FAILED');

                    throw new \Exception('Failed to generate creditor number');
                }

                $updateData['Creditor_no'] = $creditorNumber;

            } else {

                $creditorNumber = $supplier->Creditor_no;

                \Log::info('Using existing creditor number', [
                    'creditor_no' => $creditorNumber
                ]);
            }

            // ==========================================
            // UPDATE SUPPLIER
            // ==========================================
            \Log::info('Updating supplier', [
                'supplier_id' => $supplier->id,
                'update_data' => $updateData
            ]);

            $updated = $supplier->update($updateData);

            \Log::info('Supplier update result', [
                'updated' => $updated
            ]);

            // Reload fresh supplier data
            $supplier->refresh();

            \Log::info('Supplier refreshed after update', [
                'supplier_id' => $supplier->id,
                'Creditor' => $supplier->Creditor,
                'Creditor_no' => $supplier->Creditor_no
            ]);

            // ==========================================
            // CHECK EXISTING CREDITOR
            // ==========================================
            \Log::info('Checking existing creditor record', [
                'supplier_code' => $supplier->code
            ]);

            $existingCreditor = Creditor::where('supplier_code', $supplier->code)->first();

            if (!$existingCreditor) {

                \Log::info('No creditor record found. Creating new creditor record...');

                $creditorData = [
                    'supplier_code' => $supplier->code,
                    'credit_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'status' => 'pending',
                    'settled_way' => Creditor::SETTLED_WAY_REGISTRATION,
                    'Creditor_no' => $creditorNumber
                ];

                // Add bill_no
                if ($request->bill_no) {

                    $creditorData['bill_no'] = $request->bill_no;

                    \Log::info('bill_no added to creditorData', [
                        'bill_no' => $request->bill_no
                    ]);
                }

                \Log::info('Creating creditor with data', [
                    'creditor_data' => $creditorData
                ]);

                $creditor = Creditor::create($creditorData);

                \Log::info('Creditor record created successfully', [
                    'creditor_id' => $creditor->id,
                    'creditor_no' => $creditor->Creditor_no,
                    'supplier_code' => $creditor->supplier_code
                ]);

            } else {

                \Log::warning('Creditor record already exists', [
                    'existing_creditor_id' => $existingCreditor->id,
                    'existing_creditor_no' => $existingCreditor->Creditor_no,
                    'supplier_code' => $existingCreditor->supplier_code
                ]);
            }

        } else {

            // ==========================================
            // REMOVE CREDITOR STATUS
            // ==========================================
            \Log::info('Processing Creditor = N');

            \Log::info('Updating supplier to remove creditor status', [
                'supplier_id' => $supplier->id,
                'update_data' => $updateData
            ]);

            $supplier->update($updateData);

            \Log::info('Creditor status removed successfully', [
                'supplier_code' => $supplier->code
            ]);
        }

        DB::commit();

        \Log::info('Database transaction committed successfully');

        // Reload supplier
        $supplier = Supplier::where('code', strtoupper($request->code))->first();

        \Log::info('Final supplier response data', [
            'supplier_id' => $supplier->id,
            'Creditor' => $supplier->Creditor,
            'Creditor_no' => $supplier->Creditor_no
        ]);

        \Log::info('========== END updateCreditorStatus SUCCESS ==========');

        return response()->json([
            'success' => true,
            'supplier' => $supplier,
            'creditor_no' => $supplier->Creditor_no,
            'message' => 'Creditor status updated successfully'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        \Log::error('========== updateCreditorStatus FAILED ==========');

        \Log::error('Exception Details', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'trace' => $e->getTraceAsString(),
            'request_data' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to update creditor status: ' . $e->getMessage()
        ], 500);
    }
}

    // Add method to get creditor status
    public function getCreditorStatus($code)
    {
        $supplier = Supplier::where('code', strtoupper($code))->first();

        return response()->json([
            'exists' => $supplier !== null,
            'is_creditor' => $supplier ? $supplier->Creditor === 'Y' : false,
            'creditor_no' => $supplier ? $supplier->Creditor_no : null,
            'supplier' => $supplier
        ]);
    }

    // Add method to check supplier exists
    public function checkSupplierExists($code)
    {
        $supplier = Supplier::where('code', strtoupper($code))->first();

        return response()->json([
            'exists' => $supplier !== null,
            'supplier' => $supplier,
            'is_creditor' => $supplier ? $supplier->Creditor === 'Y' : false
        ]);
    }
}