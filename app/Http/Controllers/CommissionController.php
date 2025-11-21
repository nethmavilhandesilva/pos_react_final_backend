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
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255',
            'starting_price' => 'required|numeric|min:0',
            'end_price' => 'required|numeric|gte:starting_price',
            'commission_amount' => 'required|numeric|min:0', 
        ]);

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
            // item_code and item_name are typically not changed during an update, but we keep them for integrity
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255', 
            'starting_price' => 'required|numeric|min:0',
            'end_price' => 'required|numeric|gte:starting_price',
            'commission_amount' => 'required|numeric|min:0', 
        ]);

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