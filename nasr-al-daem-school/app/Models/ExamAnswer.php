<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    protected $fillable = ['exam_attempt_id', 'exam_question_id', 'question_text_snapshot', 'question_type_snapshot', 'options_snapshot', 'correct_answer_snapshot', 'max_score', 'answer', 'is_correct', 'awarded_score', 'answered_at'];

    protected $hidden = ['correct_answer_snapshot'];

    protected function casts(): array
    {
        return ['options_snapshot' => 'array', 'is_correct' => 'boolean', 'max_score' => 'decimal:2', 'awarded_score' => 'decimal:2', 'answered_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    public function answerOptions(): array
    {
        $options = $this->options_snapshot;
        if (! is_array($options) || $options === []) {
            return $this->question_type_snapshot === 'true_false' ? ['true' => 'صح', 'false' => 'خطأ'] : [];
        }

        return array_is_list($options) ? array_combine(array_map('strval', $options), $options) : $options;
    }
}
