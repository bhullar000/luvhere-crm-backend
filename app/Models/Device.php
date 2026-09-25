<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A device registered for push. One row per (user, push token). */
#[Fillable(['user_id', 'push_token', 'platform', 'device_name', 'app_version', 'last_seen_at'])]
class Device extends Model
{
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<User, Device> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
