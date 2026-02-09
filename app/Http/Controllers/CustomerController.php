<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function apiIndex()
    {
        $customers = Customer::select('id', 'name', 'short_name', 'credit_limit', 'profile_pic', 'nic_front', 'nic_back')->get();
        return response()->json($customers);
    }

    public function apiStore(Request $request)
    {
        $data = $request->validate([
            'short_name'   => 'nullable|string',
            'name'         => 'nullable|string',
            'ID_NO'        => 'nullable|string',
            'telephone_no' => 'nullable|string',
            'address'      => 'nullable|string',
            'credit_limit' => 'nullable|numeric',
            'profile_pic'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'nic_front'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'nic_back'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // Handle File Uploads
        if ($request->hasFile('profile_pic')) {
            $data['profile_pic'] = $request->file('profile_pic')->store('customers/profiles', 'public');
        }
        if ($request->hasFile('nic_front')) {
            $data['nic_front'] = $request->file('nic_front')->store('customers/nic', 'public');
        }
        if ($request->hasFile('nic_back')) {
            $data['nic_back'] = $request->file('nic_back')->store('customers/nic', 'public');
        }

        if (!empty($data['short_name'])) {
            $data['short_name'] = strtoupper($data['short_name']);
        }

        $customer = Customer::create($data);
        return response()->json($customer, 201);
    }

  public function apiUpdate(Request $request, Customer $customer)
{
    $data = $request->validate([
        'short_name'   => 'nullable|string',
        'name'         => 'nullable|string',
        'ID_NO'        => 'nullable|string',
        'telephone_no' => 'nullable|string',
        'address'      => 'nullable|string',
        'credit_limit' => 'nullable|numeric',
        // Use 'file' instead of 'image' for better compatibility
        'profile_pic'  => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_front'    => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        'nic_back'     => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
    ]);

    $fields = ['profile_pic', 'nic_front', 'nic_back'];
    foreach ($fields as $field) {
        if ($request->hasFile($field)) {
            // Delete old file
            if ($customer->$field) {
                Storage::disk('public')->delete($customer->$field);
            }
            // Store new file
            $data[$field] = $request->file($field)->store('customers', 'public');
        }
    }

    $customer->update($data);
    return response()->json($customer);
}
    public function apiDestroy(Customer $customer)
    {
        // Delete images from storage before deleting record
        Storage::disk('public')->delete([$customer->profile_pic, $customer->nic_front, $customer->nic_back]);
        $customer->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
   public function checkOrCreate(Request $request)
{
    // Check by short_name (Customer Code)
    $customer = Customer::where('short_name', strtoupper($request->short_name))->first();

    if ($customer) {
        // If it exists but didn't have a phone, you might want to update it
        if (!$customer->telephone_no && $request->telephone_no) {
            $customer->update(['telephone_no' => $request->telephone_no]);
        }
        
        return response()->json(['was_created' => false, 'customer' => $customer]);
    }

    // Create if totally new
    $newCustomer = Customer::create([
        'short_name' => strtoupper($request->short_name),
        'name' => strtoupper($request->short_name),
        'telephone_no' => $request->telephone_no,
    ]);

    return response()->json(['was_created' => true, 'customer' => $newCustomer]);
}
}
