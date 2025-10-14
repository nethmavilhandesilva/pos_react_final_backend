<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomersLoan;
use App\Models\IncomeExpenses; // This is the correct model name
use Carbon\Carbon;
use App\Models\GrnEntry;
use App\Models\Setting;


class CustomersLoanController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
{
    // Fetch all customers for the dropdown/search
    $customers = Customer::all();

    // Fetch distinct codes from GrnEntry for the wasted dropdown
    $grnCodes = GrnEntry::distinct()->pluck('code');

    // Get the date from Setting model or use today as fallback
    $settingDate = Setting::value('value') ?? now()->toDateString();

    // Start the query to fetch loans with related customers
    $query = IncomeExpenses::with('customer');

    // Apply customer filter if provided
    if ($request->filled('filter_customer')) {
        $query->where('customer_id', $request->filter_customer);
    }

    // Only show records where Date column equals $settingDate
    $query->whereDate('Date', $settingDate);

    // Execute the query
    $loans = $query->orderBy('created_at', 'desc')->get();

    // Return the view
    return view('dashboard.customers_loans.index', compact('customers', 'loans', 'grnCodes'));
}

  public function store(Request $request)
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
        $rules['return_item_code'] = 'required|string';
        $rules['return_bill_no'] = 'required|string';
        $rules['return_weight'] = 'required|numeric';
        $rules['return_packs'] = 'required|numeric';
        $rules['return_reason'] = 'nullable|string|max:255';
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
            return back()->with('error', 'GRN code not found.');
        }
        $grnEntry->packs = max(0, $grnEntry->packs - $validated['wasted_packs']);
        $grnEntry->weight = max(0, $grnEntry->weight - $validated['wasted_weight']);
        $grnEntry->save();

        return redirect()->route('customers-loans.index')
            ->with('success', 'GRN stock updated successfully!');
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
        $incomeExpense->amount = 0;
        $incomeExpense->type = 'expense';
        $incomeExpense->date = $settingDate;
        $incomeExpense->save();
        $incomeExpense->ip_address = $request->ip(); 

        return redirect()->route('customers-loans.index')
            ->with('success', 'Return record added successfully!');
    }

    // --- Handle Ingoing / Outgoing ---
    if ($loanType === 'ingoing' || $loanType === 'outgoing') {
        $customerShortName = null;
        if (!empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $customerShortName = $customer->short_name;
            }
        }

        $incomeExpense = new IncomeExpenses();
        $incomeExpense->loan_type = $loanType;
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

        $incomeExpense->amount = ($loanType === 'ingoing') ? $validated['amount'] : -$validated['amount'];
        $incomeExpense->type = ($loanType === 'ingoing') ? 'income' : 'expense';
        $incomeExpense->save();

        return redirect()->route('customers-loans.index')
            ->with('success', 'Income/Expense record created successfully!');
    }

    // --- Handle Old / Today loans ---
    $customerShortName = null;
    if (!empty($validated['customer_id'])) {
        $customer = Customer::find($validated['customer_id']);
        if ($customer) {
            $customerShortName = $customer->short_name;
        }
    }

    $loan = new CustomersLoan();
    // If settling_way is cheque, force loan_type = 'today'
    $loan->loan_type = ($settlingWay === 'cheque') ? 'old' : $validated['loan_type'];
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

    $incomeExpense = new IncomeExpenses();
    $incomeExpense->loan_id = $loan->id;
    // Same: force loan_type = 'today' if settling_way is cheque
    $incomeExpense->loan_type = ($settlingWay === 'cheque') ? 'old' : $loanType;
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

    if ($loanType === 'old') {
        $incomeExpense->amount = $validated['amount'];
        $incomeExpense->type = 'income';
    } elseif ($loanType === 'today' || $settlingWay === 'cheque') {
        // Force expense if today or cheque
        $incomeExpense->amount = -$validated['amount'];
        $incomeExpense->type = 'expense';
    }

    $incomeExpense->save();

    return redirect()->route('customers-loans.index')
        ->with('success', 'Loan and income/expense record created successfully!');
}


