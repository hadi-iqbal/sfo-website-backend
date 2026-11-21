<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();

            if (! $user || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => 'Those credentials do not match our records.']);
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages(['email' => 'This account is inactive.']);
            }

            $user->load('permissions');
            $token = $user->requires_profile_setup
                ? $user->createToken('portal setup', ['profile:setup'], now()->addHour())
                : $user->createToken('admin', ['portal']);

            return response()->json(['token' => $token->plainTextToken, 'user' => $user->toAdminArray()])->header('Cache-Control', 'no-store');
        });
    }

    public function me(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_active, 403, 'This account is inactive.');
        $user->load('permissions');

        return response()->json(['user' => $user->toAdminArray()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
