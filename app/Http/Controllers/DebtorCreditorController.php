<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\Creditor;
use App\Models\Supplier;
use App\Models\SupplierLoan;
use App\Models\Debtor; // Add this import
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
     * This now also checks the debtors table for payments
     */
   private function calculatePaymentTotals($paymentHistory, $billNo = null, $customerCode = null)
{
    $history = $this->parseHistory($paymentHistory);
    
    $actualPaid = 0;
    $creditDeductions = 0;
    $creditAmount = 0;  // Add this
    
    // Get payments from payment_history
    foreach ($history as $payment) {
        $method = strtolower(trim($payment['method'] ?? ''));
        $amount = floatval($payment['amount'] ?? 0);
        
        if ($method === 'credit') {
            $creditAmount += $amount;  // Track credit amounts
            $creditDeductions += $amount;
        } else {
            $actualPaid += $amount;
        }
    }
    
    // Also check the debtors table for additional payments
    if ($billNo && $customerCode) {
        $debtorRecord = Debtor::where('bill_no', $billNo)
            ->where('customer_code', $customerCode)
            ->first();
            
        if ($debtorRecord && $debtorRecord->paid_amount > 0) {
            // Don't add if already counted, just ensure we have the correct amount
            if ($actualPaid == 0 && $debtorRecord->paid_amount > 0) {
                $actualPaid = $debtorRecord->paid_amount;
            }
        }
    }
    
    return [
        'paid' => $actualPaid,
        'deductions' => $creditDeductions,
        'credit_amount' => $creditAmount  // Return credit amount
    ];
}

    /**
     * Get the appropriate model based on view_old_bills parameter
     */
    private function getSaleModel($viewOldBills = false)
    {
        return $viewOldBills ? SalesHistory::class : Sale::class;
    }

    /**
     * Get combined report for both debtors and creditors
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
            return response()->json(['success' => false, 'message' => 'Error generating report'], 500);
        }
    }

    /**
     * Get debtor report - FIXED DOUBLE COUNTING
     */
   public function getDebtorReport(Request $request)
{
    try {
        $search = $request->query('search');
        $limit = $request->query('limit', 50);
        $viewOldBills = filter_var($request->query('view_old_bills', false), FILTER_VALIDATE_BOOLEAN);

        // Get all customer codes that are debtors
        $customerIds = Customer::where('Debtor', 'Y')->pluck('short_name')
            ->unique()
            ->values();

        $debtorsQuery = Customer::whereIn('short_name', $customerIds);

        if ($search) {
            $debtorsQuery->where(function ($q) use ($search) {
                $q->where('short_name', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('telephone_no', 'LIKE', "%{$search}%")
                    ->orWhere('Debtor_no', 'LIKE', "%{$search}%");
            });
        }

        $debtors = $debtorsQuery->take($limit)->get();

        // Get the appropriate model based on view_old_bills
        $saleModel = $this->getSaleModel($viewOldBills);
        
        // Get all sales/bills for these customers
        $allSales = $saleModel::whereIn('customer_code', $debtors->pluck('short_name'))
            ->where('bill_printed', 'Y')
            ->get()
            ->groupBy('customer_code');

        $debtorData = [];
        $summary = [
            'sales' => 0,
            'paid' => 0,
            'rem' => 0,
            'credit_deductions' => 0,
            'credit_amounts' => 0  // Add this to track total credits
        ];

        foreach ($debtors as $customer) {
            $netSales = 0;
            $actualPaid = 0;
            $creditDeduction = 0;
            $totalCreditAmount = 0;  // Track total credit for this customer
            $billCount = 0;
            
            // Process bills grouped by bill_no to avoid double counting
            $processedBills = [];

            // Process current sales/bills
            if (isset($allSales[$customer->short_name])) {
                foreach ($allSales[$customer->short_name] as $bill) {
                    $billNo = $bill->bill_no;
                    
                    // Skip if we've already processed this bill number
                    if (isset($processedBills[$billNo])) {
                        continue;
                    }
                    
                    // Calculate bill total
                    $billTotal = floatval($bill->total);
                    if (isset($bill->packs) && $bill->packs > 0 && isset($bill->CustomerPackCost)) {
                        $billTotal += floatval($bill->packs) * floatval($bill->CustomerPackCost);
                    }
                    
                    // Calculate payment totals for this bill - pass billNo and customer code
                    $payments = $this->calculatePaymentTotals(
                        $bill->payment_history, 
                        $billNo, 
                        $customer->short_name
                    );
                    
                    // Get credit amount from payment history
                    $creditAmountFromHistory = $payments['credit_amount'] ?? 0;
                    
                    $creditDeduction += $payments['deductions'];
                    $totalCreditAmount += $creditAmountFromHistory;
                    
                    // ✅ CRITICAL FIX: Add credit amount to bill total for sales amount
                    $billTotalWithCredit = $billTotal + $creditAmountFromHistory;
                    $netBillAmount = $billTotalWithCredit - $payments['deductions'];
                    
                    $netSales += $netBillAmount;
                    $actualPaid += $payments['paid'];
                    $billCount++;
                    
                    $processedBills[$billNo] = true;
                }
            }

            $remaining = max(0, $netSales - $actualPaid);

            $debtorData[] = [
                'debtor_no' => $customer->Debtor_no,
                'code' => $customer->short_name,
                'name' => $customer->name,
                'telephone' => $customer->telephone_no,
                'address' => $customer->address,
                'total_sales' => $netSales,  // Now includes credit amounts
                'total_paid' => $actualPaid,
                'credit_deductions' => $creditDeduction,
                'total_credit_amount' => $totalCreditAmount,
                'total_remaining' => $remaining,
                'bill_count' => $billCount,
                'status' => $remaining <= 0 ? 'Fully Paid' : 'Pending'
            ];

            $summary['sales'] += $netSales;
            $summary['paid'] += $actualPaid;
            $summary['credit_deductions'] += $creditDeduction;
            $summary['credit_amounts'] += $totalCreditAmount;
            $summary['rem'] += $remaining;
        }

        return response()->json([
            'success' => true,
            'data' => $debtorData,
            'summary' => [
                'total_debtors' => count($debtorData),
                'total_sales_amount' => $summary['sales'],
                'total_paid_amount' => $summary['paid'],
                'total_credit_deductions' => $summary['credit_deductions'],
                'total_credit_amounts' => $summary['credit_amounts'],
                'total_remaining_amount' => $summary['rem']
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Debtor report error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Get detailed debtor information - FIXED DOUBLE COUNTING
     * UPDATED: Always use Date column from sales table for payment dates
     */
 public function getDebtorDetails(Request $request, $code)
{
    try {
        $viewOldBills = filter_var($request->query('view_old_bills', false), FILTER_VALIDATE_BOOLEAN);
        
        $customer = Customer::where('short_name', $code)->first();
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        // Get the appropriate model based on view_old_bills
        $saleModel = $this->getSaleModel($viewOldBills);
        
        // Get all bills for this customer
        $bills = $saleModel::where('customer_code', $code)
            ->where('bill_printed', 'Y')
            ->get()
            ->groupBy('bill_no')
            ->map(function ($billGroup, $billNo) use ($code) {
                $firstBill = $billGroup->first();
                
                // Calculate total amount for this bill
                $totalAmount = 0;
                foreach ($billGroup as $bill) {
                    $billTotal = floatval($bill->total);
                    if (isset($bill->packs) && $bill->packs > 0 && isset($bill->CustomerPackCost)) {
                        $billTotal += floatval($bill->packs) * floatval($bill->CustomerPackCost);
                    }
                    $totalAmount += $billTotal;
                }
                
                // Calculate payment totals - pass billNo and customer code
                $payments = $this->calculatePaymentTotals(
                    $firstBill->payment_history, 
                    $billNo, 
                    $code
                );
                
                $netBillAmount = $totalAmount;
                $paidAmount = $payments['paid'];
                $remainingAmount = max(0, $netBillAmount - $paidAmount);
                
                return [
                    'bill_no' => $billNo,
                    'date' => $firstBill->Date ? date('Y-m-d', strtotime($firstBill->Date)) : null,
                    'total_amount' => $netBillAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => $remainingAmount,
                    'credit_deductions' => $payments['deductions']
                ];
            })
            ->values()
            ->filter(function($bill) {
                return $bill !== null;
            })
            ->sortByDesc('date')
            ->values();

        // Collect all payments - ALWAYS USE SALE'S DATE COLUMN, IGNORE PAYMENT_HISTORY DATE
        $payments = [];
        
        // Get payments from sales/bills only
        $salesRecords = $saleModel::where('customer_code', $code)
            ->whereNotNull('payment_history')
            ->get();
            
        foreach ($salesRecords as $sale) {
            $paymentHistory = $this->parseHistory($sale->payment_history);
            foreach ($paymentHistory as $payment) {
                // Skip credit payments as they're deductions
                if (strtolower(trim($payment['method'] ?? '')) === 'credit') {
                    continue;
                }
                
                // IMPORTANT: Use the sale's Date column, NOT the payment['date']
                $payments[] = [
                    'bill_no' => $sale->bill_no,
                    'amount' => $payment['amount'] ?? 0,
                    'method' => $payment['method'] ?? 'Cash',
                    'method_display' => $this->getPaymentMethodDisplay($payment['method'] ?? 'Cash'),
                    'date' => $sale->Date ? date('Y-m-d', strtotime($sale->Date)) : null
                ];
            }
        }

        // Sort payments by date (newest first)
        usort($payments, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Calculate totals
        $totalBillAmount = $bills->sum('total_amount');
        $totalPaidAmount = $bills->sum('paid_amount');
        $totalCreditDeductions = $bills->sum('credit_deductions');
        
        // Apply overpayment logic for display
        $displayPaidAmount = $totalPaidAmount;
        if ($totalPaidAmount > $totalBillAmount) {
            $displayPaidAmount = $totalPaidAmount - $totalCreditDeductions;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $customer->short_name,
                'debtor_no' => $customer->Debtor_no,
                'name' => $customer->name,
                'telephone' => $customer->telephone_no,
                'address' => $customer->address,
                'credit_limit' => $customer->credit_limit ?? 0,
                'profile_pic' => $customer->profile_pic ?? null,
                'bills' => $bills,
                'payments' => $payments,
                'total_bill_amount' => $totalBillAmount,
                'total_paid_amount' => $displayPaidAmount,
                'total_credit_deductions' => $totalCreditDeductions,
                'total_remaining' => $bills->sum('remaining_amount')
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Debtor details error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Get payment method display text
     */
    private function getPaymentMethodDisplay($method)
    {
        $methods = [
            'Cash' => 'Cash',
            'Cheque' => 'Cheque',
            'Bank Transfer' => 'Bank Transfer',
            'credit' => 'Credit',
            'bag_to_box' => 'Bag to Box',
            'bill_to_bill' => 'Bill to Bill',
            'bad_debt' => 'Bad Debt'
        ];
        
        return $methods[$method] ?? ucfirst($method);
    }

    /**
     * Get creditor report - NO LEGACY RECORDS
     */
    public function getCreditorReport(Request $request)
    {
        try {
            $search = $request->query('search');
            $limit = $request->query('limit', 50);
            $viewOldBills = filter_var($request->query('view_old_bills', false), FILTER_VALIDATE_BOOLEAN);

            // Get all supplier codes that are creditors
            $supplierIds = Supplier::where('Creditor', 'Y')->pluck('code')
                ->unique()
                ->values();

            $creditorsQuery = Supplier::whereIn('code', $supplierIds);

            if ($search) {
                $creditorsQuery->where(function ($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                        ->orWhere('name', 'LIKE', "%{$search}%");
                });
            }

            $creditors = $creditorsQuery->take($limit)->get();

            // Get the appropriate model based on view_old_bills
            $saleModel = $this->getSaleModel($viewOldBills);
            
            // Get all supplier bills - NO LEGACY
            $allBills = $saleModel::whereIn('supplier_code', $creditors->pluck('code'))
                ->where('supplier_bill_printed', 'Y')
                ->get()
                ->groupBy('supplier_code');
            
            // Get supplier loans
            $allLoans = SupplierLoan::whereIn('code', $creditors->pluck('code'))
                ->get()
                ->groupBy('code');

            $creditorData = [];
            $summary = [
                'supplier_amount' => 0,
                'paid' => 0,
                'rem' => 0
            ];

            foreach ($creditors as $supplier) {
                $totalAmount = 0;
                $totalPaid = 0;
                $billCount = 0;
                
                // Process bills grouped by bill_no to avoid double counting
                $processedBills = [];

                // Process current supplier bills only - NO LEGACY
                if (isset($allBills[$supplier->code])) {
                    foreach ($allBills[$supplier->code] as $bill) {
                        $billNo = $bill->supplier_bill_no;
                        
                        // Skip if we've already processed this bill number
                        if (isset($processedBills[$billNo])) {
                            continue;
                        }
                        
                        $totalAmount += floatval($bill->SupplierTotal);
                        $totalPaid += floatval($bill->supplier_paid_amount);
                        $billCount++;
                        
                        $processedBills[$billNo] = true;
                    }
                }

                // Process loans
                if (isset($allLoans[$supplier->code])) {
                    foreach ($allLoans[$supplier->code] as $loan) {
                        $paymentTotals = $this->calculatePaymentTotals($loan->payment_details);
                        $netLoanAmount = floatval($loan->loan_amount) - $paymentTotals['deductions'];
                        $totalAmount += $netLoanAmount;
                        $totalPaid += $paymentTotals['paid'];
                        $billCount++;
                    }
                }

                $remaining = max(0, $totalAmount - $totalPaid);
                
                $creditorData[] = [
                    'code' => $supplier->code,
                    'name' => $supplier->name,
                    'total_supplier_amount' => $totalAmount,
                    'total_paid' => $totalPaid,
                    'total_remaining' => $remaining,
                    'bill_count' => $billCount,
                    'status' => $remaining <= 0 ? 'Fully Settled' : 'Pending'
                ];
                
                $summary['supplier_amount'] += $totalAmount;
                $summary['paid'] += $totalPaid;
                $summary['rem'] += $remaining;
            }

            return response()->json([
                'success' => true,
                'data' => $creditorData,
                'summary' => [
                    'total_creditors' => count($creditorData),
                    'total_supplier_amount' => $summary['supplier_amount'],
                    'total_paid_amount' => $summary['paid'],
                    'total_remaining_amount' => $summary['rem']
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Creditor report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed creditor information - NO LEGACY RECORDS
     */
    public function getCreditorDetails(Request $request, $code)
    {
        try {
            $viewOldBills = filter_var($request->query('view_old_bills', false), FILTER_VALIDATE_BOOLEAN);
            
            $supplier = Supplier::where('code', $code)->first();
            if (!$supplier) {
                return response()->json(['success' => false, 'message' => 'Supplier not found'], 404);
            }

            // Get the appropriate model based on view_old_bills
            $saleModel = $this->getSaleModel($viewOldBills);
            
            // Get all supplier bills - NO LEGACY
            $bills = $saleModel::where('supplier_code', $code)
                ->where('supplier_bill_printed', 'Y')
                ->get()
                ->groupBy('supplier_bill_no')
                ->map(function ($billGroup, $billNo) {
                    $firstBill = $billGroup->first();
                    $totalAmount = $billGroup->sum('SupplierTotal');
                    $paidAmount = $billGroup->sum('supplier_paid_amount');
                    
                    return [
                        'bill_no' => $billNo,
                        'date' => $firstBill->Date,
                        'total_amount' => floatval($totalAmount),
                        'paid_amount' => floatval($paidAmount),
                        'remaining_amount' => max(0, floatval($totalAmount) - floatval($paidAmount)),
                        'type' => 'Sale Bill',
                        'is_fully_settled' => (floatval($totalAmount) - floatval($paidAmount)) <= 0
                    ];
                })
                ->values()
                ->sortByDesc('date')
                ->values();

            // Get supplier loans
            $loans = SupplierLoan::where('code', $code)
                ->orderBy('Date', 'desc')
                ->get()
                ->map(function($loan) {
                    $paymentTotals = $this->calculatePaymentTotals($loan->payment_details);
                    $netLoanAmount = floatval($loan->loan_amount) - $paymentTotals['deductions'];
                    $paidAmount = $paymentTotals['paid'];
                    $remainingAmount = max(0, $netLoanAmount - $paidAmount);
                    
                    return [
                        'bill_no' => $loan->bill_no,
                        'loan_amount' => floatval($loan->loan_amount),
                        'net_amount' => $netLoanAmount,
                        'paid_amount' => $paidAmount,
                        'remaining_amount' => $remainingAmount,
                        'date' => $loan->Date,
                        'type' => $loan->type,
                        'is_fully_settled' => $remainingAmount <= 0
                    ];
                });

            // Collect all payments - NO LEGACY
            $payments = $this->getCreditorPaymentHistory($code, $viewOldBills);

            // Calculate totals
            $totalAmount = $bills->sum('total_amount') + $loans->sum('net_amount');
            $totalPaid = $bills->sum('paid_amount') + $loans->sum('paid_amount');
            $totalRemaining = $bills->sum('remaining_amount') + $loans->sum('remaining_amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'code' => $supplier->code,
                    'name' => $supplier->name,
                    'bills' => $bills,
                    'loans' => $loans,
                    'payments' => $payments,
                    'total_amount' => $totalAmount,
                    'total_paid' => $totalPaid,
                    'total_remaining' => $totalRemaining
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Creditor details error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get creditor payment history - UPDATED: Always use Date column from sales/loans tables
     */
    private function getCreditorPaymentHistory($supplierCode, $viewOldBills = false)
    {
        $payments = [];
        
        // Get the appropriate model
        $saleModel = $this->getSaleModel($viewOldBills);
        
        // Get payments from supplier bills only - ALWAYS USE SALE'S DATE COLUMN
        $sales = $saleModel::where('supplier_code', $supplierCode)
            ->whereNotNull('payment_history')
            ->get();
            
        foreach ($sales as $sale) {
            $paymentHistory = $this->parseHistory($sale->payment_history);
            foreach ($paymentHistory as $payment) {
                // IMPORTANT: Use the sale's Date column, NOT the payment['date']
                $payments[] = [
                    'bill_no' => $sale->supplier_bill_no ?? $sale->bill_no,
                    'amount' => $payment['amount'] ?? 0,
                    'method_display' => $this->getPaymentMethodDisplay($payment['method'] ?? 'Cash'),
                    'date' => $sale->Date ? date('Y-m-d', strtotime($sale->Date)) : null,
                    'method' => $payment['method'] ?? 'Cash'
                ];
            }
        }

        // Get payments from loans - ALWAYS USE LOAN'S DATE COLUMN
        $loans = SupplierLoan::where('code', $supplierCode)
            ->whereNotNull('payment_details')
            ->get();
            
        foreach ($loans as $loan) {
            $paymentHistory = $this->parseHistory($loan->payment_details);
            foreach ($paymentHistory as $payment) {
                $payments[] = [
                    'bill_no' => $loan->bill_no,
                    'amount' => $payment['amount'] ?? 0,
                    'method_display' => $this->getPaymentMethodDisplay($payment['method'] ?? 'Cash'),
                    'date' => $loan->Date ? date('Y-m-d', strtotime($loan->Date)) : null,
                    'method' => $payment['method'] ?? 'Cash'
                ];
            }
        }

        // Sort payments by date (newest first)
        usort($payments, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $payments;
    }
}