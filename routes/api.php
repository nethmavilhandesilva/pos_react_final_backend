<?php

use App\Http\Controllers\FarmerLoanController;
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


// ----------------------------------------------------------------------
// ✅ PROTECTED ROUTES (Requires 'auth:sanctum') 
// ----------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // CUSTOMERS
    Route::get('/suppliers/loan-summary', [SupplierLoanController::class, 'getLoanTakenSummary']);
    Route::get('/customers', [CustomerController::class, 'apiIndex']);
    Route::post('/customers', [CustomerController::class, 'apiStore']);
    Route::post('/customers/update/{customer}', [CustomerController::class, 'apiUpdate']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);
    
    //new supplier dashboarrd routes
    Route::get('/suppliers/all-codes', [SupplierLoanController::class, 'getAllCodes']);
    Route::get('/suppliers/full-report', [SupplierLoanController::class, 'getFarmerFullReport']);
    
    // ITEMS
    Route::apiResource('items', ItemController::class);
    Route::get('items/search/{query}', [ItemController::class, 'search']);

    // ----------------------------------------------------------------------
    // 🆕 SUPPLIER ROUTES (Custom + Resource)
    // ----------------------------------------------------------------------

    // ✔ Custom supplier reports
    Route::get('/suppliers/bill-status-summary', [SupplierController::class, 'getSupplierBillStatusSummary']);
    Route::get('/suppliers/supplierloans', [SupplierLoanController::class, 'getSupplierBillStatusSummary2']);
    Route::get('/suppliers/{supplierCode}/details', [SupplierController::class, 'getSupplierDetails']);

    // ✔ Default REST API (index, store, update, delete)
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
    
    // routes/api.php
    Route::get('/settings', function () {
        // We fetch the first record. 
        // If you have multiple keys, use: Setting::where('key', 'your_key')->first();
        $setting = Setting::first();

        if (!$setting) {
            return response()->json(['value' => 'No Data'], 404);
        }

        // Return the whole object or just the value column
        return response()->json([
            'value' => $setting->value,
            'company' => $setting->CompanyName, // Optional: if you need it later
        ]);
    });


    // ======================================================================
    // 🆕 FIXED CASHIER DASHBOARD ROUTES (Moved BEFORE parameterized routes)
    // ======================================================================
    
    // Cashier Dashboard Routes - SPECIFIC PATTERNS FIRST
    Route::get('/sales/all', [SalesEntryController::class, 'getAllSales2'])
        ->name('sales.all');
    
    Route::put('/sales/update-given-amount-applied', [SalesEntryController::class, 'updateGivenAmountApplied'])
        ->name('sales.update-given-amount-applied');
    
    // This is the specific route for updating given amount - MUST come before /sales/{sale}
    Route::put('/sales/{saleId}/given-amount', [SalesEntryController::class, 'updateSaleGivenAmount'])
        ->name('sales.update-sale-given-amount')
        ->where('saleId', '[0-9]+'); // Only match numeric IDs
    
    // Regular CRUD routes - MORE GENERAL PATTERNS LAST
    Route::get('/sales', [SalesEntryController::class, 'index']); // This will NOT conflict with /sales/all
    Route::post('/sales', [SalesEntryController::class, 'store']);
    Route::put('/sales/{sale}', [SalesEntryController::class, 'update'])
        ->where('sale', '[0-9]+'); // Only match numeric IDs
    Route::delete('/sales/{sale}', [SalesEntryController::class, 'destroy'])
        ->where('sale', '[0-9]+'); // Only match numeric IDs
    
    Route::post('/sales/mark-printed', [SalesEntryController::class, 'markAsPrinted']);
    Route::post('/sales/mark-all-processed', [SalesEntryController::class, 'markAllAsProcessed']);

    // CUSTOMER LOAN FETCH
    Route::post('/get-loan-amount', [SalesEntryController::class, 'getLoanAmount']);

    // COMMISSIONS (CRUD)
    Route::resource('commissions', CommissionController::class)->except(['create', 'edit']);

    //suplier bill number
    Route::get('/generate-f-series-bill', [SupplierController::class, 'generateFSeriesBill']);
    
    //supplier report
    Route::get('sales/profit-by-supplier', [SupplierController::class, 'getProfitBySupplier']);
    
    //supplier bill no
    Route::post('/suppliers/mark-as-printed', [SupplierController::class, 'marksuppliers']);
    Route::get('/suppliers/bill/{billNo}/details', [SupplierController::class, 'getBillDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details', [SupplierController::class, 'getUnprintedDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details2', [SupplierController::class, 'getUnprintedDetails2']);
    
    //Day Process
    Route::post('/sales/process-day', [SalesEntryController::class, 'processDay']);

    //loan section
    Route::get('/customers-loans/data', [CustomersLoanController::class, 'getInitialData']); // NEW: For Customers, GRN Codes
    Route::get('/customers-loans/index', [CustomersLoanController::class, 'index']); // Get today's loans

    // Loan CRUD (using the existing methods)
    Route::post('/customers-loans', [CustomersLoanController::class, 'store']);
    // Note: React will use POST with _method=PUT to hit this route
    Route::post('/customers-loans/{id}', [CustomersLoanController::class, 'updateApi']); 
    Route::delete('/customers-loans/{id}', [CustomersLoanController::class, 'destroy']);

    // Utility Endpoints
    Route::get('/customers/{customerId}/loans-total', [CustomersLoanController::class, 'getTotalLoanAmount']); 
    Route::post('/settings/updateBalance', [CustomersLoanController::class, 'updateBalance']); // Assuming you'll add this method
    Route::get('/api/grn-entry/{code}', [CustomersLoanController::class, 'getGrnEntry']); // NEW: For item code autofill
    Route::get('/api/all-bill-nos', [CustomersLoanController::class, 'getAllBillNos']); // NEW: For bill no dropdown
    Route::put('/customers-loans/{id}', [CustomersLoanController::class, 'update']);
    
    //loans
    Route::post('/loan-report-results', [CustomersLoanController::class, 'getLoanReportData']);
    
    //update given amount
    Route::get('/sales/customer/given-amount/{customerCode}', function($customerCode) {
        // Get all sales for this customer with non-null given_amount, ordered by bill_no and updated_at
        $sales = \App\Models\Sale::where('customer_code', $customerCode)
            ->whereNotNull('given_amount')
            ->orderBy('bill_no', 'asc')
            ->orderBy('updated_at', 'desc')
            ->get();
        
        // Group by bill_no to get the latest given_amount for each bill
        $billAmounts = [];
        foreach ($sales as $sale) {
            if (!isset($billAmounts[$sale->bill_no])) {
                $billAmounts[$sale->bill_no] = $sale->given_amount;
            }
        }
        
        // Or if you want all entries separately without grouping by latest
        $allEntries = $sales->map(function($sale) {
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
            'by_bill_no' => $billAmounts, // Latest given_amount per bill_no
            'all_entries' => $allEntries // All entries with their bill_no
        ]);
    });
    
    Route::middleware('auth:sanctum')->get('/supplier-report', [ReportController::class, 'getSupplierReport']);
});

