<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Item;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    // --- API to LIST all commissions ---
    public function index()
    {
        return Commission::all();
    }

    // --- API to get item options (dropdown) ---
    public function getItemOptions()
    {
        return Item::select('no as item_code', 'type as item_name')->get();
    }

    // --- API to store a new commission ---
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'item_code' => 'nullable|string|max:255',
            'item_name' => 'nullable|string|max:255',
            'supplier_code' => 'nullable|string|max:255',
            'supplier_name' => 'nullable|string|max:255',
            'starting_price' => 'nullable|numeric|min:0',
            'end_price' => 'nullable|numeric|gte:starting_price',
            'commission_amount' => 'nullable|numeric|min:0',
        ]);

        // Determine the type
        if (!empty($validatedData['item_code'])) {
            $validatedData['type'] = 'T';
        } elseif (!empty($validatedData['supplier_code'])) {
            $validatedData['type'] = 'S';
        } else {
            $validatedData['type'] = 'Z';
        }

        $commission = Commission::create($validatedData);

        return response()->json([
            'message' => 'Commission created successfully!',
            'commission' => $commission
        ], 201);
    }

    // --- API to get a single commission for editing ---
    public function show(Commission $commission)
    {
        return $commission;
    }

    // --- API to UPDATE an existing commission ---
    public function update(Request $request, Commission $commission)
    {
        $validatedData = $request->validate([
            'item_code' => 'nullable|string|max:255',
            'item_name' => 'nullable|string|max:255',
            'supplier_code' => 'nullable|string|max:255',
            'supplier_name' => 'nullable|string|max:255',
            'starting_price' => 'required|numeric|min:0',
            'end_price' => 'required|numeric|gte:starting_price',
            'commission_amount' => 'required|numeric|min:0',
        ]);

        // Determine the type
        if (!empty($validatedData['item_code'])) {
            $validatedData['type'] = 'T';
        } elseif (!empty($validatedData['supplier_code'])) {
            $validatedData['type'] = 'S';
        } else {
            $validatedData['type'] = 'Z';
        }

        $commission->update($validatedData);

        return response()->json([
            'message' => 'Commission updated successfully!',
            'commission' => $commission
        ]);
    }

    // --- API to DELETE a commission ---
    public function destroy(Commission $commission)
    {
        $commission->delete();

        return response()->json([
            'message' => 'Commission deleted successfully!'
        ]);
    }
}
