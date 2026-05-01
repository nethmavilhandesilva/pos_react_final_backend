<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    // Register user (stores hashed password)
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'user_id'  => 'required|string|max:255|unique:users,user_id',
            'password' => 'required|string|min:6|confirmed', // expects password_confirmation
            'role'     => 'nullable|string',
            'email'    => 'nullable|email|max:255',
        ]);

        try {
            $user = User::create([
                'name'       => $request->name,
                'user_id'    => $request->user_id,
                'email'      => $request->email ?? null,
                'password'   => Hash::make($request->password),
                'role'       => $request->role ?? 'User',
                'ip_address' => $request->ip(),
            ]);

            // hide password in response
            $user->makeHidden(['password']);

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'user'    => $user
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Login by user_id + password
    public function login(Request $request)
    {
        $request->validate([
            'user_id'  => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $user = User::where('user_id', $request->user_id)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The provided credentials are incorrect.'
                ], 401);
            }

            // Update IP or login info
            $user->update(['ip_address' => $request->ip()]);

            // Delete existing tokens (optional - for single device login)
            // $user->tokens()->delete();

            // Create Sanctum Token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Hide password
            $user->makeHidden(['password']);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'token'   => $token,
                'user'    => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'role'     => $user->role,
                    'user_id'  => $user->user_id,
                    'ip_address' => $user->ip_address,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get authenticated user
    public function getUser(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'user'    => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'role'     => $user->role,
                    'user_id'  => $user->user_id,
                    'ip_address' => $user->ip_address,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user: ' . $e->getMessage()
            ], 500);
        }
    }

    // Logout user (revoke token)
    public function logout(Request $request)
    {
        try {
            // Revoke the token that was used to authenticate the current request
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Change password
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        try {
            $user = $request->user();

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 401);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Password change failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update user profile
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name'  => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        try {
            $user = $request->user();
            
            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user'    => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'role'     => $user->role,
                    'user_id'  => $user->user_id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Profile update failed: ' . $e->getMessage()
            ], 500);
        }
    }
}