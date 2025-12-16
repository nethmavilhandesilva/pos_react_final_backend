<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomersLoan;
use App\Models\IncomeExpenses;
use App\Models\Sale; // Added import for Sale
use App\Models\GrnEntry;
use App\Models\Setting;
use Carbon\Carbon;
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LoanReportExport;
use Illuminate\Http\JsonResponse; // Added import for JsonResponse

class CustomersLoanController extends Controller
{
    /**
     * Helper to fetch loans for a specific date.
     */
    private function fetchTodayLoans($date)
    {
        // Start the query to fetch loans with related customers
        $query = IncomeExpenses::with('customer')
            ->whereDate('Date', $date)
            ->where('loan_type', '!=', 'returns');

        // Execute the query
        $loans = $query->orderBy('created_at', 'desc')->get();

        // Format loans for the frontend table
        return $loans->map(function ($loan) {
            return [
                'id' => $loan->id, // Use IncomeExpenses ID for Edit/Delete
                'customer_id' => $loan->customer_id,
                'loan_type' => $loan->loan_type,
                'description' => $loan->description,
                'amount' => (float) $loan->amount, // Keep original sign for logic
                'display_amount' => number_format(abs($loan->amount), 2), // Display absolute value
                'customer_short_name' => $loan->customer_short_name,
                'bill_no' => $loan->bill_no,
                'settling_way' => $loan->settling_way,
                'cheque_no' => $loan->cheque_no,
                'bank' => $loan->bank,
                'cheque_date' => $loan->cheque_date,
                'grn_code' => $loan->grn_code ?? null,
                'wasted_packs' => $loan->wasted_packs ?? null,
                'wasted_weight' => $loan->wasted_weight ?? null,
            ];
        });
    }

