<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;

Route::get('/customers', [CustomerController::class, 'apiIndex']);
Route::post('/customers', [CustomerController::class, 'apiStore']);
Route::put('/customers/{customer}', [CustomerController::class, 'apiUpdate']);
Route::delete('/customers/{customer}', [CustomerController::class, 'apiDestroy']);