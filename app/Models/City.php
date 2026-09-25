<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'state', 'country_code', 'latitude', 'longitude'])]
class City extends Model
{
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
