<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

/** Writes the moderation / config audit trail shown in the CRM. */
class AuditLogger
{
    /** @param array<string, mixed> $meta */
    public static function log(Request $request, string $action, ?string $subjectType = null, ?int $subjectId = null, array $meta = []): void
    {
        $admin = $request->user();

        AdminAuditLog::query()->create([
            'admin_id' => $admin instanceof Admin ? $admin->id : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta ?: null,
            'ip' => $request->ip(),
        ]);
    }
}