// ======================================================================
// 🌐 PUBLIC ROUTES (Outside auth middleware - accessible without authentication)
// ======================================================================

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
// FARMER (SUPPLIER) REPORT ROUTES
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

// Supplier Loan Routes
Route::prefix('supplier-loan')->group(function () {
    Route::post('/', [App\Http\Controllers\SupplierLoanController::class, 'store']);
    Route::get('/supplier/{code}', [App\Http\Controllers\SupplierLoanController::class, 'getBySupplier']);
    Route::get('/supplier/{code}/total', [App\Http\Controllers\SupplierLoanController::class, 'getTotalLoan']);
    Route::get('/bill/{billNo}', [App\Http\Controllers\SupplierLoanController::class, 'getByBillNo']);
    Route::put('/{id}', [App\Http\Controllers\SupplierLoanController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\SupplierLoanController::class, 'destroy']);
});

// Farmer Loan Routes
Route::prefix('farmer-loans')->group(function () {
    Route::post('/', [FarmerLoanController::class, 'store']);
    Route::get('/data', [FarmerLoanController::class, 'getLoansData']);  // Changed from getTodayLoans
    Route::get('/all', [FarmerLoanController::class, 'getLoansData']);    // With ?all=true parameter
    Route::get('/{id}', [FarmerLoanController::class, 'getLoan']);         // Get single loan
    Route::put('/{id}', [FarmerLoanController::class, 'update']);
    Route::delete('/{id}', [FarmerLoanController::class, 'destroy']);
    Route::get('/balance/{supplier_code}', [FarmerLoanController::class, 'getFarmerBalance']);
    Route::get('/balance/{supplier_code}/details', [FarmerLoanController::class, 'getFarmerBalanceDetails']);
    Route::get('/all-balances', [FarmerLoanController::class, 'getAllFarmersBalances']);
});

// ======================================================================
// 🏦 BANK ROUTES (FULLY UPDATED)
// ======================================================================

// Bank CRUD routes
Route::prefix('banks')->group(function () {
    Route::get('/', [BankController::class, 'index']);
    Route::get('/list', [BankController::class, 'getBanksList']);
    Route::post('/', [BankController::class, 'store']);
    Route::get('/{id}', [BankController::class, 'show']);
    Route::put('/{id}', [BankController::class, 'update']);
    Route::delete('/{id}', [BankController::class, 'destroy']);
});

// Bank account dashboard and reports - IMPORTANT: 'all' route comes BEFORE parameterized routes
Route::prefix('bank-accounts')->group(function () {
    // Dashboard and overview
    Route::get('/dashboard', [BankController::class, 'dashboard']);
    
    // Transactions
    Route::get('/transactions', [BankController::class, 'getTransactions']);
    Route::get('/transactions/{id}', [BankController::class, 'getTransaction']);
    
    // Statement routes - CRITICAL: 'all' must come before {bankAccountId}
    Route::get('/statement/all', [BankController::class, 'getAllAccountsStatement']);
    Route::get('/statement/{bankAccountId}', [BankController::class, 'getStatement']);
    
    // Balance
    Route::get('/balance/{bankAccountId}', [BankController::class, 'getBalance']);
    
    // Reports
    Route::get('/cheques', [BankController::class, 'getChequeReport']);
    Route::get('/bank-transfers', [BankController::class, 'getBankTransferReport']);
    Route::get('/monthly-summary', [BankController::class, 'getMonthlySummary']);
    Route::get('/export', [BankController::class, 'exportTransactions']);
});

// Legacy routes for compatibility
Route::get('/banks-list', [BankController::class, 'getBanksList']);

// Bank routes for SalesEntryController
Route::get('/banks', [SalesEntryController::class, 'getBanks']);

// Adjustment routes
Route::get('/adjustments/pending-customer-bills', [SalesEntryController::class, 'getPendingCustomerBills']);
Route::get('/adjustments/pending-farmer-bills', [SalesEntryController::class, 'getPendingFarmerBills']);
Route::post('/adjustments/apply', [SalesEntryController::class, 'applyPaymentAdjustment']);

// Update given amount applied route
Route::put('/sales/update-given-amount-applied', [SalesEntryController::class, 'updateGivenAmountApplied']);
// Add this route inside the auth middleware group
Route::get('/sales/payment-history/{billNo}', [SalesEntryController::class, 'getPaymentHistory']);