<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherReportDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'dispatch_id', 'report_id', 'report_revision', 'recipient_user_id', 'student_id', 'audience', 'snapshot', 'read_at', 'push_attempted_at'];

    protected function casts(): array
    {
        return ['report_revision' => 'integer', 'student_id' => 'integer', 'recipient_user_id' => 'integer',
            'snapshot' => 'array', 'read_at' => 'datetime', 'push_attempted_at' => 'datetime'];
    }
}
