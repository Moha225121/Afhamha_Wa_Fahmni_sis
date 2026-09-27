<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lesson_id', 'title', 'disk', 'file_path', 'original_name', 'mime_type', 'size'])]
class LessonAttachment extends Model
{
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
