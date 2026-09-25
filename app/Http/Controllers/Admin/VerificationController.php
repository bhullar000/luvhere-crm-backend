<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Face-verification attempts: review outcomes and override manually. */
class VerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Verification::query()->with(['user' => fn ($u) => $u->select('id', 'name', 'email', 'phone', 'is_verified', 'verification_status')
            ->with(['photos' => fn ($p) => $p->orderBy('position')])]);

        $status = $request->query('status', 'failed');
        if ($status === 'queue') {
            $q->whereIn('status', ['pending', 'processing', 'failed']);
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }

        $page = $q->latest('id')->paginate(min(50, max(5, (int) $request->query('per_page', 20))));
        $page->getCollection()->transform(fn (Verification $v) => [
            'id' => $v->id, 'status' => $v->status, 'confidence' => $v->confidence,
            'liveness_confidence' => $v->liveness_confidence, 'failure_reason' => $v->failure_reason,
            'created_at' => $v->created_at?->toIso8601String(), 'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'user' => $v->user ? [
                'id' => $v->user->id, 'name' => $v->user->name, 'email' => $v->user->email, 'phone' => $v->user->phone,
                'is_verified' => $v->user->is_verified,
                'photos' => $v->user->photos->take(4)->map(fn ($p) => [
                    'id' => $p->id, 'thumb_url' => $p->thumb_url ?: $p->url,
                    'face_status' => $p->face_status, 'face_similarity' => $p->face_similarity,
                ])->values(),
            ] : null,
        ]);

        return response()->json($page);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $v = Verification::query()->findOrFail($id);
        $approve = $data['decision'] === 'approve';

        DB::transaction(function () use ($v, $data, $approve) {
            $v->forceFill([
                'status' => $approve ? 'verified' : 'failed',
                'failure_reason' => $approve ? null : ($data['reason'] ?? 'Rejected by reviewer'),
                'reviewed_at' => now(),
            ])->save();

            DB::table('users')->where('id', $v->user_id)->update([
                'is_verified' => $approve,
                'verification_status' => $approve ? 'verified' : 'failed',
            ]);
        });

        AuditLogger::log($request, 'verification.'.$data['decision'], 'user', $v->user_id, ['verification_id' => $id, 'reason' => $data['reason'] ?? null]);

        return response()->json(['ok' => true]);
    }
}
