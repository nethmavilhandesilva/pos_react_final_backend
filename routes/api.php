<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\SupplierController;

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