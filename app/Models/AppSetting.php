<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Remote key/value config editable from the CRM (free limits, min version, maintenance…). */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'free_limits' => ['likes_per_day' => 5, 'messages_per_match' => 5],
        'app' => [
            'min_supported_version' => '1.0.0',
            'maintenance_mode' => false,
            'maintenance_message' => 'Kive is getting a tune-up. Back shortly.',
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
