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
        $customers = Customer::select('id', 'name', 'short_name', 'credit_limit', 'profile_pic', 'nic_front', 'nic_back', 'telephone_no', 'Debtor', 'Debtor_no')->get();
        return response()->json($customers);
    }

  // In CustomerController.php, update the apiStore method to properly handle bill_no
public function apiStore(Request $request)
{
    \Log::info('apiStore request received', [
        'all_data' => $request->all(),
        'bill_no_from_request' => $request->bill_no
    ]);

    $data = $request->validate([
        'short_name' => 'nullable|string',
        'name' => 'nullable|string',
        'ID_NO' => 'nullable|string',
        'telephone_no' => 'nullable|string',
        'address' => 'nullable|string',
        'credit_limit' => 'nullable|numeric',
        'profile_pic' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_front' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'nic_back' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'Debtor' => 'nullable|in:Y,N',
        'bill_no' => 'nullable|string',  // Make sure this is included
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
        $debtorNumber = null;
        if ($data['Debtor'] === 'Y') {
            $debtorNumber = DebtorNumberHelper::generateDebtorNumber();
            $data['Debtor_no'] = $debtorNumber;
            \Log::info('Generated Debtor Number', ['Debtor_no' => $debtorNumber]);
        }

        $customer = Customer::create($data);
        \Log::info('Customer created successfully', ['customer_id' => $customer->id, 'customer_short_name' => $customer->short_name]);

        // If customer is marked as Debtor, create a debtor record
        if ($data['Debtor'] === 'Y' && $debtorNumber) {
            \Log::info('Creating debtor record with bill_no:', ['bill_no' => $data['bill_no'] ?? null]);
            
            // Create debtor record with the bill number
            $debtorData = [
                'bill_no' => $data['bill_no'] ?? null,  // ✅ Make sure bill_no is included
                'customer_code' => $customer->short_name,
                'credit_amount' => 0,
                'paid_amount' => 0,
                'remaining_amount' => 0,
                'status' => 'pending',
                'settled_way' => 'registration',
                'Debtor_no' => $debtorNumber
            ];
            
            \Log::info('Debtor data to create:', $debtorData);
            
            $debtor = Debtor::create($debtorData);
            
            \Log::info('Debtor record created', [
                'debtor_id' => $debtor->id,
                'stored_bill_no' => $debtor->bill_no,
                'customer_code' => $debtor->customer_code
            ]);
        }

        DB::commit();

        return response()->json($customer, 201);
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Error creating customer with debtor record', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);
        return response()->json(['error' => 'Failed to create customer: ' . $e->getMessage()], 500);
    }
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
            'profile_pic' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_front' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'nic_back' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'Debtor' => 'nullable|in:Y,N',
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
        'customer_id' => 'nullable|integer', // Add customer_id validation
        'Debtor' => 'required|in:Y,N',
        'bill_no' => 'nullable|string'
    ]);

    DB::beginTransaction();

    try {
        // Find customer by ID if provided, otherwise by short_name
        $customer = null;
        if ($request->customer_id) {
            $customer = Customer::find($request->customer_id);
        }
        
        if (!$customer && $request->short_name) {
            $customer = Customer::where('short_name', strtoupper($request->short_name))->first();
        }

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $updateData = ['Debtor' => $request->Debtor];
        $debtorNumber = $customer->Debtor_no;
        $wasNewDebtorRecordCreated = false;

        \Log::info('UpdateDebtorStatus called', [
            'customer_short_name' => $customer->short_name,
            'customer_id' => $customer->id,
            'existing_debtor_no' => $customer->Debtor_no,
            'request_bill_no' => $request->bill_no,
            'request_Debtor' => $request->Debtor,
            'provided_customer_id' => $request->customer_id
        ]);

        // If setting as Debtor
        if ($request->Debtor === 'Y') {
            // Use existing Debtor_no, DO NOT generate a new one
            if (empty($customer->Debtor_no)) {
                $debtorNumber = DebtorNumberHelper::generateDebtorNumber();
                $updateData['Debtor_no'] = $debtorNumber;
                \Log::info('Generated new debtor number', [
                    'customer_code' => $customer->short_name,
                    'customer_id' => $customer->id,
                    'debtor_no' => $debtorNumber
                ]);
            } else {
                $debtorNumber = $customer->Debtor_no;
                \Log::info('Using existing debtor number from customer', [
                    'customer_code' => $customer->short_name,
                    'customer_id' => $customer->id,
                    'debtor_no' => $debtorNumber
                ]);
            }

            // Check if debtor record already exists for this specific bill
            $existingDebtor = null;
            if ($request->bill_no) {
                $existingDebtor = Debtor::where('customer_code', $customer->short_name)
                    ->where('bill_no', $request->bill_no)
                    ->first();
                
                \Log::info('Checking for existing debtor', [
                    'customer_code' => $customer->short_name,
                    'customer_id' => $customer->id,
                    'bill_no' => $request->bill_no,
                    'exists' => $existingDebtor ? true : false,
                    'existing_debtor_no' => $existingDebtor ? $existingDebtor->Debtor_no : null
                ]);
            }

            // Create debtor record with bill number if it doesn't exist
            if (!$existingDebtor && $request->bill_no) {
                $debtor = Debtor::create([
                    'bill_no' => $request->bill_no,
                    'customer_code' => $customer->short_name,
                    'credit_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'status' => 'pending',
                    'settled_way' => 'registration',
                    'Debtor_no' => $debtorNumber
                ]);
                $wasNewDebtorRecordCreated = true;
                
                \Log::info('NEW debtor record created', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $customer->short_name,
                    'customer_id' => $customer->id,
                    'debtor_no' => $debtorNumber,
                    'debtor_id' => $debtor->id
                ]);
            } elseif ($existingDebtor) {
                \Log::info('Debtor record already exists for this bill', [
                    'bill_no' => $request->bill_no,
                    'customer_code' => $customer->short_name,
                    'existing_debtor_id' => $existingDebtor->id,
                    'existing_debtor_no' => $existingDebtor->Debtor_no
                ]);
            }
        }

        // Update customer
        $customer->update($updateData);

        DB::commit();

        $message = '';
        if ($request->Debtor === 'Y') {
            if ($wasNewDebtorRecordCreated) {
                $message = $request->bill_no 
                    ? "Debtor record created successfully for Bill #{$request->bill_no} with Debtor No: {$debtorNumber}"
                    : "Debtor registered successfully with Debtor No: {$debtorNumber}";
            } else {
                $message = $request->bill_no 
                    ? "Debtor record already exists for Bill #{$request->bill_no} with Debtor No: {$debtorNumber}"
                    : "Debtor status updated successfully. Debtor No: {$debtorNumber}";
            }
        } else {
            $message = "Customer debtor status removed";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'customer' => $customer,
            'debtor_no' => $debtorNumber,
            'was_new_record_created' => $wasNewDebtorRecordCreated
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Error updating debtor status: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request_data' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to update debtor status: ' . $e->getMessage()
        ], 500);
    }
}
}