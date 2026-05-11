<?php

namespace App\Http\Controllers;

use App\Helpers\DebtorNumberHelper;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Models\Debtor;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function apiIndex()
    {
        $customers = Customer::select('id', 'name', 'short_name', 'credit_limit', 'profile_pic', 'nic_front', 'nic_back', 'telephone_no', 'Debtor')->get();
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
            'Debtor'       => 'nullable|in:Y,N',
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

        // Set Debtor default if not provided
        if (!isset($data['Debtor'])) {
            $data['Debtor'] = 'N';
        }

        DB::beginTransaction();
        
        try {
            // Generate Debtor number if Debtor is Y
            if ($data['Debtor'] === 'Y') {
                $data['Debtor_no'] = DebtorNumberHelper::generateDebtorNumber();
            }

            $customer = Customer::create($data);
            
            // If customer is marked as Debtor, create a debtor record
            if ($data['Debtor'] === 'Y' && $data['Debtor_no']) {
                Debtor::create([
                    'bill_no' => null, // No bill number for initial debtor registration
                    'customer_code' => $customer->short_name,
                    'credit_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'status' => 'pending',
                    'settled_way' => 'registration',
                    'Debtor_no' => $data['Debtor_no']
                ]);
            }
            
            DB::commit();
            
            return response()->json($customer, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating customer with debtor record: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create customer'], 500);
        }
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
            'profile_pic'  => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_front'    => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_back'     => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'Debtor'       => 'nullable|in:Y,N',
        ]);

        $fields = ['profile_pic', 'nic_front', 'nic_back'];
        foreach ($fields as $field) {
            if ($request->hasFile($field)) {
                if ($customer->$field) {
                    Storage::disk('public')->delete($customer->$field);
                }
                $data[$field] = $request->file($field)->store('customers', 'public');
            }
        }

        $customer->update($data);
        return response()->json($customer);
    }

    public function apiDestroy(Customer $customer)
    {
        $files = array_filter([$customer->profile_pic, $customer->nic_front, $customer->nic_back]);

        if (!empty($files)) {
            Storage::disk('public')->delete($files);
        }

        $customer->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    public function checkOrCreate(Request $request)
    {
        $customer = Customer::where('short_name', strtoupper($request->short_name))->first();

        if ($customer) {
            if (!$customer->telephone_no && $request->telephone_no) {
                $customer->update(['telephone_no' => $request->telephone_no]);
            }
            if ($request->Debtor === 'Y') {
                $customer->update(['Debtor' => 'Y']);
            }
            return response()->json(['was_created' => false, 'customer' => $customer]);
        }

        $newCustomer = Customer::create([
            'short_name' => strtoupper($request->short_name),
            'name' => strtoupper($request->short_name),
            'telephone_no' => $request->telephone_no,
            'Debtor' => $request->Debtor ?? 'N',
        ]);

        return response()->json(['was_created' => true, 'customer' => $newCustomer]);
    }

    public function checkShortName($short_name)
    {
        $customer = Customer::where('short_name', strtoupper($short_name))->first();

        return response()->json([
            'exists' => $customer !== null,
            'customer' => $customer,
            'is_debtor' => $customer ? $customer->Debtor === 'Y' : false
        ]);
    }

    public function getDebtorStatus($short_name)
    {
        $customer = Customer::where('short_name', strtoupper($short_name))->first();

        return response()->json([
            'exists' => $customer !== null,
            'is_debtor' => $customer ? $customer->Debtor === 'Y' : false,
            'customer' => $customer
        ]);
    }

  public function updateDebtorStatus(Request $request)
{
    $request->validate([
        'short_name' => 'required|string',
        'Debtor' => 'required|in:Y,N',
        'bill_no' => 'nullable|string'  // Add bill_no validation
    ]);

    DB::beginTransaction();
    
    try {
        $customer = Customer::where('short_name', strtoupper($request->short_name))->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $updateData = ['Debtor' => $request->Debtor];
        $debtorNumber = null;
        
        // If setting as Debtor
        if ($request->Debtor === 'Y') {
            // Generate new debtor number if not exists
            if (empty($customer->Debtor_no)) {
                $debtorNumber = DebtorNumberHelper::generateDebtorNumber();
                $updateData['Debtor_no'] = $debtorNumber;
            } else {
                $debtorNumber = $customer->Debtor_no;
            }
            
            // ✅ Check if debtor record already exists for this customer AND specific bill
            $existingDebtor = Debtor::where('customer_code', $customer->short_name)
                ->where('bill_no', $request->bill_no)  // Match specific bill
                ->first();
            
            // Create debtor record with bill number if it doesn't exist
            if (!$existingDebtor && $request->bill_no) {
                Debtor::create([
                    'bill_no' => $request->bill_no,  // ✅ Use the bill_no from request
                    'customer_code' => $customer->short_name,
                    'credit_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'status' => 'pending',
                    'settled_way' => 'credit',  // Use 'credit' instead of 'registration'
                    'Debtor_no' => $debtorNumber
                ]);
                \Log::info('Debtor record created from status update', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $customer->short_name,
                    'debtor_no' => $debtorNumber
                ]);
            } elseif (!$existingDebtor && !$request->bill_no) {
                // Create registration record without bill number
                Debtor::create([
                    'bill_no' => null,
                    'customer_code' => $customer->short_name,
                    'credit_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'status' => 'pending',
                    'settled_way' => 'credit',
                    'Debtor_no' => $debtorNumber
                ]);
            }
        }
        
        $customer->update($updateData);
        
        DB::commit();
        
        return response()->json([
            'success' => true, 
            'customer' => $customer,
            'debtor_no' => $customer->Debtor_no
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Error updating debtor status: ' . $e->getMessage());
        return response()->json([
            'success' => false, 
            'message' => 'Failed to update debtor status: ' . $e->getMessage()
        ], 500);
    }
}
}