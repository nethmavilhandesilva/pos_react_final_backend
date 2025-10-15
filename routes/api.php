<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\GrnEntryController;
use App\Http\Controllers\CustomersLoanController;

//CUSTOMERS
Route::get('/customers', [CustomerController::class, 'apiIndex']);
Route::post('/customers', [CustomerController::class, 'apiStore']);
Route::put('/customers/{customer}', [CustomerController::class, 'apiUpdate']);
Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);
//ITEMS
Route::apiResource('items', ItemController::class);
Route::get('items/search/{query}', [ItemController::class, 'search']);
// API Routes for Suppliers
Route::apiResource('suppliers', SupplierController::class);
Route::get('suppliers/search/{query}', [SupplierController::class, 'search']);
// GRN Entry API Routes
Route::get('/grn-entries', [GrnEntryController::class, 'index']);
Route::get('/grn-entries/create-data', [GrnEntryController::class, 'createData']);
Route::post('/grn-entries', [GrnEntryController::class, 'store']);
Route::get('/grn-entries/{id}', [GrnEntryController::class, 'show']);
Route::put('/grn-entries/{id}', [GrnEntryController::class, 'update']);
Route::delete('/grn-entries/{id}', [GrnEntryController::class, 'destroy']);
// Customers Loan API Routes
Route::get('/customers-loans', [CustomersLoanController::class, 'index']);
Route::post('/customers-loans', [CustomersLoanController::class, 'store']);
Route::get('/customers-loans/{customerId}/total', [CustomersLoanController::class, 'getCustomerLoanTotal']);
Route::put('/customers-loans/{id}', [CustomersLoanController::class, 'update']);
Route::delete('/customers-loans/{id}', [CustomersLoanController::class, 'destroy']);
//GRN UPDATE API Routes
Route::get('/not-changing-grns', [GrnEntryController::class, 'getNotChangingGRNs']);
Route::get('/grn/balance/{code}', [GrnEntryController::class, 'getGrnBalance']);
Route::post('/grn/store2', [GrnEntryController::class, 'store2']);
Route::delete('/grn/delete/update/{id}', [GrnEntryController::class, 'destroyupdate']);
Route::get('/grn-entries/code/{code}', [GrnEntryController::class, 'getByCode']);