<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Reads academic records; report classifications never modify the original marks. */
class TeacherReportSources
{
    public const TYPES = ['weekly', 'monthly', 'midterm', 'final', 'other'];

    public function catalog(Teacher $teacher, int $classroomId): array
    {
        $classroom = Classroom::query()->findOrFail($classroomId);
        $subjects = $this->subjects($teacher, $classroomId);
        abort_if($subjects->isEmpty(), 403, 'لا يمكنك إعداد تقارير لهذا الصف.');

        $students = Student::query()->where('classroom_id', $classroomId)->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', 'student')->where('status', 'active'))
            ->with('user:id,name')->get()->sortBy(fn ($student) => $student->user->name)->values();
        $studentIds = $students->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
        $metadata = Schema::hasTable('teacher_report_sources')
            ? DB::table('teacher_report_sources')->where('teacher_id', $teacher->id)->where('classroom_id', $classroomId)->get()->keyBy('source_key')
            : collect();
        $sources = array_merge(
            $this->sheetSources($teacher, $classroomId, $studentIds, $subjects, $metadata),
            $this->examSources($teacher, $classroomId, $studentIds, $subjects, $metadata),
        );

        return [
            'classroom' => ['id' => (int) $classroom->id, 'label' => $this->classroomLabel($classroom)],
            'subjects' => $subjects->map(fn ($subject) => ['id' => (int) $subject->id, 'name' => $subject->name])->values()->all(),
            'students' => $students->map(fn ($student) => ['id' => (int) $student->id, 'name' => $student->user->name])->all(),
            'sources' => $sources,
        ];
    }

