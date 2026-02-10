<?php

use App\Http\Controllers\ReportController2;
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
    Route::get('/customers', [CustomerController::class, 'apiIndex']);
    Route::post('/customers', [CustomerController::class, 'apiStore']);
    Route::post('/customers/update/{customer}', [CustomerController::class, 'apiUpdate']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);

    // ITEMS
    Route::apiResource('items', ItemController::class);
    Route::get('items/search/{query}', [ItemController::class, 'search']);

    // ----------------------------------------------------------------------
    // 🆕 SUPPLIER ROUTES (Custom + Resource)
    // ----------------------------------------------------------------------

    // ✔ Custom supplier reports
    Route::get('/suppliers/bill-status-summary', [SupplierController::class, 'getSupplierBillStatusSummary']);
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


    // SALES
    Route::get('/sales', [SalesEntryController::class, 'index']);
    Route::post('/sales', [SalesEntryController::class, 'store']);
    Route::put('/sales/{sale}', [SalesEntryController::class, 'update']);
    Route::delete('/sales/{sale}', [SalesEntryController::class, 'destroy']);
    Route::post('/sales/mark-printed', [SalesEntryController::class, 'markAsPrinted']);
    Route::post('/sales/mark-all-processed', [SalesEntryController::class, 'markAllAsProcessed']);
    Route::put('/sales/{sale}/given-amount', [SalesEntryController::class, 'updateGivenAmount']);

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
    // Get the latest given_amount for this customer
    $latestSale = \App\Models\Sale::where('customer_code', $customerCode)
        ->whereNotNull('given_amount')
        ->orderBy('updated_at', 'desc')
        ->first();
    
    return response()->json([
        'success' => true,
        'given_amount' => $latestSale ? $latestSale->given_amount : null
    ]);
});
Route::middleware('auth:sanctum')->get('/supplier-report', [ReportController::class, 'getSupplierReport']);
});
//printed sales report
Route::get('/sales-report/printed', [ReportController::class, 'getPrintedReport']);
//update te supplier
Route::put('/sales/{id}/update-supplier', [SupplierController::class, 'updateSupplier']);
//tore 2 metod
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