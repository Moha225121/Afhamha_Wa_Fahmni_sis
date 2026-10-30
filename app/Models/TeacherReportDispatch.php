<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherReportDispatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'teacher_id', 'report_id', 'revision', 'fingerprint', 'response'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'response' => 'array'];
    }
}
