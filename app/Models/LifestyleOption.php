<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group', 'value', 'label'])]
class LifestyleOption extends Model
{
    protected $table = 'lifestyle_options';
}
