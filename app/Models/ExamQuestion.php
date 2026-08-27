<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamQuestion extends Model
{
    protected $fillable = ['exam_id', 'question_text', 'type', 'options', 'correct_answer', 'score', 'position'];

    protected $hidden = ['correct_answer'];

    protected function casts(): array
    {
        return ['options' => 'array', 'score' => 'decimal:2'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function answerOptions(): array
    {
        $options = $this->options;
        if (! is_array($options) || $options === []) {
            return $this->type === 'true_false' ? ['true' => 'صح', 'false' => 'خطأ'] : [];
        }

        return array_is_list($options) ? array_combine(array_map('strval', $options), $options) : $options;
    }
}
