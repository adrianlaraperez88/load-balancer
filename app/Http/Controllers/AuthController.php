<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate user and issue a personal access token (Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:100',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'web-client';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Authenticated successfully.',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Get the authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'user' => $request->user(),
        ]);
    }

    /**
     * Revoke the current personal access token (Logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Token successfully revoked.',
        ]);
    }

    /**
     * A sample protected compute workload requiring Sanctum authentication.
     */
    public function processWorkload(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'message' => 'Compute workload executed successfully.',
            'authenticated_user_id' => $user->id,
            'authenticated_user_email' => $user->email,
            'payload_processed' => $request->all(),
            'executed_at' => now()->toISOString(),
        ]);
    }
}
