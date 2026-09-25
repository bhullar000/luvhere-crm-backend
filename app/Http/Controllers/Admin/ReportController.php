<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Report triage, plus the block list. */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Report::query()->with(['reporter:id,name,email,phone', 'reportedUser:id,name,email,phone,status']);

        $status = $request->query('status', 'open');
        if ($status === 'active') {
            $q->whereIn('status', ['open', 'reviewing']);
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($request->filled('reason')) {
            $q->where('reason', $request->query('reason'));
        }
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('details', 'like', "%{$s}%")
                ->orWhereHas('reportedUser', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")));
        }

        $page = $q->latest('id')->paginate(min(100, max(5, (int) $request->query('per_page', 25))));
        $counts = DB::table('reports')->selectRaw('reported_user_id, COUNT(*) c')->whereIn('reported_user_id', $page->getCollection()->pluck('reported_user_id'))
            ->groupBy('reported_user_id')->pluck('c', 'reported_user_id');

        $page->getCollection()->transform(fn (Report $r) => [
            'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details, 'status' => $r->status,
            'resolution_note' => $r->resolution_note, 'resolved_at' => $r->resolved_at,
            'created_at' => $r->created_at?->toIso8601String(),
            'reporter' => $r->reporter, 'reported' => $r->reportedUser,
            'reports_against_total' => (int) ($counts[$r->reported_user_id] ?? 1),
        ]);

        return response()->json($page);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,reviewing,actioned,dismissed'],
            'note' => ['nullable', 'string', 'max:1000'],
            'suspend_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'ban' => ['nullable', 'boolean'],
        ]);
        $report = Report::query()->findOrFail($id);
        $closing = in_array($data['status'], ['actioned', 'dismissed'], true);

        DB::table('reports')->where('id', $id)->update([
            'status' => $data['status'],
            'resolution_note' => $data['note'] ?? $report->resolution_note,
            'resolved_by' => $closing ? $request->user()->id : null,
            'resolved_at' => $closing ? now() : null,
            'updated_at' => now(),
        ]);

        // Optional one-click penalty on the reported account.
        if ($data['status'] === 'actioned' && ($data['ban'] ?? false)) {
            $u = User::query()->find($report->reported_user_id);
            $u?->forceFill(['status' => 'banned', 'status_reason' => "Report #{$id}: {$report->reason}"])->save();
            $u?->tokens()->delete();
        } elseif ($data['status'] === 'actioned' && ! empty($data['suspend_days'])) {
            $u = User::query()->find($report->reported_user_id);
            $u?->forceFill(['status' => 'suspended', 'status_reason' => "Report #{$id}: {$report->reason}", 'suspended_until' => now()->addDays($data['suspend_days'])])->save();
            $u?->tokens()->delete();
        }

        AuditLogger::log($request, 'report.'.$data['status'], 'report', $id, array_filter([
            'note' => $data['note'] ?? null, 'ban' => $data['ban'] ?? null, 'suspend_days' => $data['suspend_days'] ?? null,
        ]));

        return response()->json(['ok' => true]);
    }

    public function blocks(Request $request): JsonResponse
    {
        $q = DB::table('blocks')
            ->join('users as a', 'a.id', '=', 'blocks.blocker_id')
            ->join('users as b', 'b.id', '=', 'blocks.blocked_id')
            ->select('blocks.id', 'blocks.created_at', 'a.id as blocker_id', 'a.name as blocker_name', 'b.id as blocked_id', 'b.name as blocked_name');

        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('a.name', 'like', "%{$s}%")->orWhere('b.name', 'like', "%{$s}%"));
        }
        if ($request->filled('user_id')) {
            $uid = (int) $request->query('user_id');
            $q->where(fn ($w) => $w->where('blocks.blocker_id', $uid)->orWhere('blocks.blocked_id', $uid));
        }

        // Users blocked by many people are the strongest abuse signal.
        $top = DB::table('blocks')->join('users', 'users.id', '=', 'blocks.blocked_id')
            ->selectRaw('users.id, users.name, users.status, COUNT(*) as blocked_by')
            ->groupBy('users.id', 'users.name', 'users.status')->orderByDesc('blocked_by')->limit(5)->get();

        $page = $q->orderByDesc('blocks.id')->paginate(25);

        return response()->json(array_merge($page->toArray(), ['most_blocked' => $top]));
    }

    public function removeBlock(Request $request, int $id): JsonResponse
    {
        DB::table('blocks')->where('id', $id)->delete();
        AuditLogger::log($request, 'block.remove', 'block', $id);

        return response()->json(['ok' => true]);
    }
}
