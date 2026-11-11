<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Remote key/value config editable from the CRM (free limits, min version, maintenance…). */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'free_limits' => ['likes_per_day' => 5, 'messages_per_match' => 5, 'anon_chats_per_day' => 2, 'anon_messages_per_chat' => 5],
        'app' => [
            'min_supported_version' => '1.0.0',
            'maintenance_mode' => false,
            'maintenance_message' => 'Kive is getting a tune-up. Back shortly.',
            // On: a build older than the store's latest version can't be used until it updates.
            'force_update' => false,
            'latest_version_ios' => '1.0.0',
            'latest_version_android' => '1.0.0',
            'ios_store_url' => '',
            'android_store_url' => 'https://play.google.com/store/apps/details?id=com.luvhere.app',
            'update_message' => null,
        ],
        // test | live per integration (IntegrationController); a missing key follows kive-backend's .env.
        'integrations' => [],
    ];

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $default = self::DEFAULTS[$key] ?? [];

        try {
            $row = self::query()->find($key);
        } catch (\Throwable) {
            return $default; // table not migrated yet
        }

        return array_merge($default, is_array($row?->value) ? $row->value : []);
    }

    /** @param array<string, mixed> $value */
    public static function put(string $key, array $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function freeLikesPerDay(): int
    {
        return (int) self::get('free_limits')['likes_per_day'];
    }
}
