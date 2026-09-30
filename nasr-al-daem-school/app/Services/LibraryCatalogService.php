<?php

namespace App\Services;

use App\Models\LibraryResource;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LibraryCatalogService
{
    public const BOOK_TYPES = [
        'textbook' => 'كتاب الطالب', 'workbook' => 'كراسة التدريبات',
        'activity' => 'كتاب النشاط', 'teacher_guide' => 'دليل المعلم',
        'assessment' => 'التقييم والاختبارات', 'handwriting' => 'الخط',
    ];

    public const GRADES = [
        1 => 'الصف الأول', 2 => 'الصف الثاني', 3 => 'الصف الثالث', 4 => 'الصف الرابع',
        5 => 'الصف الخامس', 6 => 'الصف السادس', 7 => 'الصف السابع', 8 => 'الصف الثامن',
        9 => 'الصف التاسع', 10 => 'الأول الثانوي', 11 => 'الثاني الثانوي', 12 => 'الثالث الثانوي',
    ];

    public function visibleQuery(User $user): Builder
    {
        abort_unless($user->status === 'active', 403);
        if ($user->isAdmin()) {
            return LibraryResource::query();
        }
        if ($user->isStudent()) {
            $student = $user->student;
            abort_unless($student?->status === 'active', 403);

            return self::visibleStudentGrade(LibraryResource::query()->visibleTo($student), $student);
        }
        if ($user->isTeacher()) {
            $teacher = $user->teacher;
            abort_unless($teacher?->status === 'active', 403);

            return LibraryResource::query()->visibleToTeacher($teacher);
        }
        abort(403);
    }

    public function data(Request $request, User $user): array
    {
        $visible = $this->visibleQuery($user);
        $bookTypes = config('library.book_types', self::BOOK_TYPES);
        $grades = config('library.grades', self::GRADES);
        $filters = array_replace(array_fill_keys(['q', 'subject', 'grade', 'book_type', 'audience', 'track', 'term', 'category', 'subject_id', 'stage'], ''), $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
            'book_type' => ['nullable', Rule::in(array_keys($bookTypes))],
            'audience' => ['nullable', Rule::in(['all', 'teachers'])],
            'track' => ['nullable', 'string', 'max:100'],
            'term' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'stage' => ['nullable', 'string', 'max:100'],
        ]));
        if ($user->isStudent()) {
            // A student's class comes from their profile. Ignore grade values supplied in the URL.
            $filters['grade'] = '';
        }

        $catalogSubjects = (clone $visible)->whereNotNull('subject_name')->where('subject_name', '!=', '')
            ->selectRaw('subject_name as name, COUNT(*) as count')->groupBy('subject_name')->get()
            ->map(fn ($entry) => (object) ['name' => $entry->name, 'count' => (int) $entry->count]);
        $legacySubjects = (clone $visible)->where(fn ($query) => $query->whereNull('subject_name')->orWhere('subject_name', ''))
            ->whereNotNull('subject_id')->selectRaw('subject_id, COUNT(*) as count')->groupBy('subject_id')->get();
        $subjectNames = Subject::whereIn('id', $legacySubjects->pluck('subject_id'))->pluck('name', 'id');
        foreach ($legacySubjects as $entry) {
            if ($name = $subjectNames->get($entry->subject_id)) {
                $catalogSubjects->push((object) ['name' => $name, 'count' => (int) $entry->count]);
            }
        }
        $catalogSubjects = $catalogSubjects->groupBy('name')->map(fn ($entries, $name) => (object) ['name' => $name, 'count' => $entries->sum('count')])->sortBy('name')->values();
        $catalogTerms = (clone $visible)->whereNotNull('term')->where('term', '!=', '')->distinct()->orderBy('term')->pluck('term');
        $catalogTracks = (clone $visible)->whereNotNull('track')->where('track', '!=', '')->distinct()->orderBy('track')->pluck('track');
        $stats = [
            'total' => (clone $visible)->count(),
            'subjects' => $catalogSubjects->count(),
            'teacher_only' => (clone $visible)->where('audience', 'teachers')->count(),
        ];

        $query = clone $visible;
        foreach (preg_split('/\s+/u', LibraryResource::normalizeSearch((string) $filters['q']), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token).'%';
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]);
        }
        $query->when($filters['subject'], function (Builder $query, string $subject): void {
            $query->where(fn (Builder $scope) => $scope->where('subject_name', $subject)
                ->orWhere(fn (Builder $legacy) => $legacy->where(fn (Builder $empty) => $empty->whereNull('subject_name')->orWhere('subject_name', ''))
                    ->whereHas('subject', fn (Builder $subjects) => $subjects->where('name', $subject))));
        })->when($filters['grade'], fn (Builder $query, $grade) => $query->whereJsonContains('grade_levels', (int) $grade));
        foreach (['book_type', 'audience', 'track', 'term', 'category', 'subject_id'] as $field) {
            $query->when($filters[$field], fn (Builder $query, $value) => $query->where($field, $value));
        }
        $query->when($filters['stage'], fn (Builder $query, $stage) => $query->where(fn (Builder $scope) => $scope
            ->where('education_stage', $stage)
            ->orWhereHas('subject', fn (Builder $subjects) => $subjects->where('stage', $stage))
            ->orWhereHas('classroom', fn (Builder $classrooms) => $classrooms->where('stage', $stage))));
        $resources = $query->with(['subject', 'classroom'])->select([
            'id', 'title', 'category', 'subject_id', 'classroom_id', 'file_path', 'disk', 'is_public', 'status',
            'created_by', 'audience', 'subject_name', 'grade_levels', 'education_stage', 'track', 'book_type',
            'term', 'original_name', 'file_size', 'mime_type', 'page_count', 'created_at', 'updated_at',
        ])->orderBy('subject_name')->orderBy('title')->orderBy('id')->paginate(18)->withQueryString();

        return compact('resources', 'catalogSubjects', 'catalogTerms', 'catalogTracks', 'grades', 'bookTypes', 'filters', 'stats') + [
            'routePrefix' => $user->role.'.library',
            'layout' => $user->role.'.layout',
            'student' => $user->isStudent() ? $user->student->loadMissing('classroom') : null,
            'teacher' => $user->isTeacher() ? $user->teacher : null,
        ];
    }

    private static function visibleStudentGrade(Builder $query, Student $student): Builder
    {
        $grade = self::gradeForClassroom($student->classroom);

        return $query->where(fn (Builder $books) => $books
            ->whereNull('grade_levels')
            ->orWhereJsonLength('grade_levels', 0)
            ->when($grade, fn (Builder $books, int $grade) => $books->orWhereJsonContains('grade_levels', $grade)));
    }

    private static function gradeForClassroom(?Classroom $classroom): ?int
    {
        if (! $classroom) {
            return null;
        }

        $name = LibraryResource::normalizeSearch($classroom->name);
        $isSecondary = str_contains(LibraryResource::normalizeSearch($classroom->stage.' '.$classroom->name), 'ثانوي');
        if ($isSecondary) {
            foreach (['الحادي عشر' => 11, 'الثاني عشر' => 12, 'العاشر' => 10] as $ordinal => $grade) {
                if (str_contains($name, $ordinal)) {
                    return $grade;
                }
            }
        }
        if (preg_match('/(?:^|\s)(?:الصف\s*)?(1[0-2]|[1-9])(?:$|\s)/u', $name, $match)) {
            $grade = (int) $match[1];
            if (($isSecondary && $grade >= 10) || (! $isSecondary && $grade <= 9)) {
                return $grade;
            }
        }

        $ordinals = [
            'اول' => 1, 'الاول' => 1, 'اولي' => 1, 'الاولي' => 1,
            'ثاني' => 2, 'الثاني' => 2, 'الثانيه' => 2,
            'ثالث' => 3, 'الثالث' => 3, 'الثالثه' => 3,
            'رابع' => 4, 'الرابع' => 4, 'الرابعه' => 4,
            'خامس' => 5, 'الخامس' => 5, 'الخامسه' => 5,
            'سادس' => 6, 'السادس' => 6, 'السادسه' => 6,
            'سابع' => 7, 'السابع' => 7, 'السابعه' => 7,
            'ثامن' => 8, 'الثامن' => 8, 'الثامنه' => 8,
            'تاسع' => 9, 'التاسع' => 9, 'التاسعه' => 9,
        ];
        foreach ($ordinals as $ordinal => $number) {
            if (preg_match('/(?:^|\s)'.preg_quote($ordinal, '/').'(?=$|\s)/u', $name)) {
                return $isSecondary && $number <= 3 ? $number + 9 : ($isSecondary ? null : $number);
            }
        }

        return null;
    }
}