public function update(Request $request, $id)
{
    // Base validation rules
    $rules = [
        'loan_type' => 'required|string|in:old,today,ingoing,outgoing,grn_damage',
        'settling_way' => 'nullable|string|in:cash,cheque',
        'customer_id' => 'nullable|exists:customers,id',
        'amount' => 'nullable|numeric|min:0.01',
        'description' => 'required|string|max:255',
        'bill_no' => 'nullable|string|max:255',
        'cheque_no' => 'nullable|string|max:255',
        'bank' => 'nullable|string|max:255',
        'cheque_date' => 'nullable|date',
        'wasted_code' => 'nullable|string',
        'wasted_packs' => 'nullable|numeric',
        'wasted_weight' => 'nullable|numeric',
    ];

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
        if ($request->input('settling_way') === 'cheque') {
            $rules['cheque_no'] = 'nullable|string|max:255';
            $rules['bank'] = 'nullable|string|max:255';
            $rules['cheque_date'] = 'nullable|date';
        }
    }

    $validated = $request->validate($rules);

    // Find the IncomeExpense record (not CustomersLoan directly)
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

    // --- Update CustomersLoan record only if it exists ---
    if ($loan) {
        if (in_array($validated['loan_type'], ['old', 'today', 'grn_damage'])) {
            if ($validated['loan_type'] === 'grn_damage') {
                $loan->update([
                    'loan_type' => $validated['loan_type'],
                    'grn_code' => $validated['wasted_code'],
                    'wasted_packs' => $validated['wasted_packs'],
                    'wasted_weight' => $validated['wasted_weight'],
                    'description' => $validated['description'] ?? 'N/A',
                ]);
            } else {
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
        } elseif (in_array($validated['loan_type'], ['ingoing', 'outgoing'])) {
            if ($validated['settling_way'] === 'cheque') {
                $loan->update([
                    'loan_type' => $validated['loan_type'],
                    'settling_way' => $validated['settling_way'],
                    'cheque_no' => $validated['cheque_no'],
                    'bank' => $validated['bank'],
                    'cheque_date' => $validated['cheque_date'],
                    'bill_no' => $validated['bill_no'] ?? null,
                    'description' => $validated['description'],
                ]);
            }
        }
    }

    // --- Update the IncomeExpenses record ---
    $incomeExpense->loan_type = $validated['loan_type'];
    $incomeExpense->customer_id = $validated['customer_id'] ?? null;
    $incomeExpense->description = $validated['description'] ?? 'N/A';
    $incomeExpense->bill_no = $validated['bill_no'] ?? null;
    $incomeExpense->cheque_no = $validated['cheque_no'] ?? null;
    $incomeExpense->bank = $validated['bank'] ?? null;
    $incomeExpense->cheque_date = $validated['cheque_date'] ?? null;
    $incomeExpense->settling_way = $validated['settling_way'] ?? null;
    $incomeExpense->customer_short_name = $customerShortName;

    if ($validated['loan_type'] === 'ingoing') {
        $incomeExpense->amount = $validated['amount'];
        $incomeExpense->type = 'income';
    } elseif ($validated['loan_type'] === 'outgoing') {
        $incomeExpense->amount = -$validated['amount'];
        $incomeExpense->type = 'expense';
    } elseif ($validated['loan_type'] === 'grn_damage') {
        $incomeExpense->amount = -($validated['wasted_weight'] * 10);
        $incomeExpense->description = "GRN Damage: " . $validated['wasted_code'];
        $incomeExpense->type = 'expense';
    } elseif ($validated['loan_type'] === 'old') {
        $incomeExpense->amount = $validated['amount'];
        $incomeExpense->type = 'income';
    } elseif ($validated['loan_type'] === 'today') {
        $incomeExpense->amount = -$validated['amount'];
        $incomeExpense->type = 'expense';
    }

    $incomeExpense->save();

    return response()->json(['message' => 'Record updated successfully!']);
}

    
public function destroy($id)
{
    // Find the IncomeExpense record
    $incomeExpense = IncomeExpenses::findOrFail($id);

    // Check if linked loan exists
    if ($incomeExpense->loan_id) {
        $loan = CustomersLoan::find($incomeExpense->loan_id);

        if ($loan) {
            // Delete only for loan-related types
            if (in_array($incomeExpense->loan_type, ['old', 'today', 'grn_damage'])) {
                $loan->delete();
            } elseif (in_array($incomeExpense->loan_type, ['ingoing', 'outgoing']) 
                      && $incomeExpense->settling_way === 'cheque') {
                // Optional: only delete if cheque-based
                $loan->delete();
            }
        }
    }

    // Delete the income/expense record
    $incomeExpense->delete();

   return redirect()->back()->with('success', 'Record deleted successfully!');
}