    public function saveMetadata(Teacher $teacher, int $classroomId, array $sources): array
    {
        if (! Schema::hasTable('teacher_report_sources')) {
            throw ValidationException::withMessages(['sources' => 'لم تكتمل تهيئة التقارير بعد. يرجى التواصل مع مسؤول النظام.']);
        }
        $catalog = $this->catalog($teacher, $classroomId);
        $current = collect($catalog['sources'])->keyBy('key');
        $subjectIds = array_column($catalog['subjects'], 'id');
        foreach ($sources as &$entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (['subject_id', 'assessment_type', 'assessment_date'] as $field) {
                if (array_key_exists($field, $entry) && $entry[$field] === '') {
                    $entry[$field] = null;
                }
            }
        }
        unset($entry);
        $data = Validator::make(['sources' => $sources], [
            'sources' => ['array', 'max:1000'],
            'sources.*' => ['required', 'array:key,subject_id,assessment_type,assessment_date'],
            'sources.*.key' => ['required', 'string', 'max:160', 'distinct'],
            'sources.*.subject_id' => ['nullable', 'integer', Rule::in($subjectIds)],
            'sources.*.assessment_type' => ['nullable', Rule::in(self::TYPES)],
            'sources.*.assessment_date' => ['nullable', 'date', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
        ])->validate();

        $rows = [];
        foreach ($data['sources'] as $index => $entry) {
            $source = $current->get($entry['key']);
            if (! $source) {
                throw ValidationException::withMessages(["sources.{$index}.key" => 'أحد التقييمات غير متاح لهذا الصف أو لم يعد موجودًا.']);
            }
            foreach (['subject_id' => 'subject', 'assessment_date' => 'date'] as $field => $permission) {
                if (! $source['metadata_editable'][$permission] && array_key_exists($field, $entry)
                    && (string) ($entry[$field] ?? '') !== (string) ($source[$field] ?? '')) {
                    throw ValidationException::withMessages(["sources.{$index}.{$field}" => 'لا يمكن تغيير مادة الاختبار أو تاريخه من إعدادات التقرير.']);
                }
            }
            $rows[] = [
                'teacher_id' => $teacher->id, 'classroom_id' => $classroomId, 'source_key' => $source['key'],
                'subject_id' => array_key_exists('subject_id', $entry) ? $entry['subject_id'] : $source['subject_id'],
                'assessment_type' => array_key_exists('assessment_type', $entry) ? $entry['assessment_type'] : $source['assessment_type'],
                'assessment_date' => array_key_exists('assessment_date', $entry) ? $entry['assessment_date'] : $source['assessment_date'],
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::transaction(function () use ($teacher, $classroomId, $rows): void {
                // Re-check the current assignment immediately before saving report-only metadata.
                $allowed = $this->subjects($teacher, $classroomId)->pluck('id')->map(fn ($id) => (int) $id)->all();
                abort_if($allowed === [], 403, 'لا يمكنك إعداد تقارير لهذا الصف.');
                foreach ($rows as $row) {
                    if ($row['subject_id'] !== null && ! in_array((int) $row['subject_id'], $allowed, true)) {
                        throw ValidationException::withMessages(['sources' => 'تغيّرت المواد المسندة إليك. أعد تحميل الصف ثم حاول مجددًا.']);
                    }
                }
                DB::table('teacher_report_sources')->upsert($rows, ['teacher_id', 'classroom_id', 'source_key'], [
                    'subject_id', 'assessment_type', 'assessment_date', 'updated_at',
                ]);
            });
        }

        return $this->catalog($teacher, $classroomId);
    }

    private function subjects(Teacher $teacher, int $classroomId): Collection
    {
        abort_unless(Teacher::query()->whereKey($teacher->id)->exists(), 403);

        return DB::table('teacher_assignments as assignment')->join('subjects', 'subjects.id', '=', 'assignment.subject_id')
            ->where('assignment.teacher_id', $teacher->id)->where('assignment.classroom_id', $classroomId)
            ->select('subjects.id', 'subjects.name')->distinct()->orderBy('subjects.name')->get();
    }

    private function sheetSources(Teacher $teacher, int $classroomId, array $studentIds, Collection $subjects, Collection $metadata): array
    {
        if (! Schema::hasTable('grade_sheets')) {
            return [];
        }
        $sheet = DB::table('grade_sheets')->where('teacher_id', $teacher->id)->where('classroom_id', $classroomId)->first();
        if (! $sheet) {
            return [];
        }
        $scores = $this->decoded($sheet->column_scores ?? null);
        $columns = $this->decoded($sheet->sheet_data ?? null);
        if (! array_is_list($columns)) {
            $columns = [];
        }
        if ($columns === []) {
            $columns = array_values(array_filter([
                ['key' => 'monthly', 'title' => 'اختبار شهري', 'max_score' => 100],
                ['key' => 'midterm', 'title' => 'اختبار نصفي', 'max_score' => 100],
                ['key' => 'work', 'title' => 'أعمال', 'max_score' => 100],
                ['key' => 'activity', 'title' => 'نشاط', 'max_score' => 100],
            ], fn ($column) => $this->sheetMarks($scores[$column['key']] ?? null, $studentIds, 100) !== []));
        }
        $sources = [];
        $seen = [];
        $hasRawMarks = false;
        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['key'] ?? null)
                || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $column['key']) || isset($seen[$column['key']])) {
                continue;
            }
            $maximum = $column['max_score'] ?? 100;
            if (! $this->number($maximum, 100) || (float) $maximum <= 0) {
                continue;
            }
            $seen[$column['key']] = true;
            $marks = $this->sheetMarks($scores[$column['key']] ?? null, $studentIds, (float) $maximum);
            $hasRawMarks = $hasRawMarks || $marks !== [];
            $key = 'sheet:'.$sheet->id.':column:'.$column['key'];
            $source = [
                'key' => $key, 'kind' => 'sheet',
                'title' => is_string($column['title'] ?? null) && trim($column['title']) !== '' ? trim($column['title']) : 'تقييم من سجل الدرجات',
                'subject_id' => $subjects->count() === 1 ? (int) $subjects->first()->id : null,
                'assessment_type' => in_array($column['key'], ['monthly', 'midterm'], true) ? $column['key'] : null,
                'assessment_date' => null, 'maximum_score' => (float) $maximum, 'marks' => $marks,
                'metadata_editable' => ['subject' => true, 'type' => true, 'date' => true],
            ];
            $sources[] = $this->applyMetadata($source, $metadata->get($key), $subjects);
        }
        if (! $hasRawMarks) {
            $marks = $this->sheetMarks($this->decoded($sheet->scores ?? null), $studentIds, 100);
            if ($marks !== []) {
                $key = 'sheet:'.$sheet->id.':overall';
                $sources[] = $this->applyMetadata([
                    'key' => $key, 'kind' => 'sheet', 'title' => 'المعدل المحفوظ في سجل الدرجات',
                    'subject_id' => $subjects->count() === 1 ? (int) $subjects->first()->id : null,
                    'assessment_type' => null, 'assessment_date' => null, 'maximum_score' => 100.0, 'marks' => $marks,
                    'metadata_editable' => ['subject' => true, 'type' => true, 'date' => true],
                ], $metadata->get($key), $subjects);
            }
        }

        return $sources;
    }

    private function examSources(Teacher $teacher, int $classroomId, array $studentIds, Collection $subjects, Collection $metadata): array
    {
        if (! Schema::hasTable('exams')) {
            return [];
        }
        $query = DB::table('exams')->where('exams.teacher_id', $teacher->id)->where('exams.classroom_id', $classroomId)
            ->whereIn('exams.subject_id', $subjects->pluck('id'))->whereIn('exams.status', ['published', 'completed'])->select('exams.*');
        if (Schema::hasTable('exam_periods') && Schema::hasColumn('exams', 'exam_period_id')) {
            $query->leftJoin('exam_periods as report_period', 'report_period.id', '=', 'exams.exam_period_id')
                ->addSelect('report_period.exam_type as report_period_type');
        }
        $exams = $query->orderBy('exams.starts_at')->orderBy('exams.id')->get();
        if ($exams->isEmpty()) {
            return [];
        }
        $manual = Schema::hasTable('grades') && $studentIds !== []
            ? DB::table('grades')->whereIn('exam_id', $exams->pluck('id'))->whereIn('student_id', array_keys($studentIds))->get()
                ->keyBy(fn ($row) => $row->exam_id.':'.$row->student_id)
            : collect();
        $automatic = Schema::hasTable('exam_attempts') && $studentIds !== []
            ? DB::table('exam_attempts')->whereIn('exam_id', $exams->pluck('id'))->whereIn('student_id', array_keys($studentIds))
                ->where('status', 'submitted')->whereNotNull('submitted_at')->whereNotNull('graded_at')
                ->orderByDesc('attempt_number')->orderByDesc('id')->get()->groupBy(fn ($row) => $row->exam_id.':'.$row->student_id)
            : collect();

        return $exams->map(function ($exam) use ($studentIds, $subjects, $manual, $automatic, $metadata): array {
            $marks = [];
            foreach ($studentIds as $studentId => $_) {
                $recordKey = $exam->id.':'.$studentId;
                $record = $manual->get($recordKey);
                if ($record && $this->mark($record->score, $exam->total_score)) {
                    $marks[$studentId] = ['score' => (float) $record->score, 'maximum' => (float) $exam->total_score];

                    continue;
                }
                foreach ($automatic->get($recordKey, collect()) as $attempt) {
                    if ($this->mark($attempt->score, $attempt->maximum_score)) {
                        $marks[$studentId] = ['score' => (float) $attempt->score, 'maximum' => (float) $attempt->maximum_score];
                        break;
                    }
                }
            }
            $key = 'exam:'.$exam->id;

            return $this->applyMetadata([
                'key' => $key, 'kind' => 'exam', 'title' => (string) $exam->title,
                'subject_id' => (int) $exam->subject_id,
                'assessment_type' => $this->periodType($exam->report_period_type ?? null),
                'assessment_date' => $this->schoolDate($exam->starts_at ?? null),
                'maximum_score' => $this->number($exam->total_score) && (float) $exam->total_score > 0 ? (float) $exam->total_score : null,
                'marks' => $marks, 'metadata_editable' => ['subject' => false, 'type' => true, 'date' => false],
            ], $metadata->get($key), $subjects);
        })->all();
    }

    private function applyMetadata(array $source, ?object $metadata, Collection $subjects): array
    {
        $allowed = $subjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($metadata && $metadata->subject_id !== null && ! in_array((int) $metadata->subject_id, $allowed, true)) {
            $metadata = null;
        }
        if ($metadata && $source['kind'] === 'exam' && $metadata->subject_id !== null && (int) $metadata->subject_id !== $source['subject_id']) {
            $metadata = null;
        }
        if ($metadata) {
            if ($source['kind'] === 'sheet') {
                $source['subject_id'] = $metadata->subject_id === null ? null : (int) $metadata->subject_id;
                $source['assessment_date'] = $this->date($metadata->assessment_date);
            }
            $source['assessment_type'] = in_array($metadata->assessment_type, self::TYPES, true) ? $metadata->assessment_type : null;
        }
        $source['subject_name'] = $source['subject_id'] === null ? null : $subjects->firstWhere('id', $source['subject_id'])?->name;
        $source['needs_mapping'] = $source['subject_id'] === null || $source['assessment_type'] === null || $source['assessment_date'] === null;

        return $source;
    }

    private function sheetMarks(mixed $values, array $studentIds, float $maximum): array
    {
        if (! is_array($values)) {
            return [];
        }
        $marks = [];
        foreach ($values as $studentId => $score) {
            if (isset($studentIds[(string) $studentId]) && $this->mark($score, $maximum)) {
                $marks[(string) $studentId] = ['score' => (float) $score, 'maximum' => $maximum];
            }
        }

        return $marks;
    }

    private function mark(mixed $score, mixed $maximum): bool
    {
        return $this->number($maximum) && (float) $maximum > 0 && $this->number($score, (float) $maximum);
    }

    private function number(mixed $value, float $maximum = PHP_FLOAT_MAX): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)
            && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= $maximum;
    }

    private function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function schoolDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, config('app.timezone', 'UTC'))->setTimezone(LessonPublicationTime::timezone())->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function periodType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $type = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا'])) ?? ''));

        return match ($type) {
            'weekly', 'اسبوعي', 'اختبار اسبوعي', 'امتحان اسبوعي' => 'weekly',
            'monthly', 'شهري', 'اختبار شهري', 'امتحان شهري' => 'monthly',
            'midterm', 'mid-term', 'mid_term', 'نصفي', 'اختبار نصفي', 'امتحان نصفي' => 'midterm',
            'final', 'نهائي', 'اختبار نهائي', 'امتحان نهائي' => 'final',
            'other', 'اخرى' => 'other',
            default => null,
        };
    }

    private function classroomLabel(Classroom $classroom): string
    {
        $name = trim((string) $classroom->name);
        $section = trim((string) $classroom->section);
        $alreadyIncluded = $section === '' || preg_match('/(?:^|[\s\-–—(])'.preg_quote($section, '/').'\)?$/ui', $name);

        return $name.($alreadyIncluded ? '' : ' '.$section);
    }
}
