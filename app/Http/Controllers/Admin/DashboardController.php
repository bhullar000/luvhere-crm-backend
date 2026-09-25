<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Overview KPIs, work queues and the analytics charts. */
class DashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $users = DB::table('users')->whereNull('deleted_at');
        $day = now()->subDay();

        $total = (clone $users)->count();

        return response()->json([
            'kpis' => [
                'total_users' => $total,
                'new_today' => (clone $users)->where('created_at', '>=', now()->startOfDay())->count(),
                'new_7d' => (clone $users)->where('created_at', '>=', now()->subDays(7))->count(),
                'dau' => (clone $users)->where('last_active_at', '>=', $day)->count(),
                'mau' => (clone $users)->where('last_active_at', '>=', now()->subDays(30))->count(),
                'onboarded' => (clone $users)->whereNotNull('onboarding_completed_at')->count(),
                'verified' => (clone $users)->where('is_verified', true)->count(),
                'premium' => (clone $users)->where('is_premium', true)->count(),
                'suspended' => (clone $users)->where('status', 'suspended')->count(),
                'banned' => (clone $users)->where('status', 'banned')->count(),
                'matches' => DB::table('matches')->where('status', 'active')->count(),
                'matches_7d' => DB::table('matches')->where('matched_at', '>=', now()->subDays(7))->count(),
            ],
            'queues' => [
                'open_reports' => DB::table('reports')->whereIn('status', ['open', 'reviewing'])->count(),
                'photos_pending' => DB::table('photos')->where('moderation_status', 'pending')->count(),
                'verifications_pending' => DB::table('verifications')->whereIn('status', ['pending', 'processing'])->count(),
                'photos_face_rejected' => DB::table('photos')->where('face_status', 'rejected')->count(),
            ],
            'funnel' => [
                ['label' => 'Signed up', 'value' => $total],
                ['label' => 'Onboarded', 'value' => (clone $users)->whereNotNull('onboarding_completed_at')->count()],
                ['label' => 'Has photo', 'value' => DB::table('photos')->distinct()->count('user_id')],
                ['label' => 'Verified', 'value' => (clone $users)->where('is_verified', true)->count()],
                ['label' => 'Premium', 'value' => (clone $users)->where('is_premium', true)->count()],
            ],
            'signups' => $this->daily('users', 'created_at', 14, true),
            'recent_users' => DB::table('users')->whereNull('deleted_at')->latest('id')->limit(6)
                ->get(['id', 'name', 'email', 'phone', 'created_at', 'is_verified', 'status']),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $days = min(90, max(7, (int) $request->query('days', 30)));
        $users = fn () => DB::table('users')->whereNull('deleted_at');

        $ageExpr = DB::getDriverName() === 'sqlite'
            ? "CAST((julianday('now') - julianday(birthdate)) / 365.25 AS INTEGER)"
            : 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE())';

        $ages = $users()->whereNotNull('birthdate')->selectRaw("$ageExpr as age")->pluck('age');
        $buckets = ['18-21' => 0, '22-25' => 0, '26-30' => 0, '31-35' => 0, '36-45' => 0, '46+' => 0];
        foreach ($ages as $a) {
            $key = match (true) {
                $a <= 21 => '18-21', $a <= 25 => '22-25', $a <= 30 => '26-30',
                $a <= 35 => '31-35', $a <= 45 => '36-45', default => '46+',
            };
            $buckets[$key]++;
        }

        return response()->json([
            'days' => $days,
            'signups' => $this->daily('users', 'created_at', $days, true),
            'matches' => $this->daily('matches', 'matched_at', $days),
            'likes' => $this->daily('likes', 'created_at', $days),
            'messages' => $this->mergeDaily(
                $this->daily('messages', 'created_at', $days),
                $this->daily('anon_messages', 'created_at', $days),
            ),
            'active' => $this->daily('users', 'last_active_at', $days, true),
            'gender' => $users()->selectRaw("COALESCE(gender,'unknown') as label, COUNT(*) as value")->groupBy('gender')->get(),
            'age' => collect($buckets)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values(),
            'goals' => $users()->selectRaw("COALESCE(relationship_goal,'unset') as label, COUNT(*) as value")->groupBy('relationship_goal')->get(),
            'cities' => DB::table('users')->whereNull('users.deleted_at')
                ->join('cities', 'cities.id', '=', 'users.city_id')
                ->selectRaw('cities.name as label, COUNT(*) as value')
                ->groupBy('cities.id', 'cities.name')->orderByDesc('value')->limit(8)->get(),
            'platforms' => DB::table('devices')->selectRaw("COALESCE(platform,'unknown') as label, COUNT(*) as value")
                ->groupBy('platform')->get(),
            'top_interests' => DB::table('user_interests')
                ->join('interests', 'interests.id', '=', 'user_interests.interest_id')
                ->selectRaw('interests.name as label, COUNT(*) as value')
                ->groupBy('interests.id', 'interests.name')->orderByDesc('value')->limit(10)->get(),
            'match_rate' => [
                'likes' => DB::table('likes')->whereIn('action', ['like', 'super_like'])->count(),
                'matches' => DB::table('matches')->count(),
                'chats_started' => DB::table('chats')->where('message_count', '>', 0)->count()
                    + DB::table('anon_conversations')->where('message_count', '>', 0)->count(),
                'reveals' => DB::table('chats')->where('is_revealed', true)->count(),
            ],
        ]);
    }

    /**
     * Per-day counts for the last $days days, zero-filled.
     *
     * @return list<array{date:string, value:int}>
     */
    private function daily(string $table, string $column, int $days, bool $excludeDeleted = false): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $q = DB::table($table)->where($column, '>=', $from);
        if ($excludeDeleted) {
            $q->whereNull('deleted_at');
        }
        $rows = $q->selectRaw("DATE($column) as d, COUNT(*) as c")->groupBy('d')->pluck('c', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $d, 'value' => (int) ($rows[$d] ?? 0)];
        }

        return $out;
    }

    /** @param list<array{date:string,value:int}> $a @param list<array{date:string,value:int}> $b */
    private function mergeDaily(array $a, array $b): array
    {
        foreach ($a as $i => $row) {
            $a[$i]['value'] += $b[$i]['value'] ?? 0;
        }

        return $a;
    }
}
