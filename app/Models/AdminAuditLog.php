<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip'])]
class AdminAuditLog extends Model
{
    protected $casts = ['meta' => 'array'];

    /** @return BelongsTo<Admin, AdminAuditLog> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
