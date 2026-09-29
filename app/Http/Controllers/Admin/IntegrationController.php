<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Test or live per integration, stored in app_settings `integrations` and read by kive-backend
 * (App\Support\IntegrationModes) on every request. A mode never saved is null: the app follows its .env.
 */
class IntegrationController extends Controller
{
    /** The integrations kive-backend switches, in the order a new user meets them. */
    public const KEYS = ['sms', 'verification', 'inappropriate', 'moderation'];

    public function show(): JsonResponse
    {
        $stored = AppSetting::get('integrations');

        return response()->json([
            'integrations' => array_combine(self::KEYS, array_map(
                fn (string $key) => in_array($stored[$key] ?? null, ['test', 'live'], true) ? $stored[$key] : null,
                self::KEYS,
            )),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'integrations' => ['required', 'array'],
            'integrations.*' => ['nullable', Rule::in(['test', 'live'])],
        ]);

        $modes = array_intersect_key($data['integrations'], array_flip(self::KEYS));
        $before = AppSetting::get('integrations');

        AppSetting::put('integrations', array_filter([...$before, ...$modes], fn ($mode) => $mode !== null));
        AuditLogger::log($request, 'integrations.update', 'settings', null, ['before' => $before, 'after' => $modes]);

        return $this->show();
    }
}
