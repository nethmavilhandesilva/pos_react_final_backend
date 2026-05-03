<?php

use App\Http\Controllers\FarmerLoanController;
use App\Http\Controllers\IC_UtilityTypeController;
use App\Http\Controllers\ReportController2;
use App\Http\Controllers\SupplierLoanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\GrnEntryController;
use App\Http\Controllers\CustomersLoanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalesEntryController;
use App\Http\Controllers\CommissionController;
use App\Models\Setting;
use App\Http\Controllers\BankController;

// ----------------------------------------------------------------------
// 🚨 PUBLIC ROUTES (No Authentication Required) 🚨
// ----------------------------------------------------------------------

// AUTH
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// PUBLIC COMMISSION ITEM OPTIONS
Route::get('/items/options', [CommissionController::class, 'getItemOptions']);

//printed sales report
Route::get('/sales-report/printed', [ReportController::class, 'getPrintedReport']);

//update the supplier
Route::put('/sales/{id}/update-supplier', [SupplierController::class, 'updateSupplier']);

//store 2 method
Route::post('/suppliers/advance', [SupplierController::class, 'store2']);
Route::get('/suppliers/search-by-code/{code}', [SupplierController::class, 'getByCode']);

//NEW SALES REPORT
Route::get('/reports/sales-summary', [ReportController::class, 'getSalesSummary']);
Route::get('/reports/bill-details/{billNo}/{customerCode}', [ReportController::class, 'getBillDetails']);

//new farmer report
Route::get('/reports/farmers-summary', [ReportController::class, 'getFarmersSummary']);
Route::get('/reports/farmer-bill-details/{billNo}/{supplierCode}', [ReportController::class, 'getFarmerBillDetails']);

//add or create a customer record
Route::post('/customers/check-or-create', [CustomerController::class, 'checkOrCreate']);

//validation
Route::get('/customers/check-short-name/{short_name}', [CustomerController::class, 'checkShortName']);

//bill preview
Route::get('/public/bill/{token}', [SalesEntryController::class, 'viewPublicBill']);

//dob report
Route::get('/suppliers-report', [SupplierController::class, 'dobreport']);

//update supplier
Route::post('/suppliers/update-phone', [SupplierController::class, 'updatePhone']);
Route::post('/suppliers/resend-sms', [SupplierController::class, 'resendSupplierSMS']);

//supplier bill links
Route::get('/public/supplier-bill/{token}', function ($token) {
    return DB::table('supplier_bill_links')->where('token', $token)->first();
});

// ======================================================================
// ✅ IMPORTANT: SupplierLoanController PUBLIC ROUTES (if any)
// ======================================================================
// (None needed - all are protected)

