<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'url', 'storage_key', 'webp_url', 'webp_key', 'thumb_url', 'thumb_key',
    'veil_url', 'veil_key', 'position', 'is_primary',
    'face_status', 'face_similarity', 'face_reason', 'face_reference_id', 'face_checked_at',
])]
class Photo extends Model
{
    protected $casts = [
        'is_primary' => 'boolean',
        'position' => 'integer',
        'face_similarity' => 'float',
        'face_checked_at' => 'datetime',
    ];

    /** Signed original; the bucket is private. */
    public function signedUrl(): ?string
    {
        return MediaUrl::sign($this->storage_key ?: $this->url);
    }

    /** Signed thumbnail, falling back to the original. */
    public function signedThumb(): ?string
    {
        return MediaUrl::sign($this->thumb_key ?: $this->thumb_url) ?? $this->signedUrl();
    }

    /** @return BelongsTo<User, Photo> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
