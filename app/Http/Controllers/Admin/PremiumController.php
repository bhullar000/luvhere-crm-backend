<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The premium catalogue the app's upgrade popup and plans screen read: periods with their
 * discount, plans with a price per period, and the features each plan includes. Tables
 * belong to kive-backend; the whole catalogue is saved in one go so prices never half-update.
 */
class PremiumController extends Controller
{
    /** Keys kive-backend enforces (App\Support\Entitlements); any other key is display-only. */
    public const ENFORCED = ['unlimited_likes', 'see_likes', 'see_matches', 'unlimited_match_chat', 'anon_chat'];

    /** Icons the app can draw for a feature row. */
    public const ICONS = ['infinity', 'eye', 'people', 'chat', 'mask', 'heart', 'star', 'zap', 'crown', 'shield', 'sparkles', 'lock', 'globe', 'plane'];

    public function show(): JsonResponse
    {
        $periods = DB::table('premium_periods')->orderBy('sort')->get();
        $features = DB::table('premium_features')->orderBy('sort')->get();
        $prices = DB::table('premium_plan_prices')->get()->groupBy('plan_id');
        $values = DB::table('premium_plan_features')->get()->groupBy('plan_id');
        $periodCode = $periods->pluck('code', 'id');
        $featureKey = $features->pluck('key', 'id');

        $plans = DB::table('premium_plans')->orderBy('sort')->get()->map(fn ($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'is_popular' => (bool) $p->is_popular,
            'is_active' => (bool) $p->is_active,
            'sort' => $p->sort,
            'subscribers' => DB::table('users')->where('premium_plan_id', $p->id)->where('premium_until', '>', now())->count(),
            'prices' => (object) collect($prices[$p->id] ?? [])->mapWithKeys(fn ($r) => [$periodCode[$r->period_id] => $r->price])->all(),
            'features' => (object) collect($values[$p->id] ?? [])->mapWithKeys(fn ($r) => [$featureKey[$r->feature_id] => $r->value])->all(),
        ]);

        return response()->json([
            'periods' => $periods->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'label' => $p->label, 'price_suffix' => $p->price_suffix,
                'months' => $p->months, 'discount_percent' => $p->discount_percent, 'is_active' => (bool) $p->is_active, 'sort' => $p->sort,
            ]),
            'features' => $features->map(fn ($f) => [
                'id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'paywall_label' => $f->paywall_label, 'icon' => $f->icon,
                'in_paywall' => (bool) $f->in_paywall, 'is_active' => (bool) $f->is_active, 'sort' => $f->sort,
                'enforced' => in_array($f->key, self::ENFORCED, true),
            ]),
            'plans' => $plans,
            'icons' => self::ICONS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $code = ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'];
        $data = $request->validate([
            'periods' => ['required', 'array', 'min:1'],
            'periods.*.id' => ['nullable', 'integer'],
            'periods.*.code' => $code,
            'periods.*.label' => ['required', 'string', 'max:40'],
            'periods.*.price_suffix' => ['required', 'string', 'max:40'],
            'periods.*.months' => ['required', 'integer', 'min:1', 'max:60'],
            'periods.*.discount_percent' => ['required', 'integer', 'min:0', 'max:95'],
            'periods.*.is_active' => ['required', 'boolean'],
            'features' => ['required', 'array'],
            'features.*.id' => ['nullable', 'integer'],
            'features.*.key' => $code,
            'features.*.label' => ['required', 'string', 'max:80'],
            'features.*.paywall_label' => ['nullable', 'string', 'max:80'],
            'features.*.icon' => ['required', Rule::in(self::ICONS)],
            'features.*.in_paywall' => ['required', 'boolean'],
            'features.*.is_active' => ['required', 'boolean'],
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.id' => ['nullable', 'integer'],
            'plans.*.code' => $code,
            'plans.*.name' => ['required', 'string', 'max:60'],
            'plans.*.is_popular' => ['required', 'boolean'],
            'plans.*.is_active' => ['required', 'boolean'],
            'plans.*.prices' => ['present', 'array'],
            'plans.*.prices.*' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plans.*.features' => ['present', 'array'],
            'plans.*.features.*' => ['required', 'string', 'max:40'],
        ]);

        foreach (['periods' => 'code', 'features' => 'key', 'plans' => 'code'] as $list => $field) {
            $codes = array_column($data[$list], $field);
            if (count($codes) !== count(array_unique($codes))) {
                throw ValidationException::withMessages([$list => "Each {$field} must be unique."]);
            }
        }

        $before = $this->show()->getData(true);

        DB::transaction(function () use ($data) {
            $now = now();
            $periodIds = $this->upsert('premium_periods', $data['periods'], 'code', $now,
                fn ($p) => array_intersect_key($p, array_flip(['code', 'label', 'price_suffix', 'months', 'discount_percent', 'is_active'])));
            $featureIds = $this->upsert('premium_features', $data['features'], 'key', $now,
                fn ($f) => array_intersect_key($f, array_flip(['key', 'label', 'paywall_label', 'icon', 'in_paywall', 'is_active'])));
            $planIds = $this->upsert('premium_plans', $data['plans'], 'code', $now,
                fn ($p) => array_intersect_key($p, array_flip(['code', 'name', 'is_popular', 'is_active'])));

            foreach ($data['plans'] as $plan) {
                $planId = $planIds[$plan['code']];
                DB::table('premium_plan_prices')->where('plan_id', $planId)->delete();
                foreach ($plan['prices'] as $period => $price) {
                    if ($price !== null && isset($periodIds[$period])) {
                        DB::table('premium_plan_prices')->insert(['plan_id' => $planId, 'period_id' => $periodIds[$period], 'price' => $price, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
                DB::table('premium_plan_features')->where('plan_id', $planId)->delete();
                foreach ($plan['features'] as $key => $value) {
                    if (isset($featureIds[$key])) {
                        DB::table('premium_plan_features')->insert(['plan_id' => $planId, 'feature_id' => $featureIds[$key], 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        });

        AuditLogger::log($request, 'premium.update', 'settings', null, ['before' => $before, 'after' => $data]);

        return $this->show();
    }

    /**
     * Insert or update rows in list order (that order is their sort); rows missing from the
     * list are left alone, since a plan may still have subscribers. Retire one with is_active.
     *
     * @return array<string, int> code => id
     */
    private function upsert(string $table, array $rows, string $field, $now, callable $pick): array
    {
        $ids = [];
        foreach (array_values($rows) as $i => $row) {
            $values = [...$pick($row), 'sort' => $i + 1, 'updated_at' => $now];
            $id = $row['id'] ?? DB::table($table)->where($field, $row[$field])->value('id');
            if ($id) {
                DB::table($table)->where('id', $id)->update($values);
            } else {
                $id = DB::table($table)->insertGetId([...$values, 'created_at' => $now]);
            }
            $ids[$row[$field]] = (int) $id;
        }

        return $ids;
    }
}