// ----------------------------------------------------------------------
// ✅ PROTECTED ROUTES (Requires 'auth:sanctum') 
// ----------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // ==================== USER ROUTE (GET CURRENT USER) ====================
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'user_id' => $user->user_id
            ]
        ]);
    });

    // CUSTOMERS
    Route::get('/customers', [CustomerController::class, 'apiIndex']);
    Route::post('/customers', [CustomerController::class, 'apiStore']);
    Route::post('/customers/update/{customer}', [CustomerController::class, 'apiUpdate']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);

    // ITEMS
    Route::apiResource('items', ItemController::class);
    Route::get('items/search/{query}', [ItemController::class, 'search']);

    // ----------------------------------------------------------------------
    // SUPPLIER ROUTES (IMPORTANT: Specific routes BEFORE apiResource)
    // ----------------------------------------------------------------------

    // 🔴 CRITICAL: These specific routes MUST come BEFORE Route::apiResource
    Route::get('/suppliers/loan-summary', [SupplierLoanController::class, 'getLoanSummary']);
    Route::get('/suppliers/all-codes', [SupplierLoanController::class, 'getAllCodes']);
    Route::get('/suppliers/full-report', [SupplierLoanController::class, 'getFarmerFullReport']);
    Route::get('/suppliers/bill-status-summary', [SupplierController::class, 'getSupplierBillStatusSummary']);
    Route::get('/suppliers/supplierloans', [SupplierLoanController::class, 'getSupplierBillStatusSummary2']);
    Route::get('/suppliers/{supplierCode}/details', [SupplierController::class, 'getSupplierDetails']);
    Route::post('/suppliers/delete-loan-record', [SupplierLoanController::class, 'deleteLoanRecord']);

    // Default REST API (must come AFTER specific routes)
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/search/{query}', [SupplierController::class, 'search']);

    // ----------------------------------------------------------------------
    // GRN ENTRY ROUTES
    // ----------------------------------------------------------------------
    Route::get('/grn-entries/latest', [GrnEntryController::class, 'getLatestEntries']);
    Route::get('/grn-entries/create-data', [GrnEntryController::class, 'createData']);
    Route::get('/grn-entries/code/{code}', [GrnEntryController::class, 'getByCode']);
    Route::apiResource('grn-entries', GrnEntryController::class);

    // CUSTOMER LOANS
    Route::get('/customers-loans', [CustomersLoanController::class, 'index']);
    Route::post('/customers-loans', [CustomersLoanController::class, 'store']);
    Route::get('/customers-loans/{customerId}/total', [CustomersLoanController::class, 'getCustomerLoanTotal']);
    Route::put('/customers-loans/{id}', [CustomersLoanController::class, 'update']);
    Route::delete('/customers-loans/{id}', [CustomersLoanController::class, 'destroy']);

    // GRN UPDATE
    Route::get('/not-changing-grns', [GrnEntryController::class, 'getNotChangingGRNs']);
    Route::get('/grn/balance/{code}', [GrnEntryController::class, 'getGrnBalance']);
    Route::post('/grn/store2', [GrnEntryController::class, 'store2']);
    Route::delete('/grn/delete/update/{id}', [GrnEntryController::class, 'destroyupdate']);

    // REPORTS
    Route::get('/allitems', [ReportController::class, 'fetchItems']);
    Route::get('/item-report', [ReportController::class, 'itemReport']);
    Route::post('/report/weight', [ReportController::class, 'getweight']);
    Route::get('/grncodes', [ReportController::class, 'getGrnEntries']);
    Route::post('/report/sale-code', [ReportController::class, 'getGrnSalecodereport']);
    Route::post('/reports/salesadjustment/filter', [ReportController::class, 'salesAdjustmentReport']);
    Route::get('/reports/grn-sales-overview', [ReportController::class, 'getGrnSalesOverviewReport']);
    Route::get('/reports/grn-sales-overview2', [ReportController::class, 'getGrnSalesOverviewReport2']);
    Route::get('/suppliersall', [ReportController::class, 'getSuppliers']);
    Route::get('/customersall', [ReportController::class, 'getCustomers']);
    Route::get('/bill-numbers', [ReportController::class, 'getBillNumbers']);
    Route::get('/company-info', [ReportController::class, 'getCompanyInfo']);
    Route::get('/sales-report', [ReportController::class, 'salesReport']);

    Route::prefix('reports')->group(function () {
        Route::post('/generate', [ReportController::class, 'generateReport']);
        Route::post('/download-pdf', [ReportController::class, 'downloadPDF']);
        Route::post('/download-excel', [ReportController::class, 'downloadExcel']);
    });

    // LOAN REPORT
    Route::get('/customers-loans/report', [ReportController::class, 'loanReport']);

    // GRN REPORT
    Route::get('/grn-codes', [ReportController::class, 'fetchGrnCodes']);
    Route::get('/grn-report', [ReportController::class, 'grnReport2']);
    Route::get('/financial-report', [ReportController2::class, 'getFinancialData']);

    Route::get('/settings', function () {
        $setting = Setting::first();
        if (!$setting) {
            return response()->json(['value' => 'No Data'], 404);
        }
        return response()->json([
            'value' => $setting->value,
            'company' => $setting->CompanyName,
        ]);
    });

    // ======================================================================
    // CASHIER DASHBOARD ROUTES
    // ======================================================================

    Route::get('/sales/all', [SalesEntryController::class, 'getAllSales2'])->name('sales.all');
    Route::put('/sales/update-given-amount-applied', [SalesEntryController::class, 'updateGivenAmountApplied'])->name('sales.update-given-amount-applied');
    Route::get('/sales/payment-history/{billNo}', [SalesEntryController::class, 'getPaymentHistory'])->name('sales.payment-history');
    Route::put('/sales/{saleId}/given-amount', [SalesEntryController::class, 'updateSaleGivenAmount'])->name('sales.update-sale-given-amount')->where('saleId', '[0-9]+');

    Route::get('/sales', [SalesEntryController::class, 'index']);
    Route::post('/sales', [SalesEntryController::class, 'store']);
    Route::put('/sales/{sale}', [SalesEntryController::class, 'update'])->where('sale', '[0-9]+');
    Route::delete('/sales/{sale}', [SalesEntryController::class, 'destroy'])->where('sale', '[0-9]+');

    Route::post('/sales/mark-printed', [SalesEntryController::class, 'markAsPrinted']);
    Route::post('/sales/mark-all-processed', [SalesEntryController::class, 'markAllAsProcessed']);
    Route::post('/get-loan-amount', [SalesEntryController::class, 'getLoanAmount']);

    // COMMISSIONS (CRUD)
    Route::resource('commissions', CommissionController::class)->except(['create', 'edit']);

    //supplier bill number
    Route::get('/generate-f-series-bill', [SupplierController::class, 'generateFSeriesBill']);
    Route::get('sales/profit-by-supplier', [SupplierController::class, 'getProfitBySupplier']);
    Route::post('/suppliers/mark-as-printed', [SupplierController::class, 'marksuppliers']);
    Route::get('/suppliers/bill/{billNo}/details', [SupplierController::class, 'getBillDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details', [SupplierController::class, 'getUnprintedDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details2', [SupplierController::class, 'getUnprintedDetails2']);

    //Day Process
    Route::post('/sales/process-day', [SalesEntryController::class, 'processDay']);

    //loan section
    Route::get('/customers-loans/data', [CustomersLoanController::class, 'getInitialData']);
    Route::get('/customers-loans/index', [CustomersLoanController::class, 'index']);
    Route::post('/customers-loans', [CustomersLoanController::class, 'store']);
    Route::post('/customers-loans/{id}', [CustomersLoanController::class, 'updateApi']);
    Route::delete('/customers-loans/{id}', [CustomersLoanController::class, 'destroy']);
    Route::get('/customers/{customerId}/loans-total', [CustomersLoanController::class, 'getTotalLoanAmount']);
    Route::post('/settings/updateBalance', [CustomersLoanController::class, 'updateBalance']);
    Route::get('/api/grn-entry/{code}', [CustomersLoanController::class, 'getGrnEntry']);
    Route::get('/api/all-bill-nos', [CustomersLoanController::class, 'getAllBillNos']);
    Route::put('/customers-loans/{id}', [CustomersLoanController::class, 'update']);
    Route::post('/loan-report-results', [CustomersLoanController::class, 'getLoanReportData']);
    Route::get('/utility-types/income', [CustomersLoanController::class, 'getIncomeTypes']);
    Route::get('/utility-types/expense', [CustomersLoanController::class, 'getExpenseTypes']);

    //update given amount
    Route::get('/sales/customer/given-amount/{customerCode}', function ($customerCode) {
        $sales = \App\Models\Sale::where('customer_code', $customerCode)
            ->whereNotNull('given_amount')
            ->orderBy('bill_no', 'asc')
            ->orderBy('updated_at', 'desc')
            ->get();

        $billAmounts = [];
        foreach ($sales as $sale) {
            if (!isset($billAmounts[$sale->bill_no])) {
                $billAmounts[$sale->bill_no] = $sale->given_amount;
            }
        }

        $allEntries = $sales->map(function ($sale) {
            return [
                'bill_no' => $sale->bill_no,
                'given_amount' => $sale->given_amount,
                'updated_at' => $sale->updated_at
            ];
        });

        return response()->json([
            'success' => true,
            'customer_code' => $customerCode,
            'latest_given_amount' => $sales->first() ? $sales->first()->given_amount : null,
            'by_bill_no' => $billAmounts,
            'all_entries' => $allEntries
        ]);
    });

    Route::get('/supplier-report', [ReportController::class, 'getSupplierReport']);

    // ======================================================================
    // 🏦 BANK ROUTES (Protected)
    // ======================================================================

    Route::prefix('banks')->group(function () {
        Route::get('/', [BankController::class, 'index']);
        Route::get('/list', [BankController::class, 'getBanksList']);
        Route::post('/', [BankController::class, 'store']);
        Route::get('/{id}', [BankController::class, 'show']);
        Route::put('/{id}', [BankController::class, 'update']);
        Route::delete('/{id}', [BankController::class, 'destroy']);
    });

    Route::prefix('bank-accounts')->group(function () {
        Route::get('/dashboard', [BankController::class, 'dashboard']);
        Route::get('/transactions', [BankController::class, 'getTransactions']);
        Route::get('/transactions/{id}', [BankController::class, 'getTransaction']);
        Route::get('/statement/all', [BankController::class, 'getAllAccountsStatement']);
        Route::get('/statement/{bankAccountId}', [BankController::class, 'getStatement']);
        Route::get('/balance/{bankAccountId}', [BankController::class, 'getBalance']);
        Route::get('/cheques', [BankController::class, 'getChequeReport']);
        Route::get('/bank-transfers', [BankController::class, 'getBankTransferReport']);
        Route::get('/monthly-summary', [BankController::class, 'getMonthlySummary']);
        Route::get('/export', [BankController::class, 'exportTransactions']);
    });

    // Adjustment routes
    Route::get('/adjustments/pending-customer-bills', [SalesEntryController::class, 'getPendingCustomerBills']);
    Route::get('/adjustments/pending-farmer-bills', [SalesEntryController::class, 'getPendingFarmerBills']);
    Route::post('/adjustments/apply', [SalesEntryController::class, 'applyPaymentAdjustment']);

    // ======================================================================
    // SUPPLIER LOAN ROUTES WITH PAYMENT METHODS (Protected)
    // ======================================================================

    // IMPORTANT: These specific routes should come before any wildcard routes
    Route::get('/supplier-loan/payment-history', [SupplierLoanController::class, 'getPaymentHistory']);
    Route::get('/supplier-loan/search', [SupplierLoanController::class, 'findLoan']);
    Route::get('/supplier-loan/supplier/{code}', [SupplierLoanController::class, 'getBySupplier']);
    Route::get('/supplier-loan/supplier/{code}/total', [SupplierLoanController::class, 'getTotalLoan']);
    Route::get('/supplier-loan/bill/{billNo}', [SupplierLoanController::class, 'getByBillNo']);

    // Main CRUD routes
    Route::post('/supplier-loan', [SupplierLoanController::class, 'store']);
    Route::put('/supplier-loan/{id}', [SupplierLoanController::class, 'update']);
    Route::delete('/supplier-loan/{id}', [SupplierLoanController::class, 'destroy']);

    // Bank and bill fetching routes
    Route::get('/banks-list', [SupplierLoanController::class, 'getBanks']);
    Route::get('/pending-customer-bills', [SupplierLoanController::class, 'getPendingCustomerBills']);
    Route::get('/pending-farmer-bills', [SupplierLoanController::class, 'getPendingFarmerBills']);

    // ======================================================================
    // FARMER LOAN ROUTES (Protected)
    // ======================================================================

    Route::prefix('farmer-loans')->group(function () {
        Route::post('/', [FarmerLoanController::class, 'store']);
        Route::get('/data', [FarmerLoanController::class, 'getLoansData']);
        Route::get('/all', [FarmerLoanController::class, 'getLoansData']);
        Route::get('/{id}', [FarmerLoanController::class, 'getLoan']);
        Route::put('/{id}', [FarmerLoanController::class, 'update']);
        Route::delete('/{id}', [FarmerLoanController::class, 'destroy']);
        Route::get('/balance/{supplier_code}', [FarmerLoanController::class, 'getFarmerBalance']);
        Route::get('/balance/{supplier_code}/details', [FarmerLoanController::class, 'getFarmerBalanceDetails']);
        Route::get('/all-balances', [FarmerLoanController::class, 'getAllFarmersBalances']);
    });

    // Cashier reports
    Route::get('/payment-collection-report', [SalesEntryController::class, 'getPaymentCollectionReport']);
    Route::get('/payment-breakdown', [SalesEntryController::class, 'getPaymentBreakdown']);
    //supplier transaction reports
    Route::get('/payment-collection-report', [SupplierLoanController::class, 'getPaymentCollectionReport']);
    Route::get('/payment-details-by-bill', [SupplierLoanController::class, 'getPaymentDetailsByBill']);
    Route::prefix('utility-types')->group(function () {
        Route::get('/', [IC_UtilityTypeController::class, 'index']);
        Route::post('/', [IC_UtilityTypeController::class, 'store']);
        Route::get('/income', [IC_UtilityTypeController::class, 'getIncomeTypes']);
        Route::get('/expense', [IC_UtilityTypeController::class, 'getExpenseTypes']);
        Route::get('/{id}', [IC_UtilityTypeController::class, 'show']);
        Route::put('/{id}', [IC_UtilityTypeController::class, 'update']);
        Route::delete('/{id}', [IC_UtilityTypeController::class, 'destroy']);
        Route::post('/bulk-delete', [IC_UtilityTypeController::class, 'bulkDelete']);
        Route::patch('/{id}/toggle-status', [IC_UtilityTypeController::class, 'toggleStatus']);
    });
 //Income expense report routes
Route::get('/income-expense-report', [CustomersLoanController::class, 'getIncomeExpenseReport']);
Route::get('/income-expense-category-summary', [CustomersLoanController::class, 'getCategorySummary']);
Route::get('/income-expense-export', [CustomersLoanController::class, 'exportReport']);   
});