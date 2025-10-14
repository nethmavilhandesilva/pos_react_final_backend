<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function index()
    {
        $items = Item::all();
        return response()->json($items);
    }

    public function store(Request $request)
    {
        $request->validate([
            'no'        => 'required',
            'type'      => 'required',
            'pack_cost' => 'required|numeric',
            'pack_due'  => 'required|numeric',
        ]);

        $data = $request->all();
        $data['no'] = strtoupper($data['no']);

        $item = Item::create($data);

        return response()->json([
            'message' => 'Item added successfully!',
            'item' => $item
        ], 201);
    }

    public function show(Item $item)
    {
        return response()->json($item);
    }

    public function update(Request $request, Item $item)
    {
        $request->validate([
            'no' => 'required',
            'type' => 'required',
            'pack_cost' => 'required|numeric',
            'pack_due' => 'required|numeric',
        ]);

        $data = $request->all();
        $data['no'] = strtoupper($data['no']);

        $item->update($data);

        return response()->json([
            'message' => 'Item updated successfully!',
            'item' => $item
        ]);
    }

    public function destroy(Item $item)
    {
        $item->delete();
        return response()->json(['message' => 'Item deleted successfully!']);
    }

    public function search($query)
    {
        $items = Item::where('no', 'LIKE', $query . '%')
                    ->orWhere('type', 'LIKE', $query . '%')
                    ->get();
        
        return response()->json($items);
    }
}