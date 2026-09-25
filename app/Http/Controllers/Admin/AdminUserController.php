<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CRM staff accounts (super_admin only) and the audit trail. */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Admin::query()->orderBy('id')->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(Admin::ROLES)],
        ]);
        $admin = Admin::query()->create(array_merge($data, ['email' => strtolower($data['email']), 'is_active' => true]));
        AuditLogger::log($request, 'admin.create', 'admin', $admin->id, ['role' => $admin->role]);

        return response()->json($admin, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'role' => ['sometimes', Rule::in(Admin::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);
        $admin = Admin::query()->findOrFail($id);

        if ($admin->id === $request->user()->id && (isset($data['role']) || isset($data['is_active']))) {
            return response()->json(['message' => 'You cannot change your own role or status.'], 422);
        }

        $admin->fill($data)->save();
        if (isset($data['is_active']) && ! $data['is_active'] || isset($data['password'])) {
            $admin->tokens()->delete();
        }
        AuditLogger::log($request, 'admin.update', 'admin', $id, ['fields' => array_keys($data)]);

        return response()->json($admin);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete yourself.'], 422);
        }
        $admin = Admin::query()->findOrFail($id);
        $admin->tokens()->delete();
        $admin->delete();
        AuditLogger::log($request, 'admin.delete', 'admin', $id);

        return response()->json(['ok' => true]);
    }

    public function audit(Request $request): JsonResponse
    {
        $q = \App\Models\AdminAuditLog::query()->with('admin:id,name')->latest('id');
        if ($request->filled('admin_id')) {
            $q->where('admin_id', (int) $request->query('admin_id'));
        }
        if ($request->filled('action')) {
            $q->where('action', 'like', $request->query('action').'%');
        }
        $page = $q->paginate(40);
        $page->getCollection()->transform(fn ($l) => [
            'id' => $l->id, 'action' => $l->action, 'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id,
            'meta' => $l->meta, 'ip' => $l->ip, 'admin' => $l->admin?->name, 'created_at' => $l->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }
}
