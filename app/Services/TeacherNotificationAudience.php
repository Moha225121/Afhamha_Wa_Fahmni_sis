<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherNotificationAudience
{
    public const MAX_RECIPIENTS = 100;

    public const MAX_MESSAGING_RECIPIENTS = 2000;

    public const LABELS = [
        'all' => 'الطلاب وأولياء أمورهم ضمن نطاق تدريسي',
        'students' => 'الطلاب فقط', 'guardians' => 'أولياء الأمور فقط',
        'student' => 'طالب محدد', 'guardian' => 'ولي أمر محدد',
        'families' => 'الطلاب مع نسخة لأولياء أمورهم', 'parents' => 'أولياء الأمور فقط',
    ];

    public function pairs(Teacher $teacher): QueryBuilder
    {
        return DB::table('teacher_assignments as a')
            ->join('classrooms as c', 'c.id', '=', 'a.classroom_id')->whereNull('c.deleted_at')
            ->join('subjects as s', 's.id', '=', 'a.subject_id')->where('s.status', 'active')
            ->join('classroom_subject as cs', fn ($join) => $join->on('cs.classroom_id', '=', 'a.classroom_id')->on('cs.subject_id', '=', 'a.subject_id'))
            ->where('a.teacher_id', $teacher->id);
    }

    public function query(Teacher $teacher, array $filters, bool $selectIndividual = true): Builder
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->isTeacher(), 403);
        if ($this->isMessaging($filters)) {
            $students = $this->messagingStudents($teacher, $filters, $selectIndividual);
            $guardians = $this->guardiansFor($students);
            if (! empty($filters['guardian_ids'])) {
                $guardians->whereIn('user_id', $this->ids($filters['guardian_ids'], 'guardian_ids'));
            }

            return User::query()->where('status', 'active')->where(function ($users) use ($filters, $students, $guardians): void {
                if ($filters['audience'] === 'families') {
                    $users->where(fn ($q) => $q->where('role', 'student')->whereIn('id', (clone $students)->select('user_id')));
                }
                $users->orWhere(fn ($q) => $q->where('role', 'parent')->whereIn('id', $guardians->select('user_id')));
            });
        }
        foreach (['classroom_ids', 'student_ids', 'guardian_ids', 'selection_mode'] as $field) {
            if (array_key_exists($field, $filters) && ($field !== 'selection_mode' || ! empty($filters[$field]))) {
                throw ValidationException::withMessages([$field => 'هذا الاختيار متاح في واجهة الرسائل الجديدة فقط.']);
            }
        }
        $pairs = $this->pairs($teacher);
        foreach (['subject_id', 'classroom_id'] as $field) {
            if (! empty($filters[$field]) && ! (clone $pairs)->where('a.'.$field, $filters[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'هذا الاختيار خارج نطاق تكليفاتك التدريسية النشطة.']);
            }
        }
        $pairs->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('a.subject_id', $id))
            ->when($filters['classroom_id'] ?? null, fn ($q, $id) => $q->where('a.classroom_id', $id));
        if (! empty($filters['subject_id']) && ! empty($filters['classroom_id']) && ! (clone $pairs)->exists()) {
            throw ValidationException::withMessages(['classroom_id' => 'لست مكلّفاً بتدريس هذه المادة في الصف المحدد.']);
        }
        $students = Student::query()->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
            ->whereIn('classroom_id', $pairs->select('a.classroom_id'));
        $guardians = Guardian::query()->where('status', 'active')
            ->whereHas('students', fn ($q) => $q->whereIn('students.id', (clone $students)->select('students.id')));
        $audience = $filters['audience'] ?? 'students';
        if (! array_key_exists($audience, self::LABELS)) {
            throw ValidationException::withMessages(['audience' => 'نوع الجمهور غير صالح.']);
        }

        $users = User::query()->where('status', 'active')->where(function ($q) use ($audience, $students, $guardians): void {
            if (in_array($audience, ['all', 'students', 'student'], true)) {
                $q->where(fn ($q) => $q->where('role', 'student')->whereIn('id', (clone $students)->select('user_id')));
            }
            if (in_array($audience, ['all', 'guardians', 'guardian'], true)) {
                $q->orWhere(fn ($q) => $q->where('role', 'parent')->whereIn('id', (clone $guardians)->select('user_id')));
            }
        });
        if ($selectIndividual && in_array($audience, ['student', 'guardian'], true)) {
            if (empty($filters['recipient_id'])) {
                throw ValidationException::withMessages(['recipient_id' => 'اختر مستلماً واحداً من القائمة المسموح بها.']);
            }
            $users->whereKey($filters['recipient_id']);
        } elseif ($selectIndividual && ! empty($filters['recipient_id'])) {
            throw ValidationException::withMessages(['recipient_id' => 'لا تختَر مستلماً فردياً مع جمهور جماعي.']);
        }

        return $users;
    }

    public function resolve(Teacher $teacher, array $filters): Collection
    {
        $limit = $this->limit($filters);
        $users = $this->query($teacher, $filters)->select(['users.id', 'users.name', 'users.role'])
            ->orderBy('users.id')->limit($limit + 1)->get();
        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['recipient_id' => 'لا يوجد مستلم نشط مسموح به ضمن الاختيارات الحالية.']);
        }
        if ($users->count() > $limit) {
            throw ValidationException::withMessages(['audience' => 'الحد الأقصى '.$limit.' مستلم في الطلب الواحد. حدّد صفاً أو أسماء أقل لتضييق النطاق.']);
        }

        return $users;
    }

    public function isMessaging(array $filters): bool
    {
        return in_array($filters['audience'] ?? null, ['families', 'parents'], true);
    }

    public function limit(array $filters): int
    {
        return $this->isMessaging($filters) ? self::MAX_MESSAGING_RECIPIENTS : self::MAX_RECIPIENTS;
    }

    private function ids(mixed $values, string $field): array
    {
        if (! is_array($values) || $values === [] || count($values) > self::MAX_MESSAGING_RECIPIENTS) {
            throw ValidationException::withMessages([$field => 'اختر قائمة غير فارغة من الصفوف أو الأسماء المسموح بها.']);
        }
        $ids = [];
        foreach ($values as $value) {
            $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
            if ($id === false || $id < 1 || in_array($id, $ids, true)) {
                throw ValidationException::withMessages([$field => 'تحتوي القائمة على اختيار غير صالح أو مكرر.']);
            }
            $ids[] = $id;
        }
        sort($ids);

        return $ids;
    }

    private function guardiansFor(Builder $students): Builder
    {
        return Guardian::query()->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('role', 'parent')->where('status', 'active'))
            ->whereHas('students', fn ($q) => $q->whereIn('students.id', (clone $students)->select('students.id')));
    }

    private function messagingStudents(Teacher $teacher, array $filters, bool $requireSelection = true): Builder
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->isTeacher(), 403);
        if (! empty($filters['classroom_id']) || ! empty($filters['recipient_id'])) {
            throw ValidationException::withMessages(['classroom_ids' => 'استخدم اختيار الصفوف أو الأسماء في واجهة الرسائل.']);
        }
        $pairs = $this->pairs($teacher);
        if (! empty($filters['subject_id'])) {
            $pairs->where('a.subject_id', $filters['subject_id']);
            if (! (clone $pairs)->exists()) {
                throw ValidationException::withMessages(['subject_id' => 'هذه المادة خارج نطاق تكليفاتك التدريسية النشطة.']);
            }
        }
        $students = Student::query()->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
            ->whereIn('classroom_id', (clone $pairs)->select('a.classroom_id'));
        $fields = array_values(array_filter(['classroom_ids', 'student_ids', 'guardian_ids'], fn ($field) => array_key_exists($field, $filters)));
        if (! $requireSelection && $fields === []) {
            return $students;
        }
        if (count($fields) !== 1) {
            throw ValidationException::withMessages(['classroom_ids' => 'اختر صفوفاً أو أسماء محددة قبل معاينة الرسالة.']);
        }
        $field = $fields[0];
        $ids = $this->ids($filters[$field], $field);
        $mode = $filters['selection_mode'] ?? ($field === 'classroom_ids' ? 'branches' : 'people');
        if (! in_array($mode, ['all', 'grade', 'branches', 'people'], true)
            || ($mode === 'people') !== ($field !== 'classroom_ids')
            || ($field === 'student_ids' && $filters['audience'] !== 'families')
            || ($field === 'guardian_ids' && $filters['audience'] !== 'parents')) {
            throw ValidationException::withMessages([$field => 'قائمة الأسماء لا تطابق طريقة اختيار المستلمين.']);
        }
        if ($field === 'classroom_ids') {
            $allowed = (clone $pairs)->whereIn('a.classroom_id', $ids)->distinct()->pluck('a.classroom_id')->map(fn ($id) => (int) $id)->all();
            if (count(array_diff($ids, $allowed))) {
                throw ValidationException::withMessages([$field => 'أحد الصفوف المحددة خارج نطاق تكليفاتك التدريسية النشطة.']);
            }

            return $students->whereIn('classroom_id', $ids);
        }
        if ($field === 'student_ids') {
            $allowed = (clone $students)->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if (count(array_diff($ids, $allowed))) {
                throw ValidationException::withMessages([$field => 'أحد الطلاب المحددين غير نشط أو خارج صفوفك المكلّف بها.']);
            }

            return $students->whereIn('user_id', $ids);
        }
        $allowed = $this->guardiansFor($students)->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if (count(array_diff($ids, $allowed))) {
            throw ValidationException::withMessages([$field => 'أحد أولياء الأمور غير مرتبط بطالب نشط ضمن صفوفك المكلّف بها.']);
        }

        return $students->whereHas('guardians', fn ($q) => $q->where('guardians.status', 'active')->whereIn('guardians.user_id', $ids));
    }

    /** Snapshot only the selected, currently permitted children for each recipient user. */
    public function contexts(Teacher $teacher, array $filters, Collection $recipients): array
    {
        if (! $this->isMessaging($filters)) {
            return [];
        }
        $studentUserIds = $recipients->where('role', 'student')->pluck('id')->all();
        $parentUserIds = $recipients->where('role', 'parent')->pluck('id')->all();
        $students = $this->messagingStudents($teacher, $filters)
            ->where(function ($query) use ($studentUserIds, $parentUserIds): void {
                $query->whereIn('user_id', $studentUserIds)
                    ->orWhereHas('guardians', fn ($q) => $q->whereIn('guardians.user_id', $parentUserIds));
            })
            ->with(['user', 'classroom', 'guardians' => fn ($q) => $q->where('status', 'active')
                ->whereHas('user', fn ($user) => $user->where('role', 'parent')->where('status', 'active'))])
            ->orderBy('students.id')->get();
        $contexts = [];
        foreach ($students as $student) {
            if (in_array($student->user_id, $studentUserIds)) {
                $contexts[$student->user_id] = [
                    'student_ids' => [(int) $student->id], 'student_names' => [$student->user->name],
                    'classroom_ids' => [(int) $student->classroom_id],
                    'classroom_name' => trim(($student->classroom?->name ?? '').' '.($student->classroom?->section ?? '')),
                ];
            }
            foreach ($student->guardians as $guardian) {
                if (! in_array($guardian->user_id, $parentUserIds)) {
                    continue;
                }
                $contexts[$guardian->user_id] ??= ['student_ids' => [], 'student_names' => [], 'classroom_ids' => []];
                $contexts[$guardian->user_id]['student_ids'][] = (int) $student->id;
                $contexts[$guardian->user_id]['student_names'][] = $student->user->name;
                $contexts[$guardian->user_id]['classroom_ids'][] = (int) $student->classroom_id;
            }
        }
        ksort($contexts);

        return $contexts;
    }

    public function messageBody(User $recipient, string $message, ?array $context): string
    {
        return $recipient->role === 'parent' && ! empty($context['student_names'])
            ? 'بخصوص: '.implode('، ', $context['student_names'])."\n\n".$message : $message;
    }

    /** Existing assigned classrooms and active child-parent links only; never creates classes. */
    public function messagingData(Teacher $teacher, array $filters = []): array
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->isTeacher(), 403);
        $pairs = $this->pairs($teacher)->select(['a.classroom_id', 'a.subject_id'])->get();
        $classrooms = TeacherLessonTargets::ordered(Classroom::whereIn('id', $pairs->pluck('classroom_id'))->with('academicYear')->get());
        $groups = TeacherLessonTargets::groups($classrooms, $pairs);
        $students = Student::query()->where('status', 'active')->whereIn('classroom_id', $classrooms->pluck('id'))
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
            ->with(['user', 'guardians' => fn ($q) => $q->where('status', 'active')
                ->whereHas('user', fn ($user) => $user->where('role', 'parent')->where('status', 'active'))->with('user')])
            ->orderBy('students.id')->get();
        $parents = $students->flatMap(fn ($student) => $student->guardians)->unique('user_id')->sortBy(fn ($guardian) => $guardian->user->name);
        $grades = array_map(fn ($group) => ['id' => $group['key'], 'name' => $group['label'], 'classIds' => $group['classroom_ids']], $groups);
        $selectedRooms = is_array($filters['classroom_ids'] ?? null) ? array_map('intval', $filters['classroom_ids']) : [];
        $selectedGrade = collect($grades)->first(fn ($grade) => count(array_intersect($grade['classIds'], $selectedRooms)) > 0);
        $audience = in_array($filters['audience'] ?? null, ['families', 'parents'], true) ? $filters['audience'] : 'families';
        $personField = $audience === 'parents' ? 'guardian_ids' : 'student_ids';
        $initialMode = $filters['selection_mode'] ?? (array_key_exists($personField, $filters) ? 'people' : 'grade');
        if (array_key_exists('classroom_ids', $filters)) {
            $sameRooms = function (array $ids) use ($selectedRooms): bool {
                return $selectedRooms !== [] && count($selectedRooms) === count($ids)
                    && array_diff($selectedRooms, $ids) === [] && array_diff($ids, $selectedRooms) === [];
            };
            $isAll = $initialMode === 'all' && $sameRooms($classrooms->pluck('id')->map(fn ($id) => (int) $id)->all());
            $isGrade = $initialMode === 'grade' && $sameRooms($selectedGrade['classIds'] ?? []);
            if (! $isAll && ! $isGrade) {
                $initialMode = 'branches';
            }
        }

        return [
            'teacherName' => $teacher->user->name, 'limit' => self::MAX_MESSAGING_RECIPIENTS, 'unlinkedPolicy' => 'include', 'previewOnly' => false,
            'grades' => $grades,
            'classes' => $classrooms->map(function (Classroom $room) use ($grades): array {
                $group = collect($grades)->first(fn ($grade) => in_array((int) $room->id, $grade['classIds'], true));
                $branch = TeacherLessonTargets::branchLabel($room);
                $name = $group['name'] ?? $room->name;

                return ['id' => (int) $room->id, 'name' => $name.(count($group['classIds'] ?? []) > 1 && $branch !== '' ? ' '.$branch : ''), 'branch' => $branch];
            })->values()->all(),
            'students' => $students->map(fn ($student) => [
                'id' => (int) $student->user_id, 'name' => $student->user->name, 'classId' => (int) $student->classroom_id,
                'parentIds' => $student->guardians->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            ])->values()->all(),
            'parents' => $parents->map(fn ($guardian) => ['id' => (int) $guardian->user_id, 'name' => $guardian->user->name])->values()->all(),
            'initialState' => [
                'audience' => $audience,
                'mode' => $initialMode,
                'gradeId' => $selectedGrade['id'] ?? ($grades[0]['id'] ?? null),
                'branchIds' => $selectedRooms,
                'personIds' => is_array($filters[$personField] ?? null) ? array_map('intval', $filters[$personField]) : [],
            ],
        ];
    }
}
