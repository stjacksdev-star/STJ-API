<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseController
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'token_name' => 'nullable|string|max:255',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->error('Credenciales invalidas', 401);
        }

        $expirationMinutes = (int) config('sanctum.expiration', 0);
        $expiresAt = $expirationMinutes > 0
            ? Carbon::now()->addMinutes($expirationMinutes)
            : null;
        $tokenName = trim((string) $request->input('token_name', 'stj_api_token'));

        $user->tokens()
            ->where('name', $tokenName)
            ->delete();

        $token = $user->createToken($tokenName, ['*'], $expiresAt);

        return $this->success([
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toISOString(),
            'token_name' => $tokenName,
        ], 'Login correcto');
    }

    public function me(Request $request)
    {
        return $this->success($request->user(), 'Usuario autenticado');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success([], 'Sesion cerrada');
    }
}
