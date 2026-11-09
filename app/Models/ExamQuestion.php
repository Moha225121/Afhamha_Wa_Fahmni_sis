<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ExamQuestion extends Model
{
    protected $fillable = ['exam_id', 'question_text', 'type', 'options', 'correct_answer', 'score', 'position'];

    protected $hidden = ['correct_answer'];

    protected function casts(): array
    {
        return ['options' => 'array', 'score' => 'decimal:2'];
    }

    public function getTypeAttribute(string $value): string
    {
        return $value === 'mcq' ? 'multiple_choice' : $value;
    }

    public function getOptionsAttribute(?string $value): ?array
    {
        $options = $value === null ? null : json_decode($value, true);
        if (is_array($options) && $options !== []) {
            return $options;
        }

        // Teacher forms store relational choices; retain the historical JSON representation too.
        $choices = DB::table('exam_choices')->where('exam_question_id', $this->id)->orderBy('order')->pluck('text', 'id')->all();

        return $choices ?: (is_array($options) ? $options : null);
    }

    public function getCorrectAnswerAttribute(?string $value): ?string
    {
        if ($value !== null) {
            return $value;
        }
        $correct = DB::table('exam_choices')->where('exam_question_id', $this->id)->where('is_correct', true)->pluck('id');

        return $correct->count() === 1 ? (string) $correct->first() : null;
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
