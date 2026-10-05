<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'category', 'subject_id', 'classroom_id', 'file_path', 'disk', 'is_public', 'status', 'created_by', 'audience', 'subject_name', 'grade_levels', 'education_stage', 'track', 'book_type', 'term', 'original_name', 'source_path', 'file_size', 'mime_type', 'sha256', 'page_count', 'search_text', 'extracted_text'])]
class LibraryResource extends Model
{
    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'grade_levels' => 'array', 'file_size' => 'integer', 'page_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $resource): void {
            $resource->search_text = self::searchTextFor($resource->attributesToArray());
        });
    }

    public static function normalizeSearch(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ة' => 'ه', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? $text);
    }

    public static function searchTextFor(array $attributes): string
    {
        $parts = [];
        foreach (['title', 'category', 'subject_name', 'education_stage', 'track', 'book_type', 'term', 'original_name', 'extracted_text'] as $field) {
            $parts[] = (string) ($attributes[$field] ?? '');
        }
        $parts[] = (string) config('library.book_types.'.($attributes['book_type'] ?? ''), '');
        $grades = $attributes['grade_levels'] ?? [];
        if (is_string($grades)) {
            $grades = json_decode($grades, true) ?: [];
        }
        foreach ($grades as $grade) {
            $parts[] = (string) $grade;
            $parts[] = (string) config('library.grades.'.$grade, '');
        }

        return self::normalizeSearch(implode(' ', $parts));
    }

    public function scopeVisibleTo(Builder $query, Student $student): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('audience', 'all')
            ->where(function (Builder $classroomScope) use ($student): void {
                $classroomScope->whereNull('classroom_id');

                if ($student->classroom_id) {
                    $classroomScope->orWhere('classroom_id', $student->classroom_id);
                }
            })
            ->where(function (Builder $subjectScope) use ($student): void {
                $subjectScope->whereNull('subject_id');

                if ($student->classroom_id) {
                    $subjectScope->orWhereHas('subject', function (Builder $subjects) use ($student): void {
                        $subjects
                            ->where('status', 'active')
                            ->whereHas('classrooms', fn (Builder $classrooms) => $classrooms->whereKey($student->classroom_id));
                    });
                }
            })
            ->where(function (Builder $audienceScope): void {
                $audienceScope
                    ->where('is_public', true)
                    ->orWhereNotNull('classroom_id')
                    ->orWhereNotNull('subject_id');
            });
    }

    public function scopeVisibleToTeacher(Builder $query, Teacher $teacher): Builder
    {
        return $query->where('status', 'active')->whereIn('audience', ['all', 'teachers'])
            ->where(function (Builder $scope) use ($teacher): void {
            $scope->where(fn (Builder $national) => $national->whereNull('subject_id')->whereNull('classroom_id')
                ->where(fn (Builder $audience) => $audience->where('is_public', true)->orWhere('audience', 'teachers')))
                ->whereExists(function ($subjects) use ($teacher): void {
                    $subjects->selectRaw('1')->from('teacher_assignments as library_national_assignment')
                        ->join('subjects as library_national_subject', 'library_national_subject.id', '=', 'library_national_assignment.subject_id')
                        ->where('library_national_assignment.teacher_id', $teacher->id)
                        ->where('library_national_subject.status', 'active')
                        ->whereColumn('library_national_subject.name', 'library_resources.subject_name');
                })
                ->orWhereExists(function ($assignments) use ($teacher): void {
                        $assignments->selectRaw('1')->from('teacher_assignments as library_assignment')
                            ->join('subjects as library_subject', 'library_subject.id', '=', 'library_assignment.subject_id')
                            ->where('library_assignment.teacher_id', $teacher->id)
                            ->where('library_subject.status', 'active')
                            ->where(fn ($scope) => $scope->whereColumn('library_assignment.subject_id', 'library_resources.subject_id')->orWhereNull('library_resources.subject_id'))
                            ->where(fn ($scope) => $scope->whereColumn('library_assignment.classroom_id', 'library_resources.classroom_id')->orWhereNull('library_resources.classroom_id'))
                            ->where(fn ($scope) => $scope->whereNotNull('library_resources.subject_id')->orWhereNotNull('library_resources.classroom_id'));
                    });
            });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