    /**
     * Provides all data needed for initial page load (customers, grncodes, loans).
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInitialData(Request $request)
    {
        // Fetch distinct codes from GrnEntry for the wasted dropdown
        $grnCodes = GrnEntry::distinct()->pluck('code');

        // Get the date from Setting model or use today as fallback
        $settingDate = Setting::value('value') ?? now()->toDateString();

        // Fetch today's loans using the helper
        $loans = $this->fetchTodayLoans($settingDate);

        return response()->json([
            'grnCodes' => $grnCodes,
            'loans' => $loans,
            'settingDate' => $settingDate
        ]);
    }

    /**
     * Store a newly created resource in storage. (API version)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $settingDate = Setting::value('value') ?? now()->toDateString();

        // Base validation rules
        $rules = [
            'loan_type' => 'required|string|in:old,today,ingoing,outgoing,grn_damage,returns',
            'settling_way' => 'nullable|string|in:cash,cheque',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'nullable|numeric',
            'description' => 'nullable|string|max:255',
            'bill_no' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string|max:255',
            'bank' => 'nullable|string|max:255',
            'cheque_date' => 'nullable|date',
            'wasted_code' => 'nullable|string',
            'wasted_packs' => 'nullable|numeric',
            'wasted_weight' => 'nullable|numeric',
            'return_grn_code' => 'nullable|string',
            'return_item_code' => 'nullable|string',
            'return_bill_no' => 'nullable|string',
            'return_weight' => 'nullable|numeric',
            'return_packs' => 'nullable|numeric',
            'return_reason' => 'nullable|string|max:255',
        ];

        $loanType = $request->input('loan_type');
        $settlingWay = $request->input('settling_way');

        // Conditional validation
        if ($loanType === 'ingoing' || $loanType === 'outgoing') {
            $rules['amount'] = 'required|numeric';
        } elseif ($loanType === 'grn_damage') {
            $rules['wasted_code'] = 'required|string';
            $rules['wasted_packs'] = 'required|numeric';
            $rules['wasted_weight'] = 'required|numeric';
        } elseif ($loanType === 'returns') {
            // Returns validation
            $rules['return_grn_code'] = 'required|string';
            $rules['return_bill_no'] = 'required|string';
            $rules['return_weight'] = 'required|numeric';
            $rules['return_packs'] = 'required|numeric';
        } else {
            $rules['amount'] = 'required|numeric';
            if ($settlingWay === 'cheque') {
                $rules['cheque_no'] = 'required|string|max:255';
                $rules['bank'] = 'required|string|max:255';
                $rules['cheque_date'] = 'required|date';
            }
        }

        $validated = $request->validate($rules);

        // --- Handle GRN Damage ---
        if ($loanType === 'grn_damage') {
            $grnEntry = GrnEntry::where('code', $validated['wasted_code'])->first();

            if (!$grnEntry) {
                return response()->json(['message' => 'GRN code not found.'], 404);
            }

            // Calculate change in stock
            $grnEntry->packs = max(0, $grnEntry->packs - $validated['wasted_packs']);
            $grnEntry->weight = max(0, $grnEntry->weight - $validated['wasted_weight']);

            // Record the wasted values
            $grnEntry->wasted_packs = ($grnEntry->wasted_packs ?? 0) + $validated['wasted_packs'];
            $grnEntry->wasted_weight = ($grnEntry->wasted_weight ?? 0) + $validated['wasted_weight'];
            $grnEntry->save();

            return response()->json(['message' => 'GRN stock and waste details updated successfully!'], 200);
        }

        // --- Handle Returns ---
        if ($loanType === 'returns') {
            $incomeExpense = new IncomeExpenses();
            $incomeExpense->loan_type = 'returns';
            $incomeExpense->GRN_Code = $validated['return_grn_code'];
            $incomeExpense->Item_Code = $validated['return_item_code'];
            $incomeExpense->Bill_no = $validated['return_bill_no'];
            $incomeExpense->weight = $validated['return_weight'];
            $incomeExpense->packs = $validated['return_packs'];
            $incomeExpense->Reason = $validated['return_reason'] ?? null;
            $incomeExpense->amount = 0; // Returns typically don't affect balance directly, but are recorded.
            $incomeExpense->type = 'expense';
            $incomeExpense->date = $settingDate;
            $incomeExpense->ip_address = $request->ip();
            $incomeExpense->save();

            return response()->json(['message' => 'Return record added successfully!'], 201);
        }

        // --- Handle Ingoing / Outgoing ---
        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        // --- Create IncomeExpenses record for all non-GRN, non-Return types ---
        $incomeExpense = new IncomeExpenses();
        $incomeExpense->loan_type = $loanType;
        $incomeExpense->customer_id = $validated['customer_id'] ?? null;
        $incomeExpense->description = $validated['description'];
        $incomeExpense->bill_no = $validated['bill_no'] ?? null;
        $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
        $incomeExpense->bank = $validated['bank'] ?? null;
        $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
        $incomeExpense->settling_way = $validated['settling_way'] ?? 'cash';
        $incomeExpense->customer_short_name = $customerShortName;
        $incomeExpense->date = $settingDate;
        $incomeExpense->ip_address = $request->ip();

        if ($loanType === 'ingoing') {
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } elseif ($loanType === 'outgoing') {
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        } elseif ($loanType === 'old') { // Customer paying back a loan
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } elseif ($loanType === 'today') { // Customer taking a new loan
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        }

        $incomeExpense->save();

        // If it's a loan (old or today), also save to CustomersLoan table (legacy/reporting)
        if (in_array($loanType, ['old', 'today']) && $incomeExpense->customer_id) {
            $loan = new CustomersLoan();
            $loan->fill($incomeExpense->getAttributes());
            $loan->amount = abs($validated['amount']); // Loans table traditionally stores absolute amount
            $loan->loan_type = $loanType;
            $loan->save();
            $incomeExpense->loan_id = $loan->id;
            $incomeExpense->save();
        }


        return response()->json(['message' => 'Loan and income/expense record created successfully!'], 201);
    }


    public function updateApi(Request $request, $id)
    {
        // NOTE: The request needs the ID to be passed either in URL or form data
        // The frontend passes it in the URL and spoofs PUT via POST with {id}

        // Base validation rules
        $rules = [
            'loan_type' => 'required|string|in:old,today,ingoing,outgoing,grn_damage',
            'settling_way' => 'nullable|string|in:cash,cheque',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'nullable|numeric|min:0', // Allows 0 amount for GRN
            'description' => 'required|string|max:255',
            'bill_no' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string|max:255',
            'bank' => 'nullable|string|max:255',
            'cheque_date' => 'nullable|date',
            'wasted_code' => 'nullable|string',
            'wasted_packs' => 'nullable|numeric',
            'wasted_weight' => 'nullable|numeric',
        ];

        // Conditional validation (adjusting rules for required fields)
        if (in_array($request->input('loan_type'), ['ingoing', 'outgoing'])) {
            $rules['amount'] = 'required|numeric';
            $rules['customer_id'] = 'nullable';
        } elseif ($request->input('loan_type') === 'grn_damage') {
            $rules['amount'] = 'nullable';
            $rules['wasted_code'] = 'required|string';
            $rules['wasted_packs'] = 'required|numeric';
            $rules['wasted_weight'] = 'required|numeric';
            $rules['description'] = 'nullable|string|max:255';
        } else {
            $rules['amount'] = 'required|numeric';
            $rules['customer_id'] = 'required|exists:customers,id';
        }

        $validated = $request->validate($rules);

        // Find the IncomeExpense record
        $incomeExpense = IncomeExpenses::findOrFail($id);

        // Try to get related loan if exists
        $loan = $incomeExpense->loan_id ? CustomersLoan::find($incomeExpense->loan_id) : null;

        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        // --- Update IncomeExpenses (Primary Record) ---
        $incomeExpense->loan_type = $validated['loan_type'];
        $incomeExpense->customer_id = $validated['customer_id'] ?? null;
        $incomeExpense->description = $validated['description'] ?? 'N/A';
        $incomeExpense->bill_no = $validated['bill_no'] ?? null;
        $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
        $incomeExpense->bank = $validated['bank'] ?? null;
        $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
        $incomeExpense->settling_way = $validated['settling_way'] ?? 'cash';
        $incomeExpense->customer_short_name = $customerShortName;
        $incomeExpense->ip_address = $request->ip();

        if ($validated['loan_type'] === 'ingoing' || $validated['loan_type'] === 'old') {
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } elseif ($validated['loan_type'] === 'outgoing' || $validated['loan_type'] === 'today') {
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        } elseif ($validated['loan_type'] === 'grn_damage') {
            // For simplicity, we assume a zero-value entry for tracking GRN changes
            $incomeExpense->amount = 0;
            $incomeExpense->type = 'expense';
            $incomeExpense->description = $validated['description'] ?? ("GRN Damage: " . $validated['wasted_code']);
        }

        $incomeExpense->save();

        // --- Update CustomersLoan (Secondary Record, if linked) ---
        if ($loan) {
            // Apply the same updates to the CustomersLoan record
            $loan->fill($incomeExpense->getAttributes());
            $loan->amount = abs($validated['amount']);
            $loan->save();
        }

        return response()->json(['message' => 'Record updated successfully!']);
    }


    public function destroy($id): JsonResponse
    {
        // Find the IncomeExpense record
        $incomeExpense = IncomeExpenses::findOrFail($id);

        // Check if linked loan exists
        if ($incomeExpense->loan_id) {
            $loan = CustomersLoan::find($incomeExpense->loan_id);

            if ($loan) {
                $loan->delete();
            }
        }

        // Delete the income/expense record
        $incomeExpense->delete();

        return response()->json(['message' => 'Record deleted successfully!'], 200);
    }

    public function getTotalLoanAmount($customerId): JsonResponse
    {
        $totals = IncomeExpenses::where('customer_id', $customerId)
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', 'return');
            })
            ->selectRaw("
                SUM(CASE WHEN loan_type = 'today' THEN amount ELSE 0 END) AS today_sum,
                SUM(CASE WHEN loan_type = 'old' THEN amount ELSE 0 END) AS old_sum
            ")
            ->first();

        // today_sum holds negative values (loans given), old_sum holds positive values (loans repaid)
        $todaySum = (float) ($totals->today_sum ?? 0);
        $oldSum = (float) ($totals->old_sum ?? 0);

        $totalAmount = $todaySum + $oldSum; // Sum of expenses (negative) and income (positive)

        return response()->json([
            'old_sum' => $oldSum,
            'today_sum' => $todaySum,
            'total_amount' => $totalAmount, // This should be negative if the customer owes money
        ]);
    }

    // NEW: Get GRN Entry by Code for returns autofill.
    public function getGrnEntry($code)
    {
        $grnEntry = GrnEntry::where('code', $code)->first();
        if ($grnEntry) {
            return response()->json(['item_code' => $grnEntry->item_code]);
        }
        return response()->json(['item_code' => ''], 404);
    }

    // NEW: Get all Bill Nos.
    public function getAllBillNos()
    {
        $billNos = Sale::distinct()->pluck('bill_no');
        return response()->json($billNos);
    }
   
    public function update(Request $request, $id):JsonResponse
    {
        // Base validation rules
        $rules = [
            'loan_type' => 'required|string|in:old,today,ingoing,outgoing,grn_damage',
            'settling_way' => 'nullable|string|in:cash,cheque',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'required|string|max:255',
            'bill_no' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string|max:255',
            'bank' => 'nullable|string|max:255',
            'cheque_date' => 'nullable|date',
            'wasted_code' => 'nullable|string',
            'wasted_packs' => 'nullable|numeric',
            'wasted_weight' => 'nullable|numeric',
        ];

        // Conditional validation (adjusting rules for required fields)
        if (in_array($request->input('loan_type'), ['ingoing', 'outgoing'])) {
            $rules['amount'] = 'required|numeric';
            $rules['customer_id'] = 'nullable';
        } elseif ($request->input('loan_type') === 'grn_damage') {
            $rules['amount'] = 'nullable';
            $rules['wasted_code'] = 'required|string';
            $rules['wasted_packs'] = 'required|numeric';
            $rules['wasted_weight'] = 'required|numeric';
            $rules['description'] = 'nullable|string|max:255';
        } else {
            $rules['amount'] = 'required|numeric';
            $rules['customer_id'] = 'required|exists:customers,id';
        }

        try {
            $validated = $request->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        // Find the IncomeExpense record
        $incomeExpense = IncomeExpenses::findOrFail($id); // Ensure correct namespace/model

        // Try to get related loan if exists
        $loan = $incomeExpense->loan_id ? CustomersLoan::find($incomeExpense->loan_id) : null; // Ensure correct namespace/model

        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        // --- Update IncomeExpenses (Primary Record) ---
        $incomeExpense->loan_type = $validated['loan_type'];
        $incomeExpense->customer_id = $validated['customer_id'] ?? null;
        $incomeExpense->description = $validated['description'] ?? 'N/A';
        $incomeExpense->bill_no = $validated['bill_no'] ?? null;
        $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
        $incomeExpense->bank = $validated['bank'] ?? null;
        $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
        $incomeExpense->settling_way = $validated['settling_way'] ?? 'cash';
        $incomeExpense->customer_short_name = $customerShortName;
        $incomeExpense->ip_address = $request->ip();

        if ($validated['loan_type'] === 'ingoing' || $validated['loan_type'] === 'old') {
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } elseif ($validated['loan_type'] === 'outgoing' || $validated['loan_type'] === 'today') {
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        } elseif ($validated['loan_type'] === 'grn_damage') {
            // For simplicity, we assume a zero-value entry for tracking GRN changes
            $incomeExpense->amount = 0;
            $incomeExpense->type = 'expense';
            $incomeExpense->description = $validated['description'] ?? ("GRN Damage: " . $validated['wasted_code']);
        }

        $incomeExpense->save();

        // --- Update CustomersLoan (Secondary Record, if linked) ---
        if ($loan) {
            // Apply the same updates to the CustomersLoan record
            $loan->fill($incomeExpense->getAttributes());
            $loan->amount = abs($validated['amount']);
            $loan->save();
        }

        return response()->json(['message' => 'Record updated successfully!']);
    }
}