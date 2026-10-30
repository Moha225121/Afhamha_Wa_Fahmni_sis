<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherReport extends Model
{
    protected $fillable = ['teacher_id', 'classroom_id', 'title', 'config', 'data', 'revision'];

    protected function casts(): array
    {
        return ['config' => 'array', 'data' => 'array', 'revision' => 'integer'];
    }
}
