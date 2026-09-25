<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'liveness_session_id', 'video_key', 'required_actions', 'status',
    'confidence', 'liveness_confidence', 'reference_key', 'failure_reason', 'reviewed_at',
])]
class Verification extends Model
{
    protected $casts = [
        'required_actions' => 'array',
        'confidence' => 'float',
        'liveness_confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, Verification> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
