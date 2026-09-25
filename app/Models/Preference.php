<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'min_age', 'max_age', 'max_distance_km',
    'show_me', 'verified_only', 'online_only', 'advanced',
])]
class Preference extends Model
{
    protected $casts = [
        'verified_only' => 'boolean',
        'online_only' => 'boolean',
        'advanced' => 'array',
        'min_age' => 'integer',
        'max_age' => 'integer',
        'max_distance_km' => 'integer',
    ];

    /** @return BelongsTo<User, Preference> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
