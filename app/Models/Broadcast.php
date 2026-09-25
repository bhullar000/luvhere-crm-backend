<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'title', 'body', 'audience', 'recipient_count', 'status'])]
class Broadcast extends Model
{
    protected $casts = ['audience' => 'array', 'recipient_count' => 'integer'];

    /** @return BelongsTo<Admin, Broadcast> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
