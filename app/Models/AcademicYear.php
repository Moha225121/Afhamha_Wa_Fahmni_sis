<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'starts_at', 'ends_at', 'is_current'])] class AcademicYear extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'is_current' => 'boolean'];
    }
}