public function getTotalLoanAmount($customerId)
{
    $totals = IncomeExpenses::where('customer_id', $customerId)
        ->where(function($q) {
            $q->whereNull('status')
              ->orWhere('status', '!=', 'return');
        })
        ->selectRaw("
            SUM(CASE 
                    WHEN loan_type = 'today' THEN ABS(amount)
                    ELSE 0
                END) AS today_sum,
            SUM(CASE 
                    WHEN loan_type = 'old' THEN ABS(amount)
                    ELSE 0
                END) AS old_sum
        ")
        ->first();

    $todaySum = (float) ($totals->today_sum ?? 0);
    $oldSum   = (float) ($totals->old_sum ?? 0);

    $totalAmount = $todaySum - $oldSum; // sum as positive numbers

    return response()->json([
        'old_sum'      => $oldSum,
        'today_sum'    => $todaySum,
        'total_amount' => $totalAmount,
    ]);
}



   public function loanReportResults(Request $request)
{
    // Get the date from Setting or default to today
    $settingDate = Setting::value('value') ?? now()->toDateString();

    $query = CustomersLoan::query();

    // Filter by customer if selected
    if ($request->filled('customer_short_name')) {
        $query->where('customer_short_name', $request->customer_short_name);
    }

    // Apply date filtering
    if ($request->filled('start_date') && $request->filled('end_date')) {
        // Both dates given → filter between them
        $query->whereBetween('Date', [$request->start_date, $request->end_date]);
    } elseif ($request->filled('start_date')) {
        // Only start date given → from start_date until now
        $query->whereDate('Date', '>=', $request->start_date);
    } elseif ($request->filled('end_date')) {
        // Only end date given → until end_date
        $query->whereDate('Date', '<=', $request->end_date);
    
    }

    // Fetch results ordered by Date desc
    $loans = $query->orderBy('Date', 'desc')->get();

    return view('dashboard.reports.loan-results', compact('loans'));
}



    /**
     * Show loan report.
     *
     * @return \Illuminate\View\View
     */
    public function loanReport()
{
    $allLoans = CustomersLoan::all();
    $groupedLoans = $allLoans->groupBy('customer_short_name');

    // 🔑 Get all customers with Non realized status from IncomeExpenses
   $nonRealizedCustomers = IncomeExpenses::where('status', 'Non realized')
    ->where('settling_way', 'Cheque')
    ->pluck('customer_short_name')
    ->toArray();


    $finalLoans = [];

    foreach ($groupedLoans as $customerShortName => $loans) {
        $lastOldLoan = $loans->where('loan_type', 'old')->sortByDesc('created_at')->first();
        $firstTodayAfterOld = $loans->where('loan_type', 'today')
                                    ->where('created_at', '>', $lastOldLoan->created_at ?? '1970-01-01')
                                    ->sortBy('created_at')
                                    ->first();

        $highlightColor = null;

        if ($lastOldLoan && $firstTodayAfterOld) {
            $daysBetweenLoans = Carbon::parse($lastOldLoan->created_at)
                                      ->diffInDays(Carbon::parse($firstTodayAfterOld->created_at));

            if ($daysBetweenLoans > 30) {
                $highlightColor = 'red-highlight';
            } elseif ($daysBetweenLoans >= 14 && $daysBetweenLoans <= 30) {
                $highlightColor = 'blue-highlight';
            }

            $extraTodayLoanExists = $loans->where('loan_type', 'today')
                                          ->where('created_at', '>', $firstTodayAfterOld->created_at)
                                          ->count() > 0;

            if ($extraTodayLoanExists) {
                $highlightColor = null;
            }
        } elseif ($lastOldLoan && !$firstTodayAfterOld) {
            $daysSinceLastOldLoan = Carbon::parse($lastOldLoan->created_at)
                                           ->diffInDays(Carbon::now());

            if ($daysSinceLastOldLoan > 30) {
                $highlightColor = 'red-highlight';
            } elseif ($daysSinceLastOldLoan >= 14 && $daysSinceLastOldLoan <= 30) {
                $highlightColor = 'blue-highlight';
            }
        }

        $totalToday = $loans->where('loan_type', 'today')->sum('amount');
        $totalOld = $loans->where('loan_type', 'old')->sum('amount');
        $totalAmount = $totalToday - $totalOld;

        // 🔥 If this customer has Non realized cheques → force orange highlight
        if (in_array($customerShortName, $nonRealizedCustomers)) {
            $highlightColor = 'orange-highlight';
        }

        $finalLoans[] = (object) [
            'customer_short_name' => $customerShortName,
            'total_amount' => $totalAmount,
            'highlight_color' => $highlightColor,
        ];
    }

    return view('dashboard.reports.loan-report', ['loans' => collect($finalLoans)]);
}
}