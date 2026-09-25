<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/admin. Requires a Sanctum token that belongs to an active Admin
 * (never an app User). Optional role list: `admin.role:super_admin,admin`.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->is_active) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($roles !== [] && $admin->role !== 'super_admin' && ! in_array($admin->role, $roles, true)) {
            return response()->json(['message' => 'Your role cannot do that.'], 403);
        }

        return $next($request);
    }
}
