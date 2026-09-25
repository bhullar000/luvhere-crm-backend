<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Models\Broadcast;
use App\Models\Notification;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Segment + send a system notification (in-app row + push) to many users. */
class BroadcastController extends Controller
{
    public function index(): JsonResponse
    {
        $page = Broadcast::query()->with('admin:id,name')->latest('id')->paginate(20);
        $page->getCollection()->transform(fn (Broadcast $b) => [
            'id' => $b->id, 'title' => $b->title, 'body' => $b->body, 'audience' => $b->audience,
            'recipient_count' => $b->recipient_count, 'status' => $b->status,
            'admin' => $b->admin?->name, 'created_at' => $b->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->audience($this->audienceInput($request))->count()]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:300'],
        ]);
        $audience = $this->audienceInput($request);
        $count = 0;

        $this->audience($audience)->select('users.id')->chunkById(500, function ($users) use ($data, &$count) {
            foreach ($users as $u) {
                Notification::query()->create(['user_id' => $u->id, 'type' => 'system', 'title' => $data['title'], 'body' => $data['body'], 'data' => ['type' => 'system']]);
                SendPushNotification::dispatch($u->id, $data['title'], $data['body'], ['type' => 'system']);
                $count++;
            }
        }, 'users.id', 'id');

        $b = Broadcast::query()->create([
            'admin_id' => $request->user()->id, 'title' => $data['title'], 'body' => $data['body'],
            'audience' => $audience, 'recipient_count' => $count, 'status' => 'sent',
        ]);
        AuditLogger::log($request, 'broadcast.send', 'broadcast', $b->id, ['recipients' => $count]);

        return response()->json(['ok' => true, 'recipients' => $count], 201);
    }

    /** @return array<string, mixed> */
    private function audienceInput(Request $request): array
    {
        return $request->validate([
            'audience' => ['nullable', 'array'],
            'audience.gender' => ['nullable', 'in:male,female,non_binary,other'],
            'audience.verified' => ['nullable', 'in:yes,no'],
            'audience.premium' => ['nullable', 'in:yes,no'],
            'audience.city_id' => ['nullable', 'integer'],
            'audience.inactive_days' => ['nullable', 'integer', 'min:1'],
            'audience.active_within_days' => ['nullable', 'integer', 'min:1'],
            'audience.onboarded' => ['nullable', 'in:yes,no'],
        ])['audience'] ?? [];
    }

    /** @param array<string, mixed> $a @return Builder<User> */
    private function audience(array $a): Builder
    {
        $q = User::query()->where('status', 'active')->whereNull('deleted_at');

        if (! empty($a['gender'])) {
            $q->where('gender', $a['gender']);
        }
        if (! empty($a['verified'])) {
            $q->where('is_verified', $a['verified'] === 'yes');
        }
        if (! empty($a['premium'])) {
            $q->where('is_premium', $a['premium'] === 'yes');
        }
        if (! empty($a['city_id'])) {
            $q->where('city_id', $a['city_id']);
        }
        if (! empty($a['onboarded'])) {
            $a['onboarded'] === 'yes' ? $q->whereNotNull('onboarding_completed_at') : $q->whereNull('onboarding_completed_at');
        }
        if (! empty($a['inactive_days'])) {
            $q->where(fn ($w) => $w->where('last_active_at', '<', now()->subDays((int) $a['inactive_days']))->orWhereNull('last_active_at'));
        }
        if (! empty($a['active_within_days'])) {
            $q->where('last_active_at', '>=', now()->subDays((int) $a['active_within_days']));
        }

        return $q;
    }
}
