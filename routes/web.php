<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\GrnEntryController;
use App\Http\Controllers\SalesEntryController; // ✅ Correct use statement
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CustomersLoanController;
use App\Http\Controllers\EmailController;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Http\Controllers\BillController;
use App\Models\User;
use App\Http\Controllers\DashboardController;


// New default route to redirect to login
Route::get('/', function () {
    return redirect('/login');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/register', [RegisteredUserController::class, 'create'])
    ->middleware('guest')
    ->name('register');

// Item
Route::resource('items', ItemController::class);



// Suppliers
Route::resource('suppliers', SupplierController::class);


// GRN
Route::resource('grn', GrnEntryController::class) ->except(['show']);
Route::post('/grn/store', [GrnEntryController::class, 'store'])->name('grn.store2');
Route::get('api/grn-entry/{code}', [GrnEntryController::class, 'getGrnEntryByCode']);
Route::get('/grn-used-data/{code}', [GrnEntryController::class, 'getUsedData']);
Route::post('/grn/{id}/hide', [GrnEntryController::class, 'hide'])->name('grn.hide');
Route::post('/grn/{id}/unhide', [GrnEntryController::class, 'unhide'])->name('grn.unhide');
Route::post('/grn-damages', [GrnEntryController::class, 'Damagestore'])->name('grn-damages.store');
Route::get('/grn-entries/latest', [GrnEntryController::class, 'getLatestEntries']);


// Sales
Route::get('/dashboard', [SalesEntryController::class, 'create'])->name('dashboard');
Route::post('/grn-entry', [SalesEntryController::class, 'store'])->name('grn.store');

Route::put('/sales/{sale}', [SalesEntryController::class, 'update']);
Route::delete('/sales/{sale}', [SalesEntryController::class, 'destroy']);

Route::post('/sales/mark-all-processed', [SalesEntryController::class, 'markAllAsProcessed'])->name('sales.markAllAsProcessed');
Route::get('api/sales/unprinted/{customer_code}', [SalesEntryController::class, 'getUnprintedSales']);

Route::get('/fetch-customer/{customer_code?}', [SalesEntryController::class, 'fetchCustomer'])->name('fetch.customer');

// Bill printing
Route::post('/sales/mark-printed', [SalesEntryController::class, 'markAsPrinted'])->name('sales.markAsPrinted');
Route::post('/sales/save-as-unprinted', [SalesEntryController::class, 'saveAsUnprinted'])->name('sales.save-as-unprinted');

// ❌ Removed the duplicate and incorrect route: Route::put('sales/update/{saleId}', 'SalesEntryController@update');

Route::post('/clear-data', [SalesEntryController::class, 'clearAll'])->name('clear.data');
Route::get('/sales/all-data', [SalesEntryController::class, 'getAllSalesData']);
Route::get('/sales/all', [SalesEntryController::class, 'getAllSales']);
Route::post('/sales/day-start', [SalesEntryController::class, 'dayStart'])->name('sales.dayStart');
Route::post('/update-grn-stock', [SalesEntryController::class, 'updateGrnRemainingStock'])->name('update.grn.stock');

// Reports
Route::get('/report', [ReportController::class, 'index'])->name('report.index');
Route::post('/report/fetch', [ReportController::class, 'fetch'])->name('report.fetch');
Route::post('/report/item', [ReportController::class, 'fetchItemReport'])->name('report.item.fetch');
Route::post('/report/weight', [ReportController::class, 'getweight'])->name('report.supplier_grn.fetch');
Route::post('/report/sale-code', [ReportController::class, 'getGrnSalecodereport'])->name('report.grn_sale.fetch');
Route::get('/reports/sales/filter', [ReportController::class, 'getSalesFilterReport'])->name('report.sales.filter');
Route::get('/reports/grn-sales-overview', [ReportController::class, 'getGrnSalesOverviewReport'])->name('report.grn.sales.overview');
Route::get('/reports/grn-sales-overview2', [ReportController::class, 'getGrnSalesOverviewReport2'])->name('report.grn.sales.overview2');
Route::post('/reports/salesadjustment/filter', [ReportController::class, 'salesAdjustmentReport'])->name('reports.salesadjustment.filter');
Route::post('/report/download/{reportType}/{format}', [ReportController::class, 'downloadReport'])->name('report.download');
Route::get('/financial-report', [ReportController::class, 'financialReport'])->name('financial.report');
Route::get('/sales-report', [ReportController::class, 'salesReport'])->name('sales.report');
Route::get('/grn-report', [ReportController::class, 'grnReport'])->name('grn.report');
Route::get('/returns-report', [ReportController::class, 'returnsReport']) ->name('returns.report');

// Customer loans
Route::get('/customers/{id}/loans-total', [CustomersLoanController::class, 'getTotalLoanAmount']);
Route::post('/get-loan-amount', [SalesEntryController::class, 'getLoanAmount'])->name('get.loan.amount');
Route::get('/sales/codes', [SalesEntryController::class, 'listCodes'])->name('sales.codes');
Route::get('/sales/code/{code}', [SalesEntryController::class, 'showByCode'])->name('sales.byCode');
Route::post('/save-receipt-file', [SalesEntryController::class, 'saveReceiptFile'])->name('save.receipt.file');
Route::post('/loan-report/results', [CustomersLoanController::class, 'loanReportResults'])->name('loan.report.results');
Route::get('/customers-loans/report', [CustomersLoanController::class, 'loanReport'])->name('customers-loans.report');
Route::resource('customers-loans', CustomersLoanController::class);
//Emails
Route::post('/send-receipt-email', [EmailController::class, 'sendReceiptEmail'])->name('send.receipt.email');
Route::post('/send-receipt-email', [EmailController::class, 'sendReceiptEmail'])->name('send.receipt.email');
//customer code
Route::get('/get-customer-code', function () {
    // fetch the first record with Processed = 'N'
    $sale = Sale::where('Processed', 'N')->first();

    return response()->json([
        'customer_code' => $sale ? $sale->customer_code : null,
    ]);
});
Route::get('/api/next-bill-no', [BillController::class, 'getNextBillNo']);
//Emails
Route::get('/email-report-daily', [ReportController::class, 'sendDailyReport'])->name('report.email.daily');
Route::get('/send-changes-report', [ReportController::class, 'emailChangesReport'])->name('report.changes.email');
Route::get('/send-total-sales-report', [ReportController::class, 'emailTotalSalesReport'])->name('report.total_sales.email');
Route::get('/send-bill-summary-report', [ReportController::class, 'emailBillSummaryReport'])->name('report.bill_summary.email');
Route::get('/send-credit-report', [ReportController::class, 'emailCreditReport'])->name('report.credit.email');
Route::get('/send-item-wise-report', [ReportController::class, 'emailItemWiseReport'])->name('report.itemwise.email');
Route::get('/email-grn-sales-report', [ReportController::class, 'emailGrnSalesReport'])->name('report.email.grn-sales');
Route::post('/email-supplier-sales-report', [ReportController::class, 'emailSupplierSalesReport'])->name('report.email.supplier-sales');
Route::post('/email-overview-report', [ReportController::class, 'emailOverviewReport'])->name('report.email.overview-report');
Route::get('/sales/report', [ReportController::class, 'salesfinalReport'])->name('salesemail.report');
Route::get('/send-financial-report', [ReportController::class, 'sendFinancialReportEmail'])->name('send.financial.report');
Route::get('/report/loans/email-simple', [ReportController::class, 'sendLoanReportEmail'])->name('report.loans.email-simple');
Route::get('/grn/send-email', [ReportController::class, 'sendGrnEmail'])->name('grn.sendEmail'); 
//exports
Route::get('/sales-adjustment-report/excel', [ReportController::class, 'exportToExcel'])->name('sales-adjustment.export.excel');
Route::get('/sales-adjustment-report/pdf', [ReportController::class, 'exportToPdf'])->name('sales-adjustment.export.pdf');
Route::get('/grn-sales-overview/download', [ReportController::class, 'downloadGrnSalesOverviewReport'])->name('grn-sales.download');
Route::get('/grn-overview/download2', [ReportController::class, 'downloadGrnOverviewReport2'])->name('grn-overview.download2');
Route::get('/sales-report/download', [ReportController::class, 'downloadSalesReport'])->name('sales.report.download');
Route::get('/grn/export/pdf', [ReportController::class, 'exportPdf'])->name('grn.exportPdf');
Route::get('/grn/export/excel', [ReportController::class, 'exportExcel'])->name('grn.exportExcel');
Route::get('/reports/cheque-payments', [ReportController::class, 'chequePaymentsReport']) ->name('reports.cheque-payments');
Route::post('/reports/update-status/{id}', [ReportController::class, 'updateStatus'])  ->name('reports.update-status');
Route::get('/generate-report', [ReportController::class, 'generateReport']) ->name('generate.report');//returns
Route::get('/grn-entry/{code}/remaining', [App\Http\Controllers\GrnEntryController::class, 'getRemaining']);
//returns
Route::get('/api/grn-entry/{code}', function ($code) {
    $entry = \App\Models\GrnEntry::where('code', $code)->first();
    return response()->json($entry);
});

Route::get('/api/all-bill-nos', function () {
    $salesBillNos = Sale::pluck('bill_no')->toArray();
    $historyBillNos = SalesHistory::pluck('bill_no')->toArray();

    // Merge arrays and remove duplicates
    $allBillNos = array_unique(array_merge($salesBillNos, $historyBillNos));

    return response()->json($allBillNos);
});
//GRN OPTIONS
Route::post('/grn/update-status/{id}', [GrnEntryController::class, 'updateStatus'])->name('grn.updateStatus');
// web.php
Route::get('/grn-entry/{code}', [GrnEntryController::class, 'getGrnEntry'])->name('grn.entry.fetch');
Route::post('/settings/update-balance', [SalesEntryController::class, 'updateBalance'])->name('settings.updateBalance');
//sowin ip address
Route::get('/get-user-ip/{user_id}', function ($user_id) {
    $user = User::where('user_id', $user_id)->first();
    return response()->json([
        'ip_address' => $user?->ip_address ?? null,
    ]);
})->name('get-user-ip');
// Dedicated GRN delete route
Route::delete('/grn/delete/{id}', [GrnEntryController::class, 'destroy'])->name('grn.delete');
//new  GRN TABLE
Route::post('/grn/store2', [GrnEntryController::class, 'store2'])->name('grn.store3');
Route::get('/grn/update/form', [GrnEntryController::class, 'showupdateform'])->name('grn.updateform'); // page with form + table
//new  GRN PDFS FOR UPDATE GRN
Route::post('grn/export/update/excel', [GrnEntryController::class, 'exportUPDATEExcel'])->name('grn.export.excel');
Route::post('grn/export/update/pdf', [GrnEntryController::class, 'exportUPDATEPdf'])->name('grn.export.pdf');

Route::delete('/grn/delete/update/{id}', [GrnEntryController::class, 'destroyupdate'])->name('grnupdate.delete');

//new  Gettin te real time balances for update grn page
Route::get('/grn/balance/{code}', [GrnEntryController::class, 'getBalance'])->name('grn.balance');

//new react dashboard
Route::get('/sales-entry/react', [DashboardController::class, 'index'])->name('dasboard.index'); // page that shows React app
Route::post('/grn/react', [GrnEntryController::class, 'store'])->name('grn.store2'); // existing store handler (JSON)
//given amount
Route::put('/sales/{sale}/given-amount', [SalesEntryController::class, 'updateGivenAmount'])->name('sales.update-given-amount');

require __DIR__.'/auth.php';