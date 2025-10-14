<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomersLoan;
use App\Models\IncomeExpenses;
use App\Models\GrnEntry;
use App\Models\Setting;

class CustomersLoanController extends Controller
{
    // API: Get all loans with customers and GRN codes
    public function index()
    {
        $customers = Customer::all();
        $grnCodes = GrnEntry::distinct()->pluck('code');
        $settingDate = Setting::value('value') ?? now()->toDateString();

        $loans = IncomeExpenses::with('customer')
            ->whereDate('Date', $settingDate)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'customers' => $customers,
            'grnCodes' => $grnCodes,
            'loans' => $loans
        ]);
    }

    // API: Get customer loan totals
    public function getCustomerLoanTotal($customerId)
    {
        $totals = IncomeExpenses::where('customer_id', $customerId)
            ->where(function($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'return');
            })
            ->selectRaw("
                SUM(CASE WHEN loan_type = 'today' THEN ABS(amount) ELSE 0 END) AS today_sum,
                SUM(CASE WHEN loan_type = 'old' THEN ABS(amount) ELSE 0 END) AS old_sum
            ")
            ->first();

        $todaySum = (float) ($totals->today_sum ?? 0);
        $oldSum = (float) ($totals->old_sum ?? 0);
        $totalAmount = $todaySum - $oldSum;

        return response()->json([
            'old_sum' => $oldSum,
            'today_sum' => $todaySum,
            'total_amount' => $totalAmount,
        ]);
    }

    // API: Store new loan
    public function store(Request $request)
    {
        $settingDate = Setting::value('value') ?? now()->toDateString();

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
        ];

        $loanType = $request->input('loan_type');
        
        if ($loanType === 'ingoing' || $loanType === 'outgoing') {
            $rules['amount'] = 'required|numeric';
        } elseif ($loanType === 'grn_damage') {
            $rules['wasted_code'] = 'required|string';
            $rules['wasted_packs'] = 'required|numeric';
            $rules['wasted_weight'] = 'required|numeric';
        } else {
            $rules['amount'] = 'required|numeric';
        }

        $validated = $request->validate($rules);

        // Handle GRN Damage
        if ($loanType === 'grn_damage') {
            $grnEntry = GrnEntry::where('code', $validated['wasted_code'])->first();
            if (!$grnEntry) {
                return response()->json(['error' => 'GRN code not found.'], 422);
            }
            $grnEntry->packs = max(0, $grnEntry->packs - $validated['wasted_packs']);
            $grnEntry->weight = max(0, $grnEntry->weight - $validated['wasted_weight']);
            $grnEntry->save();

            return response()->json(['message' => 'GRN stock updated successfully!']);
        }

        // Handle Returns
        if ($loanType === 'returns') {
            $incomeExpense = new IncomeExpenses();
            $incomeExpense->loan_type = 'returns';
            $incomeExpense->GRN_Code = $request->return_grn_code;
            $incomeExpense->Item_Code = $request->return_item_code;
            $incomeExpense->Bill_no = $request->return_bill_no;
            $incomeExpense->weight = $request->return_weight;
            $incomeExpense->packs = $request->return_packs;
            $incomeExpense->Reason = $request->return_reason;
            $incomeExpense->amount = 0;
            $incomeExpense->type = 'expense';
            $incomeExpense->date = $settingDate;
            $incomeExpense->ip_address = $request->ip();
            $incomeExpense->save();

            return response()->json(['message' => 'Return record added successfully!']);
        }

        // Handle other loan types
        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        // Create CustomersLoan record
        $loan = new CustomersLoan();
        $loan->loan_type = $validated['loan_type'];
        $loan->settling_way = $validated['settling_way'] ?? null;
        $loan->customer_id = $validated['customer_id'] ?? null;
        $loan->amount = $validated['amount'] ?? 0;
        $loan->description = $validated['description'] ?? 'N/A';
        $loan->customer_short_name = $customerShortName;
        $loan->bill_no = $validated['bill_no'] ?? null;
        $loan->cheque_no = $validated['cheque_no'] ?? null;
        $loan->bank = $validated['bank'] ?? null;
        $loan->cheque_date = $validated['cheque_date'] ?? null;
        $loan->date = $settingDate;
        $loan->ip_address = $request->ip();
        $loan->save();

        // Create IncomeExpenses record
        $incomeExpense = new IncomeExpenses();
        $incomeExpense->loan_id = $loan->id;
        $incomeExpense->loan_type = $validated['loan_type'];
        $incomeExpense->customer_id = $validated['customer_id'] ?? null;
        $incomeExpense->description = $validated['description'];
        $incomeExpense->bill_no = $validated['bill_no'] ?? null;
        $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
        $incomeExpense->bank = $validated['bank'] ?? null;
        $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
        $incomeExpense->settling_way = $validated['settling_way'] ?? null;
        $incomeExpense->customer_short_name = $customerShortName;
        $incomeExpense->date = $settingDate;
        $incomeExpense->ip_address = $request->ip();

        if ($loanType === 'old' || $loanType === 'ingoing') {
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } else {
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        }

        $incomeExpense->save();

        return response()->json(['message' => 'Loan record created successfully!']);
    }

    // API: Update loan
    public function update(Request $request, $id)
    {
        $rules = [
            'loan_type' => 'required|string|in:old,today,ingoing,outgoing,grn_damage',
            'settling_way' => 'nullable|string|in:cash,cheque',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'bill_no' => 'nullable|string|max:255',
            'cheque_no' => 'nullable|string|max:255',
            'bank' => 'nullable|string|max:255',
            'cheque_date' => 'nullable|date',
        ];

        $validated = $request->validate($rules);

        $incomeExpense = IncomeExpenses::findOrFail($id);
        $loan = $incomeExpense->loan_id ? CustomersLoan::find($incomeExpense->loan_id) : null;

        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        // Update CustomersLoan if exists
        if ($loan) {
            $loan->update([
                'loan_type' => $validated['loan_type'],
                'settling_way' => $validated['settling_way'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'amount' => $validated['amount'],
                'description' => $validated['description'],
                'customer_short_name' => $customerShortName,
                'bill_no' => $validated['bill_no'] ?? null,
                'cheque_no' => $validated['cheque_no'] ?? null,
                'bank' => $validated['bank'] ?? null,
                'cheque_date' => $validated['cheque_date'] ?? null,
            ]);
        }

        // Update IncomeExpenses
        $incomeExpense->loan_type = $validated['loan_type'];
        $incomeExpense->customer_id = $validated['customer_id'] ?? null;
        $incomeExpense->description = $validated['description'];
        $incomeExpense->bill_no = $validated['bill_no'] ?? null;
        $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
        $incomeExpense->bank = $validated['bank'] ?? null;
        $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
        $incomeExpense->settling_way = $validated['settling_way'] ?? null;
        $incomeExpense->customer_short_name = $customerShortName;

        if ($validated['loan_type'] === 'old' || $validated['loan_type'] === 'ingoing') {
            $incomeExpense->amount = $validated['amount'];
            $incomeExpense->type = 'income';
        } else {
            $incomeExpense->amount = -$validated['amount'];
            $incomeExpense->type = 'expense';
        }

        $incomeExpense->save();

        return response()->json(['message' => 'Record updated successfully!']);
    }

    // API: Delete loan
    public function destroy($id)
    {
        $incomeExpense = IncomeExpenses::findOrFail($id);

        if ($incomeExpense->loan_id) {
            $loan = CustomersLoan::find($incomeExpense->loan_id);
            if ($loan) {
                $loan->delete();
            }
        }

        $incomeExpense->delete();

        return response()->json(['message' => 'Record deleted successfully!']);
    }
}