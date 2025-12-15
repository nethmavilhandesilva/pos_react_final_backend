<?php

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
    Route::put('/customers/{customer}', [CustomerController::class, 'apiUpdate']);
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
});

