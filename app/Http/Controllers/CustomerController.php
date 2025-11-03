<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    // app/Http/Controllers/CustomerController.php

public function apiIndex()
{
    return response()->json(Customer::all());
}
public function index(): JsonResponse
    {
        $customers = Customer::all();
        return response()->json(['customers' => $customers]);
    }

public function apiStore(Request $request)
{
    $data = $request->validate([
        'short_name' => 'nullable|string',
        'name' => 'nullable|string',
        'ID_NO' => 'nullable|string',
        'telephone_no' => 'nullable|string',
        'address' => 'nullable|string',
        'credit_limit' => 'nullable|numeric',
    ]);

    if (!empty($data['short_name'])) {
        $data['short_name'] = strtoupper($data['short_name']);
    }

    $customer = Customer::create($data);
    return response()->json($customer, 201);
}

public function apiUpdate(Request $request, Customer $customer)
{
    $data = $request->validate([
        'short_name' => 'nullable|string',
        'name' => 'nullable|string',
        'ID_NO' => 'nullable|string',
        'telephone_no' => 'nullable|string',
        'address' => 'nullable|string',
        'credit_limit' => 'nullable|numeric',
    ]);

    $customer->update($data);
    return response()->json($customer);
}

public function apiDestroy(Customer $customer)
{
    $customer->delete();
    return response()->json(['message' => 'Deleted successfully']);
}

}
