<?php

namespace App\Http\Controllers;

use App\Models\IC_UtilityType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IC_UtilityTypeController extends Controller
{
    /**
     * Get all utility types
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = IC_UtilityType::query();
            
            // Filter by type
            if ($request->has('type') && in_array($request->type, ['income', 'expense'])) {
                $query->where('type', $request->type);
            }
            
            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            $utilityTypes = $query->orderBy('type')->orderBy('name')->get();
            
            // Add statistics
            $statistics = [
                'total' => $utilityTypes->count(),
                'income' => $utilityTypes->where('type', 'income')->count(),
                'expense' => $utilityTypes->where('type', 'expense')->count(),
                'active' => $utilityTypes->where('is_active', true)->count(),
                'inactive' => $utilityTypes->where('is_active', false)->count()
            ];
            
            return response()->json([
                'success' => true,
                'data' => $utilityTypes,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching utility types: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch utility types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new utility type
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:income,expense',
            'name' => 'required|string|max:255|unique:ic_utilitytypes,name',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $utilityType = IC_UtilityType::create([
                'type' => $request->type,
                'name' => $request->name,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utility type created successfully',
                'data' => $utilityType
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating utility type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create utility type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single utility type
     */
    public function show($id): JsonResponse
    {
        try {
            $utilityType = IC_UtilityType::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $utilityType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Utility type not found'
            ], 404);
        }
    }

    /**
     * Update utility type
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $utilityType = IC_UtilityType::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'type' => 'sometimes|required|in:income,expense',
                'name' => 'sometimes|required|string|max:255|unique:ic_utilitytypes,name,' . $id,
                'description' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $utilityType->update($request->only(['type', 'name', 'description', 'is_active']));

            return response()->json([
                'success' => true,
                'message' => 'Utility type updated successfully',
                'data' => $utilityType
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating utility type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update utility type'
            ], 500);
        }
    }

    /**
     * Delete utility type
     */
    public function destroy($id): JsonResponse
    {
        try {
            $utilityType = IC_UtilityType::findOrFail($id);
            $utilityType->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utility type deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete utility type'
            ], 500);
        }
    }

    /**
     * Bulk delete utility types
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:ic_utilitytypes,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            IC_UtilityType::whereIn('id', $request->ids)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utility types deleted successfully',
                'deleted_count' => count($request->ids)
            ]);
        } catch (\Exception $e) {
            Log::error('Error bulk deleting utility types: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete utility types'
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleStatus($id): JsonResponse
    {
        try {
            $utilityType = IC_UtilityType::findOrFail($id);
            $utilityType->is_active = !$utilityType->is_active;
            $utilityType->save();

            return response()->json([
                'success' => true,
                'message' => $utilityType->is_active ? 'Utility type activated' : 'Utility type deactivated',
                'data' => $utilityType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle status'
            ], 500);
        }
    }

    /**
     * Get income types (dropdown)
     */
    public function getIncomeTypes(): JsonResponse
    {
        try {
            $types = IC_UtilityType::income()->active()->orderBy('name')->get(['id', 'name']);
            
            return response()->json([
                'success' => true,
                'data' => $types
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch income types'
            ], 500);
        }
    }

    /**
     * Get expense types (dropdown)
     */
    public function getExpenseTypes(): JsonResponse
    {
        try {
            $types = IC_UtilityType::expense()->active()->orderBy('name')->get(['id', 'name']);
            
            return response()->json([
                'success' => true,
                'data' => $types
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expense types'
            ], 500);
        }
    }
}