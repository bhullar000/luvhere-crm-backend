<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * In-app analytics: the events the mobile app posts to kive-backend's
 * `/analytics/track` (table `analytics_events`, owned by kive-backend).
 */
class AppEventsController extends Controller
{
    /** Steps of the sign-up funnel, in order, by event name. */
    private const FUNNEL = [
        'app_open' => 'Opened the app',
        'otp_requested' => 'Asked for a code',
        'signup' => 'Signed up',
        'profile_completed' => 'Finished profile',
        'face_verification' => 'Face check',
        'photo_added' => 'Added a photo',
        'swipe' => 'Swiped',
        'match' => 'Matched',
        'message_sent' => 'Sent a message',
    ];

    public function summary(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();
        $events = fn () => DB::table('analytics_events')->where('created_at', '>=', $from);

        $total = $events()->count();
        $sessions = $events()->whereNotNull('session_id')->distinct()->count('session_id');
        $users = $events()->whereNotNull('user_id')->distinct()->count('user_id');

        // Devices (sessions) per funnel step — a signed-out visitor has no user id yet.
        $funnel = [];
        foreach (self::FUNNEL as $name => $label) {
            $funnel[] = [
                'label' => $label,
                'value' => $events()->where('name', $name)->whereNotNull('session_id')->distinct()->count('session_id'),
            ];
        }

        $screenExpr = $this->jsonText('props', 'screen');

        return response()->json([
            'days' => $days,
            'totals' => [
                'events' => $total,
                'sessions' => $sessions,
                'users' => $users,
                'events_per_session' => $sessions ? round($total / $sessions, 1) : 0,
            ],
            'daily_users' => $this->dailyDistinct($from, $days, 'user_id'),
            'daily_sessions' => $this->dailyDistinct($from, $days, 'session_id'),
            'top_events' => $events()->selectRaw('name as label, COUNT(*) as value')
                ->groupBy('name')->orderByDesc('value')->limit(15)->get(),
            'top_screens' => $events()->where('name', 'screen_view')
                ->selectRaw("$screenExpr as label, COUNT(*) as value")
                ->groupBy('label')->orderByDesc('value')->limit(12)->get(),
            'paywall_reasons' => $events()->where('name', 'paywall_shown')
                ->selectRaw($this->jsonText('props', 'reason').' as label, COUNT(*) as value')
                ->groupBy('label')->orderByDesc('value')->get(),
            'platforms' => $events()->where('name', 'app_open')
                ->selectRaw("COALESCE(platform,'unknown') as label, COUNT(DISTINCT session_id) as value")
                ->groupBy('platform')->get(),
            'versions' => $events()->where('name', 'app_open')
                ->selectRaw("COALESCE(app_version,'unknown') as label, COUNT(DISTINCT session_id) as value")
                ->groupBy('app_version')->orderByDesc('value')->limit(6)->get(),
            'funnel' => $funnel,
        ]);
    }

    /** The raw feed, newest first; filter by event name or user. */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('analytics_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->select('e.id', 'e.name', 'e.props', 'e.user_id', 'u.name as user_name', 'e.session_id',
                'e.platform', 'e.app_version', 'e.occurred_at', 'e.created_at');

        if ($request->filled('name')) {
            $q->where('e.name', $request->query('name'));
        }
        if ($request->filled('user_id')) {
            $q->where('e.user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('session_id')) {
            $q->where('e.session_id', $request->query('session_id'));
        }

        $page = $q->orderByDesc('e.created_at')->orderByDesc('e.id')->paginate(min(100, max(10, (int) $request->query('per_page', 50))));
        $page->getCollection()->transform(fn ($e) => [
            ...(array) $e,
            'props' => $e->props ? json_decode($e->props, true) : null,
        ]);

        return response()->json($page);
    }

    /** Every event name seen, for the feed's filter. */
    public function names(): JsonResponse
    {
        return response()->json(DB::table('analytics_events')->distinct()->orderBy('name')->pluck('name'));
    }

    /** @return list<array{date:string, value:int}> */
    private function dailyDistinct(\DateTimeInterface $from, int $days, string $column): array
    {
        $rows = DB::table('analytics_events')->where('created_at', '>=', $from)->whereNotNull($column)
            ->selectRaw("DATE(created_at) as d, COUNT(DISTINCT $column) as c")->groupBy('d')->pluck('c', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = now()->subDays($days - 1 - $i)->toDateString();
            $out[] = ['date' => $d, 'value' => (int) ($rows[$d] ?? 0)];
        }

        return $out;
    }

    /** A JSON string field as SQL text, on MySQL and on SQLite (tests). */
    private function jsonText(string $column, string $key): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "COALESCE(json_extract($column, '$.$key'), 'unknown')"
            : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column, '$.$key')), 'unknown')";
    }
}
