<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseController
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Credenciales inválidas', 401);
        }

        $token = $user->createToken('stj_api_token')->plainTextToken;

        return $this->success([
            'user' => $user,
            'token' => $token
        ], 'Login correcto');
    }

    public function me(Request $request)
    {
        return $this->success($request->user(), 'Usuario autenticado');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success([], 'Sesión cerrada');
    }
}