<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::query()->where('email', strtolower($data['email']))->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password) || ! $admin->is_active) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken('crm', ['admin'])->plainTextToken;
        $request->setUserResolver(fn () => $admin);
        AuditLogger::log($request, 'auth.login', 'admin', $admin->id);

        return response()->json(['token' => $token, 'admin' => $this->present($admin)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['admin' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user();
        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $admin->forceFill(['password' => $data['password']])->save();
        AuditLogger::log($request, 'auth.password_changed', 'admin', $admin->id);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }
}
