<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** User directory, profile detail and every account-level moderation action. */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::query()->withTrashed()->with(['city:id,name', 'photos' => fn ($p) => $p->orderBy('position')]);

        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%");
                if (ctype_digit($s)) {
                    $w->orWhere('id', (int) $s);
                }
            });
        }

        foreach (['status', 'gender', 'city_id', 'verification_status'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        foreach (['is_verified', 'is_premium'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, filter_var($request->query($f), FILTER_VALIDATE_BOOLEAN));
            }
        }
        if ($request->filled('onboarded')) {
            filter_var($request->query('onboarded'), FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('onboarding_completed_at')
                : $q->whereNull('onboarding_completed_at');
        }
        if ($request->query('deleted') === '1') {
            $q->onlyTrashed();
        } elseif ($request->query('deleted') !== 'all') {
            $q->whereNull('deleted_at');
        }
        if ($request->filled('inactive_days')) {
            $q->where(fn ($w) => $w->where('last_active_at', '<', now()->subDays((int) $request->query('inactive_days')))
                ->orWhereNull('last_active_at'));
        }

        $sort = in_array($request->query('sort'), ['id', 'name', 'created_at', 'last_active_at'], true) ? $request->query('sort') : 'id';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $page = $q->orderBy($sort, $dir)->paginate(min(100, max(5, (int) $request->query('per_page', 25))));
        $page->getCollection()->transform(fn (User $u) => $this->summary($u));

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $u = User::query()->withTrashed()->with([
            'city', 'photos' => fn ($p) => $p->orderBy('position'), 'verifications' => fn ($v) => $v->latest()->limit(10),
            'preference', 'interests:id,name,emoji', 'languages:id,name', 'lifestyleOptions',
        ])->findOrFail($id);

        $stats = [
            'likes_given' => DB::table('likes')->where('user_id', $id)->whereIn('action', ['like', 'super_like'])->count(),
            'passes' => DB::table('likes')->where('user_id', $id)->where('action', 'pass')->count(),
            'likes_received' => DB::table('likes')->where('target_user_id', $id)->whereIn('action', ['like', 'super_like'])->count(),
            'matches' => DB::table('matches')->where('status', 'active')->where(fn ($w) => $w->where('user_one_id', $id)->orWhere('user_two_id', $id))->count(),
            'messages_sent' => DB::table('messages')->where('sender_id', $id)->count(),
            'anon_messages_sent' => DB::table('anon_messages')->where('sender_id', $id)->count(),
            'anon_conversations' => DB::table('anon_conversations')->where(fn ($w) => $w->where('user_one_id', $id)->orWhere('user_two_id', $id))->count(),
            'reports_against' => DB::table('reports')->where('reported_user_id', $id)->count(),
            'reports_made' => DB::table('reports')->where('reporter_id', $id)->count(),
            'blocked_by' => DB::table('blocks')->where('blocked_id', $id)->count(),
            'blocking' => DB::table('blocks')->where('blocker_id', $id)->count(),
        ];

        return response()->json([
            'user' => array_merge($this->summary($u), [
                'bio' => $u->bio, 'occupation' => $u->occupation, 'education' => $u->education,
                'height_cm' => $u->height_cm, 'relationship_goal' => $u->relationship_goal,
                'interested_in' => $u->interested_in, 'birthdate' => $u->birthdate?->toDateString(),
                'latitude' => $u->latitude, 'longitude' => $u->longitude,
                'incognito' => $u->incognito, 'travel_mode' => $u->travel_mode,
                'boost_until' => $u->boost_until?->toIso8601String(),
                'premium_until' => $u->premium_until?->toIso8601String(),
                'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                'phone_verified_at' => $u->phone_verified_at?->toIso8601String(),
                'google' => (bool) $u->google_id, 'apple' => (bool) $u->apple_id,
                'suspended_until' => $u->suspended_until?->toIso8601String(),
                'status_reason' => $u->status_reason, 'admin_notes' => $u->admin_notes,
                'onboarding_completed_at' => $u->onboarding_completed_at?->toIso8601String(),
                'photos' => $u->photos->map(fn ($p) => $this->photo($p))->values(),
                'interests' => $u->interests, 'languages' => $u->languages,
                'lifestyle' => $u->lifestyleOptions->map(fn ($l) => ['group' => $l->group, 'label' => $l->label]),
                'preference' => $u->preference,
                'verifications' => $u->verifications->map(fn ($v) => [
                    'id' => $v->id, 'status' => $v->status, 'confidence' => $v->confidence,
                    'liveness_confidence' => $v->liveness_confidence, 'failure_reason' => $v->failure_reason,
                    'created_at' => $v->created_at?->toIso8601String(),
                ]),
            ]),
            'stats' => $stats,
            'devices' => DB::table('devices')->where('user_id', $id)->get(['platform', 'device_name', 'app_version', 'last_seen_at']),
            'reports' => DB::table('reports')->where('reported_user_id', $id)->latest()->limit(10)->get(['id', 'reason', 'status', 'created_at']),
            'notifications' => Notification::query()->where('user_id', $id)->latest()->limit(10)->get(['id', 'type', 'title', 'read_at', 'created_at']),
            'audit' => DB::table('admin_audit_logs')->leftJoin('admins', 'admins.id', '=', 'admin_audit_logs.admin_id')
                ->where('subject_type', 'user')->where('subject_id', $id)->latest('admin_audit_logs.id')->limit(15)
                ->get(['admin_audit_logs.action', 'admin_audit_logs.meta', 'admin_audit_logs.created_at', 'admins.name as admin']),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $u = User::query()->withTrashed()->findOrFail($id);
        $u->forceFill($data)->save();
        AuditLogger::log($request, 'user.update', 'user', $id, ['fields' => array_keys($data)]);

        return response()->json(['ok' => true]);
    }

    /** suspend | ban | reactivate | delete | restore | force_logout | verify | unverify | grant_premium | revoke_premium */
    public function action(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['suspend', 'ban', 'reactivate', 'delete', 'restore', 'force_logout', 'verify', 'unverify', 'grant_premium', 'revoke_premium'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $u = User::query()->withTrashed()->findOrFail($id);
        $reason = $data['reason'] ?? null;

        switch ($data['action']) {
            case 'suspend':
                $u->forceFill(['status' => 'suspended', 'status_reason' => $reason, 'suspended_until' => now()->addDays($data['days'] ?? 7)])->save();
                $u->tokens()->delete();
                break;
            case 'ban':
                $u->forceFill(['status' => 'banned', 'status_reason' => $reason, 'suspended_until' => null])->save();
                $u->tokens()->delete();
                break;
            case 'reactivate':
                $u->forceFill(['status' => 'active', 'status_reason' => null, 'suspended_until' => null])->save();
                break;
            case 'delete':
                $u->tokens()->delete();
                $u->delete();
                break;
            case 'restore':
                $u->restore();
                break;
            case 'force_logout':
                $u->tokens()->delete();
                break;
            case 'verify':
                $u->forceFill(['is_verified' => true, 'verification_status' => 'verified'])->save();
                break;
            case 'unverify':
                $u->forceFill(['is_verified' => false, 'verification_status' => 'unverified'])->save();
                break;
            case 'grant_premium':
                $base = $u->premium_until && $u->premium_until->isFuture() ? $u->premium_until : now();
                $u->forceFill(['is_premium' => true, 'premium_until' => $base->copy()->addDays($data['days'] ?? 30)])->save();
                break;
            case 'revoke_premium':
                $u->forceFill(['is_premium' => false, 'premium_until' => null])->save();
                break;
        }

        AuditLogger::log($request, 'user.'.$data['action'], 'user', $id, array_filter(['reason' => $reason, 'days' => $data['days'] ?? null]));

        return response()->json(['ok' => true]);
    }

    /** Send a single in-app + push notification to one user. */
    public function notify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:300']]);
        $u = User::query()->findOrFail($id);

        Notification::query()->create(['user_id' => $u->id, 'type' => 'system', 'title' => $data['title'], 'body' => $data['body'], 'data' => ['type' => 'system']]);
        SendPushNotification::dispatch($u->id, $data['title'], $data['body'], ['type' => 'system']);
        AuditLogger::log($request, 'user.notify', 'user', $id, ['title' => $data['title']]);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function summary(User $u): array
    {
        $photo = $u->relationLoaded('photos') ? $u->photos->first() : null;

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'gender' => $u->gender,
            'age' => $u->birthdate?->age,
            'city' => $u->city?->name,
            'status' => $u->status ?? 'active',
            'is_verified' => $u->is_verified, 'verification_status' => $u->verification_status,
            'is_premium' => $u->is_premium,
            'avatar' => $photo ? ($photo->thumb_url ?: $photo->url) : null,
            'onboarded' => (bool) $u->onboarding_completed_at,
            'last_active_at' => $u->last_active_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
            'deleted_at' => $u->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function photo($p): array
    {
        return [
            'id' => $p->id, 'url' => $p->url, 'thumb_url' => $p->thumb_url ?: $p->url,
            'is_primary' => $p->is_primary, 'position' => $p->position,
            'moderation_status' => $p->moderation_status,
            'face_status' => $p->face_status, 'face_similarity' => $p->face_similarity, 'face_reason' => $p->face_reason,
        ];
    }
}
