<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Debtor;
use App\Models\Sale;
use App\Models\Creditor;
use App\Models\Supplier;
use App\Models\SupplierLoan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DebtorCreditorController extends Controller
{
    /**
     * Helper to get history as array
     */
    private function parseHistory($history)
    {
        if (is_string($history)) {
            return json_decode($history, true) ?: [];
        }
        return is_array($history) ? $history : [];
    }

    /**
     * Centralized payment calculator to ensure consistency
     */
    private function calculatePaymentTotals($paymentHistory)
    {
        $history = $this->parseHistory($paymentHistory);
        $actualPaid = 0;
        $creditDeductions = 0;

        foreach ($history as $payment) {
            $amount = floatval($payment['amount'] ?? 0);
            if (strtolower($payment['method'] ?? '') === 'credit') {
                $creditDeductions += $amount;
            } else {
                $actualPaid += $amount;
            }
        }

        return [
            'paid' => $actualPaid,
            'deductions' => $creditDeductions
        ];
    }

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
            return response()->json(['success' => false, 'message' => 'Error generating report'], 500);
        }
    }

    public function getDebtorReport(Request $request)
    {
        try {
            $search = $request->query('search');
            $limit = $request->query('limit', 50);

            $customerIds = Customer::where('Debtor', 'Y')->pluck('short_name')
                ->merge(Debtor::whereNotNull('customer_code')->distinct()->pluck('customer_code'))
                ->unique()->values();

            $debtorsQuery = Customer::whereIn('short_name', $customerIds);
            
            if ($search) {
                $debtorsQuery->where(function ($q) use ($search) {
                    $q->where('short_name', 'LIKE', "%{$search}%")
                      ->orWhere('name', 'LIKE', "%{$search}%")
                      ->orWhere('telephone_no', 'LIKE', "%{$search}%");
                });
            }

            $debtors = $debtorsQuery->take($limit)->get();
            
            $allSales = Sale::whereIn('customer_code', $debtors->pluck('short_name'))
                ->where('bill_printed', 'Y')
                ->select('customer_code', 'bill_no', 'payment_history', 
                    DB::raw('SUM(total + COALESCE(packs, 0) * COALESCE(CustomerPackCost, 0)) as bill_total'))
                ->groupBy('customer_code', 'bill_no', 'payment_history')
                ->get()
                ->groupBy('customer_code');

            $allLegacy = Debtor::whereIn('customer_code', $debtors->pluck('short_name'))->get()->groupBy('customer_code');

            $debtorData = [];
            $summary = ['sales' => 0, 'paid' => 0, 'rem' => 0];

            foreach ($debtors as $customer) {
                $netSales = 0; $actualPaid = 0; $billCount = 0;

                if (isset($allSales[$customer->short_name])) {
                    foreach ($allSales[$customer->short_name] as $bill) {
                        $p = $this->calculatePaymentTotals($bill->payment_history);
                        $netSales += (floatval($bill->bill_total) - $p['deductions']);
                        $actualPaid += $p['paid'];
                        $billCount++;
                    }
                }

                if (isset($allLegacy[$customer->short_name])) {
                    foreach ($allLegacy[$customer->short_name] as $record) {
                        $netSales += floatval($record->credit_amount);
                        $actualPaid += floatval($record->paid_amount);
                        $billCount++;
                    }
                }

                $rem = max(0, $netSales - $actualPaid);
                $debtorData[] = [
                    'code' => $customer->short_name,
                    'name' => $customer->name,
                    'telephone' => $customer->telephone_no,
                    'address' => $customer->address,
                    'total_sales' => $netSales,
                    'total_paid' => $actualPaid,
                    'total_remaining' => $rem,
                    'bill_count' => $billCount,
                    'status' => $rem <= 0 ? 'Fully Paid' : 'Pending'
                ];
                $summary['sales'] += $netSales; $summary['paid'] += $actualPaid; $summary['rem'] += $rem;
            }

            return response()->json(['success' => true, 'data' => $debtorData, 'summary' => [
                'total_debtors' => count($debtorData),
                'total_sales_amount' => $summary['sales'],
                'total_paid_amount' => $summary['paid'],
                'total_remaining_amount' => $summary['rem']
            ]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getCreditorReport(Request $request)
    {
        try {
            $search = $request->query('search');
            $limit = $request->query('limit', 50);

            $supplierIds = Supplier::where('Creditor', 'Y')->pluck('code')
                ->merge(Creditor::whereNotNull('supplier_code')->distinct()->pluck('supplier_code'))
                ->unique()->values();

            $creditors = Supplier::whereIn('code', $supplierIds)
                ->when($search, fn($q) => $q->where('code', 'LIKE', "%$search%")->orWhere('name', 'LIKE', "%$search%"))
                ->take($limit)->get();

            $allBills = Sale::whereIn('supplier_code', $creditors->pluck('code'))
                ->where('supplier_bill_printed', 'Y')
                ->select('supplier_code', 'supplier_bill_no', DB::raw('SUM(SupplierTotal) as total'), DB::raw('SUM(COALESCE(supplier_paid_amount, 0)) as paid'))
                ->groupBy('supplier_code', 'supplier_bill_no')->get()->groupBy('supplier_code');

            $allLegacy = Creditor::whereIn('supplier_code', $creditors->pluck('code'))->get()->groupBy('supplier_code');
            $allLoans = SupplierLoan::whereIn('code', $creditors->pluck('code'))->get()->groupBy('code');

            $creditorData = [];
            $gt = ['sup' => 0, 'paid' => 0, 'rem' => 0];

            foreach ($creditors as $supplier) {
                $sTotal = 0; $sPaid = 0; $count = 0;

                if (isset($allBills[$supplier->code])) {
                    $sTotal += $allBills[$supplier->code]->sum('total');
                    $sPaid += $allBills[$supplier->code]->sum('paid');
                    $count += $allBills[$supplier->code]->count();
                }

                if (isset($allLegacy[$supplier->code])) {
                    $sTotal += $allLegacy[$supplier->code]->sum('credit_amount');
                    $sPaid += $allLegacy[$supplier->code]->sum('paid_amount');
                    $count += $allLegacy[$supplier->code]->count();
                }

                if (isset($allLoans[$supplier->code])) {
                    foreach ($allLoans[$supplier->code] as $loan) {
                        $lp = $this->calculatePaymentTotals($loan->payment_details);
                        $sTotal += (floatval($loan->loan_amount) - $lp['deductions']);
                        $sPaid += $lp['paid'];
                        $count++;
                    }
                }

                $rem = max(0, $sTotal - $sPaid);
                $creditorData[] = [
                    'code' => $supplier->code, 'name' => $supplier->name, 'total_supplier_amount' => $sTotal,
                    'total_paid' => $sPaid, 'total_remaining' => $rem, 'bill_count' => $count,
                    'status' => $rem <= 0 ? 'Fully Settled' : 'Pending'
                ];
                $gt['sup'] += $sTotal; $gt['paid'] += $sPaid; $gt['rem'] += $rem;
            }

            return response()->json(['success' => true, 'data' => $creditorData, 'summary' => [
                'total_creditors' => count($creditorData), 'total_supplier_amount' => $gt['sup'],
                'total_paid_amount' => $gt['paid'], 'total_remaining_amount' => $gt['rem']
            ]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getDebtorDetails($code)
    {
        try {
            $customer = Customer::where('short_name', $code)->first();
            if (!$customer) return response()->json(['success' => false], 404);

            $bills = Sale::where('customer_code', $code)
                ->where('bill_printed', 'Y')
                ->select('bill_no', 'payment_history', 'created_at', DB::raw('SUM(total + COALESCE(packs, 0) * COALESCE(CustomerPackCost, 0)) as total_amount'))
                ->groupBy('bill_no', 'payment_history', 'created_at')
                ->orderBy('created_at', 'desc')->get()
                ->map(function ($bill) {
                    $p = $this->calculatePaymentTotals($bill->payment_history);
                    $netBill = floatval($bill->total_amount) - $p['deductions'];
                    return [
                        'bill_no' => $bill->bill_no, 'created_at' => $bill->created_at, 'total_amount' => $netBill,
                        'paid_amount' => $p['paid'], 'remaining_amount' => max(0, $netBill - $p['paid'])
                    ];
                });

            $payments = [];
            $salesRecords = Sale::where('customer_code', $code)->whereNotNull('payment_history')->get();
            foreach ($salesRecords as $sale) {
                foreach ($this->parseHistory($sale->payment_history) as $p) {
                    if (strtolower($p['method'] ?? '') !== 'credit') {
                        $payments[] = array_merge($p, ['bill_no' => $sale->bill_no, 'method_display' => $this->getPaymentMethodDisplay($p['method'] ?? 'Cash')]);
                    }
                }
            }

            return response()->json(['success' => true, 'data' => [
                'code' => $customer->short_name, 'name' => $customer->name, 'bills' => $bills, 'payments' => $payments,
                'total_bill_amount' => $bills->sum('total_amount'), 'total_paid_amount' => $bills->sum('paid_amount'),
                'total_remaining' => $bills->sum('remaining_amount')
            ]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getCreditorDetails($code)
    {
        try {
            $supplier = Supplier::where('code', $code)->first();
            if (!$supplier) return response()->json(['success' => false, 'message' => 'Supplier not found'], 404);

            $bills = Sale::where('supplier_code', $code)->where('supplier_bill_printed', 'Y')
                ->select('supplier_bill_no', 'created_at', 'SupplierTotal as total_amount', 'supplier_paid_amount as paid_amount')
                ->orderBy('created_at', 'desc')->get()
                ->map(fn($b) => [
                    'bill_no' => $b->supplier_bill_no, 'created_at' => $b->created_at, 'total_amount' => floatval($b->total_amount),
                    'paid_amount' => floatval($b->paid_amount), 'remaining_amount' => max(0, floatval($b->total_amount) - floatval($b->paid_amount)),
                    'type' => 'Sale Bill', 'is_fully_settled' => (floatval($b->total_amount) - floatval($b->paid_amount)) <= 0
                ]);

            $loans = SupplierLoan::where('code', $code)->orderBy('Date', 'desc')->get()
                ->map(function($l) {
                    $p = $this->calculatePaymentTotals($l->payment_details);
                    $net = floatval($l->loan_amount) - $p['deductions'];
                    return [
                        'bill_no' => $l->bill_no, 'loan_amount' => floatval($l->loan_amount), 'net_amount' => $net,
                        'paid_amount' => $p['paid'], 'remaining_amount' => max(0, $net - $p['paid']), 'date' => $l->Date,
                        'type' => $l->type, 'is_fully_settled' => ($net - $p['paid']) <= 0
                    ];
                });

            return response()->json(['success' => true, 'data' => [
                'code' => $supplier->code, 'name' => $supplier->name, 'bills' => $bills, 'loans' => $loans,
                'payments' => $this->getCreditorPaymentHistory($code),
                'total_amount' => $bills->sum('total_amount') + $loans->sum('net_amount'),
                'total_paid' => $bills->sum('paid_amount') + $loans->sum('paid_amount'),
                'total_remaining' => $bills->sum('remaining_amount') + $loans->sum('remaining_amount')
            ]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function getCreditorPaymentHistory($supplierCode)
    {
        $payments = [];
        $sales = Sale::where('supplier_code', $supplierCode)->whereNotNull('payment_history')->get();
        foreach ($sales as $sale) {
            foreach ($this->parseHistory($sale->payment_history) as $p) {
                if (strtolower($p['method'] ?? '') !== 'credit') {
                    $payments[] = [
                        'bill_no' => $sale->supplier_bill_no ?? $sale->bill_no, 'amount' => $p['amount'] ?? 0,
                        'method_display' => $this->getPaymentMethodDisplay($p['method'] ?? 'Cash'), 'date' => $p['date'] ?? $sale->created_at
                    ];
                }
            }
        }
        return $payments;
    }

    private function getPaymentMethodDisplay($method)
    {
        $methods = ['Cash' => 'Cash', 'Cheque' => 'Cheque', 'Bank Transfer' => 'Bank Transfer', 'credit' => 'Credit'];
        return $methods[$method] ?? ucfirst($method);
    }
}