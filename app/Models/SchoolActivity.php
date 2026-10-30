<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolActivity extends Model
{
    protected $fillable = ['title', 'description', 'classroom_id', 'activity_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['activity_date' => 'date'];
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
