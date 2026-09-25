<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Remote app config: free-plan limits, minimum app version, maintenance mode. */
class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'free_limits' => AppSetting::get('free_limits'),
            'app' => AppSetting::get('app'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'free_limits.likes_per_day' => ['required', 'integer', 'min:0', 'max:1000'],
            'free_limits.messages_per_match' => ['required', 'integer', 'min:0', 'max:1000'],
            'app.min_supported_version' => ['required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/'],
            'app.maintenance_mode' => ['required', 'boolean'],
            'app.maintenance_message' => ['nullable', 'string', 'max:255'],
        ]);

        AppSetting::put('free_limits', $data['free_limits']);
        AppSetting::put('app', $data['app']);
        AuditLogger::log($request, 'settings.update', 'settings', null, $data);

        return $this->show();
    }
}
