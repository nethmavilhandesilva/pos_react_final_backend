<?php

use App\Http\Controllers\CreditorController;
use App\Http\Controllers\DebtorCreditorController;
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
use App\Http\Controllers\DebtorController;
use App\Http\Controllers\CashierBalanceController;

// ----------------------------------------------------------------------
// ðŸš¨ PUBLIC ROUTES (No Authentication Required) ðŸš¨
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
// Debug endpoint for history table
Route::get('/supplier-loan/debug-history', [SupplierLoanController::class, 'debugHistory']);
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

// ----------------------------------------------------------------------
// âœ… PROTECTED ROUTES (Requires 'auth:sanctum') 
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

    // ==================== CUSTOMER ROUTES ====================
    Route::get('/customers', [CustomerController::class, 'apiIndex']);
    Route::post('/customers', [CustomerController::class, 'apiStore']);
    Route::post('/customers/update/{customer}', [CustomerController::class, 'apiUpdate']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);
    Route::get('/customers/debtor-status/{short_name}', [CustomerController::class, 'getDebtorStatus']);
    Route::put('/customers/update-debtor-status', [CustomerController::class, 'updateDebtorStatus']);

    // ==================== ITEM ROUTES ====================
    Route::apiResource('items', ItemController::class);
    Route::get('items/search/{query}', [ItemController::class, 'search']);

    // ==================== SUPPLIER ROUTES ====================
    // ðŸ”´ CRITICAL: These specific routes MUST come BEFORE Route::apiResource
    Route::get('/suppliers/loan-summary', [SupplierLoanController::class, 'getLoanSummary']);
    Route::get('/suppliers/all-codes', [SupplierLoanController::class, 'getAllCodes']);
    Route::get('/suppliers/full-report', [SupplierLoanController::class, 'getFarmerFullReport']);
    Route::get('/suppliers/bill-status-summary', [SupplierController::class, 'getSupplierBillStatusSummary']);
    
    // MAIN ROUTE for supplier loans summary - used by the frontend
    Route::get('/suppliers/supplierloans', [SupplierLoanController::class, 'getSupplierLoansSummary']);
    
    // VIEW OLD BILLS ROUTE - alias that also uses getSupplierLoansSummary with use_history parameter
    Route::get('/suppliers/old-bills-summary', [SupplierLoanController::class, 'getSupplierLoansSummary']);

    // âœ… CRITICAL: This route MUST come BEFORE any wildcard routes like /suppliers/{supplierCode}
    Route::get('/suppliers/by-letter', [SupplierLoanController::class, 'getSuppliersByLetter']);

    Route::get('/suppliers/{supplierCode}/details', [SupplierController::class, 'getSupplierDetails']);
    Route::post('/suppliers/delete-loan-record', [SupplierLoanController::class, 'deleteLoanRecord']);
    Route::get('/suppliers/bill/{billNo}/details', [SupplierLoanController::class, 'getSupplierBillDetails']);
    Route::get('/suppliers/unprinted-details/{supplierCode}', [SupplierLoanController::class, 'getUnprintedDetails']);
    Route::get('/suppliers/with-bills', [SupplierController::class, 'getSuppliersWithBills']);

    // âœ… CRITICAL CUSTOM ROUTES - MUST BE BEFORE apiResource
    Route::put('/suppliers/update-creditor-status', [SupplierController::class, 'updateCreditorStatus']);
    Route::get('/suppliers/check-supplier/{code}', [SupplierController::class, 'checkSupplierExists']);
    Route::get('/suppliers/creditor-status/{code}', [SupplierController::class, 'getCreditorStatus']);

    // Default REST API (must come AFTER specific routes)
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/search/{query}', [SupplierController::class, 'search']);

    // ==================== GRN ENTRY ROUTES ====================
    Route::get('/grn-entries/latest', [GrnEntryController::class, 'getLatestEntries']);
    Route::get('/grn-entries/create-data', [GrnEntryController::class, 'createData']);
    Route::get('/grn-entries/code/{code}', [GrnEntryController::class, 'getByCode']);
    Route::apiResource('grn-entries', GrnEntryController::class);

    // ==================== CUSTOMER LOANS ROUTES ====================
    Route::get('/customers-loans', [CustomersLoanController::class, 'index']);
    Route::post('/customers-loans', [CustomersLoanController::class, 'store']);
    Route::get('/customers-loans/{customerId}/total', [CustomersLoanController::class, 'getCustomerLoanTotal']);
    Route::put('/customers-loans/{id}', [CustomersLoanController::class, 'update']);
    Route::delete('/customers-loans/{id}', [CustomersLoanController::class, 'destroy']);
    Route::get('/customers-loans/data', [CustomersLoanController::class, 'getInitialData']);
    Route::get('/customers-loans/index', [CustomersLoanController::class, 'index']);

    // ==================== GRN UPDATE ROUTES ====================
    Route::get('/not-changing-grns', [GrnEntryController::class, 'getNotChangingGRNs']);
    Route::get('/grn/balance/{code}', [GrnEntryController::class, 'getGrnBalance']);
    Route::post('/grn/store2', [GrnEntryController::class, 'store2']);
    Route::delete('/grn/delete/update/{id}', [GrnEntryController::class, 'destroyupdate']);

    // ==================== REPORT ROUTES ====================
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
    Route::get('/supplier-report', [ReportController::class, 'getSupplierReport']);
    Route::get('/sales/payment-report', [SalesEntryController::class, 'getPaymentReport']);
    Route::get('/sales/dashboard-stats', [SalesEntryController::class, 'getDashboardStats']);

    Route::prefix('reports')->group(function () {
        Route::post('/generate', [ReportController::class, 'generateReport']);
        Route::post('/download-pdf', [ReportController::class, 'downloadPDF']);
        Route::post('/download-excel', [ReportController::class, 'downloadExcel']);
    });

    // ==================== LOAN REPORT ====================
    Route::get('/customers-loans/report', [ReportController::class, 'loanReport']);

    // ==================== GRN REPORT ====================
    Route::get('/grn-codes', [ReportController::class, 'fetchGrnCodes']);
    Route::get('/grn-report', [ReportController::class, 'grnReport2']);
    Route::get('/financial-report', [ReportController2::class, 'getFinancialData']);

    // ==================== SETTINGS ====================
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

    // ==================== CASHIER DASHBOARD ROUTES ====================
    Route::get('/sales/all', [SalesEntryController::class, 'getAllSales2'])->name('sales.all');
    Route::put('/sales/update-given-amount-applied', [SalesEntryController::class, 'updateGivenAmountApplied'])->name('sales.update-given-amount-applied');
    Route::get('/sales/payment-history/{billNo}', [SalesEntryController::class, 'getPaymentHistory'])->name('sales.payment-history');
    Route::put('/sales/{saleId}/given-amount', [SalesEntryController::class, 'updateSaleGivenAmount'])->name('sales.update-sale-given-amount')->where('saleId', '[0-9]+');
    Route::delete('/sales/delete-bill-payments/{billNo}', [SalesEntryController::class, 'deleteBillPayments']);

    Route::get('/sales', [SalesEntryController::class, 'index']);
    Route::post('/sales', [SalesEntryController::class, 'store']);
    Route::put('/sales/{sale}', [SalesEntryController::class, 'update'])->where('sale', '[0-9]+');
    Route::delete('/sales/{sale}', [SalesEntryController::class, 'destroy'])->where('sale', '[0-9]+');

    Route::post('/sales/mark-printed', [SalesEntryController::class, 'markAsPrinted']);
    Route::post('/sales/mark-all-processed', [SalesEntryController::class, 'markAllAsProcessed']);
    Route::post('/get-loan-amount', [SalesEntryController::class, 'getLoanAmount']);

    // ==================== COMMISSIONS (CRUD) ====================
    Route::resource('commissions', CommissionController::class)->except(['create', 'edit']);

    // ==================== SUPPLIER BILL NUMBER ====================
    Route::get('/generate-f-series-bill', [SupplierController::class, 'generateFSeriesBill']);
    Route::get('sales/profit-by-supplier', [SupplierController::class, 'getProfitBySupplier']);
    Route::post('/suppliers/mark-as-printed', [SupplierController::class, 'marksuppliers']);
    Route::get('/suppliers/bill/{billNo}/details', [SupplierController::class, 'getBillDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details', [SupplierController::class, 'getUnprintedDetails']);
    Route::get('/suppliers/{supplierCode}/unprinted-details2', [SupplierController::class, 'getUnprintedDetails2']);

    // ==================== DAY PROCESS ====================
    Route::post('/sales/process-day', [SalesEntryController::class, 'processDay']);

    // ==================== LOAN SECTION ====================
    Route::post('/customers-loans/{id}', [CustomersLoanController::class, 'updateApi']);
    Route::get('/customers/{customerId}/loans-total', [CustomersLoanController::class, 'getTotalLoanAmount']);
    Route::post('/settings/updateBalance', [CustomersLoanController::class, 'updateBalance']);
    Route::get('/api/grn-entry/{code}', [CustomersLoanController::class, 'getGrnEntry']);
    Route::get('/api/all-bill-nos', [CustomersLoanController::class, 'getAllBillNos']);
    Route::post('/loan-report-results', [CustomersLoanController::class, 'getLoanReportData']);
    Route::get('/utility-types/income', [CustomersLoanController::class, 'getIncomeTypes']);
    Route::get('/utility-types/expense', [CustomersLoanController::class, 'getExpenseTypes']);

    // ==================== UPDATE GIVEN AMOUNT ====================
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

    // ==================== BANK ROUTES ====================
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
        Route::get('/cheques-report', [BankController::class, 'getChequePaymentsReport']);
    });

    // ==================== ADJUSTMENT ROUTES ====================
    Route::get('/adjustments/pending-customer-bills', [SalesEntryController::class, 'getPendingCustomerBills']);
    Route::get('/adjustments/pending-farmer-bills', [SalesEntryController::class, 'getPendingFarmerBills']);
    Route::post('/adjustments/apply', [SalesEntryController::class, 'applyPaymentAdjustment']);

    // ==================== SUPPLIER LOAN ROUTES (CRITICAL: Order matters!) ====================
    // IMPORTANT: These specific routes should come before any wildcard routes
    Route::get('/supplier-loans/adjusted-total', [SupplierLoanController::class, 'getAdjustedTotal']);
    Route::get('/supplier-loan/search', [SupplierLoanController::class, 'findLoan']);
    Route::get('/supplier-loan/payment-history', [SupplierLoanController::class, 'getPaymentHistory']);
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

    // Supplier Loan Summary Routes
    Route::put('/supplier-loan/{id}', [SupplierLoanController::class, 'update']);
    Route::get('/supplier-loan/loan-summary', [SupplierLoanController::class, 'getLoanSummary']);

    // ==================== FARMER LOAN ROUTES ====================
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

    // ==================== CASHIER REPORTS ====================
    Route::get('/payment-collection-report', [SalesEntryController::class, 'getPaymentCollectionReport']);
    Route::get('/payment-breakdown', [SalesEntryController::class, 'getPaymentBreakdown']);
    Route::get('/supplier-payment-collection-report', [SupplierLoanController::class, 'getPaymentCollectionReport']);
    Route::get('/payment-details-by-bill', [SupplierLoanController::class, 'getPaymentDetailsByBill']);

    // ==================== UTILITY TYPES ====================
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

    // ==================== INCOME EXPENSE REPORT ROUTES ====================
    Route::get('/income-expense-report', [CustomersLoanController::class, 'getIncomeExpenseReport']);
    Route::get('/income-expense-category-summary', [CustomersLoanController::class, 'getCategorySummary']);
    Route::get('/income-expense-export', [CustomersLoanController::class, 'exportReport']);

    // ==================== SUPPLIER CREDITOR ROUTES ====================
    Route::get('/suppliers/check-creditor/{code}', [SupplierController::class, 'getSupplierByCode']);
    Route::post('/suppliers/check-or-create-creditor', [SupplierController::class, 'checkOrCreateCreditor']);

    // ==================== SUPPLIER DETAILED REPORT ====================
    Route::get('/supplier-detailed-report/{supplierCode}', [SupplierController::class, 'getDetailedReport']);

    // Debtor Routes
    Route::post('/debtors/create-with-customer', [DebtorController::class, 'createDebtorWithCustomer']);
    Route::prefix('debtors')->group(function () {
        Route::post('/create', [DebtorController::class, 'createDebt']);
        Route::put('/update-payment', [DebtorController::class, 'updateDebtorPayment']);
        Route::get('/{billNo}', [DebtorController::class, 'getDebtor']);
        Route::get('/customer/{customerCode}', [DebtorController::class, 'getCustomerDebtors']);
        Route::get('/pending/all', [DebtorController::class, 'getPendingDebtors']);
        Route::get('/by-number/{debtorNo}', [DebtorController::class, 'getDebtorByNumber']);
    });

    // Creditor routes (similar to Debtor but for suppliers)
    Route::post('/creditors/create', [CreditorController::class, 'createCreditor']);
    Route::put('/creditors/update-payment', [CreditorController::class, 'updateCreditorPayment']);
    Route::get('/creditors/{billNo}', [CreditorController::class, 'getCreditor']);
    Route::get('/creditors/supplier/{supplierCode}', [CreditorController::class, 'getSupplierCreditors']);
    Route::get('/creditors/pending/all', [CreditorController::class, 'getPendingCreditors']);
    Route::post('/creditors/create-with-supplier', [CreditorController::class, 'createCreditorWithSupplier']);
    
    // Debtor and Creditor Report Routes
    Route::get('/debtor-creditor/combined', [DebtorCreditorController::class, 'getCombinedReport']);
    Route::get('/debtor-creditor/debtor/{code}', [DebtorCreditorController::class, 'getDebtorDetails']);
    Route::get('/debtor-creditor/creditor/{code}', [DebtorCreditorController::class, 'getCreditorDetails']);
    Route::get('/debtor-creditor/debtors', [DebtorCreditorController::class, 'getDebtorReport']);
    Route::get('/debtor-creditor/creditors', [DebtorCreditorController::class, 'getCreditorReport']);

    // Add this inside the authenticated routes group
    Route::get('/sales/archived', [SalesEntryController::class, 'getArchivedSales']);
  Route::put('/sales/update-customer-and-debtor', [SalesEntryController::class, 'updateCustomerAndDebtor']);
 // Add this line to your routes/api.php file
    Route::get('/income-sources', [SalesEntryController::class, 'getIncomeSources']);
    Route::get('/income-filter-options', [SalesEntryController::class, 'getIncomeFilterOptions']);
    //cashbalance routes
    Route::prefix('cashier-balance')->group(function () {
        Route::post('/record-payment', [CashierBalanceController::class, 'recordPayment']);
        Route::get('/balance', [CashierBalanceController::class, 'getBalance']);
        Route::get('/detailed-balance', [CashierBalanceController::class, 'getDetailedBalance']);
     Route::post('/allocate-funds', [CashierBalanceController::class, 'allocateFunds']); // NEW
    Route::get('/allocated-funds', [CashierBalanceController::class, 'getAllocatedFunds']); // NEW
    Route::get('/bank-list', [CashierBalanceController::class, 'getBankList']); // NEW
    });
});