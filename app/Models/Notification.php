<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification row. Written for every event we notify on, whether or
 * not a push actually goes out, so the in-app list stays complete.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'read_at'])]
class Notification extends Model
{
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<User, Notification> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
