<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResultPublicationService
{
    public const STATUSES = ['draft' => 'مسودة', 'under_review' => 'قيد المراجعة', 'approved' => 'معتمدة', 'published' => 'منشورة', 'hidden' => 'مخفية'];

    public function students(object $period)
    {
        return Student::with('user')->whereHas('classroom', fn ($q) => $q->where('academic_year_id', $period->academic_year_id))
            ->when($period->classroom_id, fn ($q) => $q->where('classroom_id', $period->classroom_id))
            ->when($period->grade, fn ($q) => $q->whereHas('classroom', fn ($c) => $c->where('name', $period->grade)));
    }

    public function saveMark(int $periodId, array $data): void
    {
        DB::transaction(function () use ($periodId, $data): void {
            $period = $this->locked($periodId);
            $this->editable($period);
            abort_unless($this->students($period)->whereKey($data['student_id'])->exists(), 422, 'الطالب خارج نطاق فترة النتائج.');
            $key = ['exam_period_id' => $periodId, 'student_id' => $data['student_id'], 'subject_id' => $data['subject_id']];
            $old = DB::table('result_marks')->where($key)->first();
            DB::table('result_marks')->updateOrInsert($key, ['score' => $data['score'], 'maximum_score' => $data['maximum_score'], 'notes' => $data['notes'] ?? null, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(SchoolAccountService::class)->audit('result_mark_saved', 'results', $periodId, (array) $old, $data);
        });
    }

    public function importExams(int $periodId, array $examIds): void
    {
        DB::transaction(function () use ($periodId, $examIds): void {
            $period = $this->locked($periodId);
            $this->editable($period);
            $students = $this->students($period)->pluck('id');
            foreach ($examIds as $examId) {
                $exam = DB::table('exams')->join('classrooms', 'classrooms.id', '=', 'exams.classroom_id')->where('exams.id', $examId)->where('classrooms.academic_year_id', $period->academic_year_id)->when($period->classroom_id, fn ($q) => $q->where('classrooms.id', $period->classroom_id))->when($period->grade, fn ($q) => $q->where('classrooms.name', $period->grade))->select('exams.*')->first();
                abort_unless($exam, 422, 'أحد الاختبارات خارج نطاق الفترة.');
                if ($exam->exam_period_id && (int) $exam->exam_period_id !== $periodId) {
                    throw ValidationException::withMessages(['exams' => 'الاختبار مرتبط بالفعل بفترة أخرى.']);
                }
                $manual = DB::table('grades')->where('exam_id', $examId)->whereIn('student_id', $students)->get()->keyBy('student_id');
                $automatic = DB::table('exam_attempts')->where('exam_id', $examId)->whereIn('student_id', $students)->where('status', 'submitted')->whereNotNull('percentage')->orderByDesc('id')->get()->unique('student_id')->keyBy('student_id');
                foreach ($students as $studentId) {
                    $mark = $manual->get($studentId) ?? $automatic->get($studentId);
                    if (! $mark) {
                        continue;
                    }
                    $max = isset($mark->maximum_score) ? $mark->maximum_score : $exam->total_score;
                    $this->saveMark($periodId, ['student_id' => $studentId, 'subject_id' => $exam->subject_id, 'score' => $mark->score, 'maximum_score' => $max, 'notes' => 'من الاختبار: '.$exam->title]);
                }
                DB::table('exams')->where('id', $examId)->update(['exam_period_id' => $periodId]);
            }
            app(SchoolAccountService::class)->audit('results_imported', 'results', $periodId, [], ['exam_ids' => $examIds]);
        }, 3);
    }

    public function transition(int $periodId, string $status): void
    {
        DB::transaction(function () use ($periodId, $status): void {
            $period = $this->locked($periodId);
            if ($period->status === $status) {
                return;
            }
            $allowed = ['draft' => ['under_review'], 'under_review' => ['draft', 'approved'], 'approved' => ['draft', 'published'], 'published' => ['hidden'], 'hidden' => ['draft', 'published']];
            if (! in_array($status, $allowed[$period->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'انتقال الحالة غير مسموح. راجع النتائج ثم اعتمدها قبل النشر.']);
            }
            if (in_array($status, ['under_review', 'approved', 'published'], true) && ! DB::table('result_marks')->where('exam_period_id', $periodId)->exists()) {
                throw ValidationException::withMessages(['status' => 'أدخل درجات الفترة قبل مراجعتها أو نشرها.']);
            }
            $changes = ['status' => $status, 'updated_at' => now()];
            if ($status === 'published') {
                if ($period->scheduled_at && now()->lt($period->scheduled_at)) {
                    throw ValidationException::withMessages(['status' => 'لم يحِن موعد النشر المحدد. ستُنشر الفترة المعتمدة عند حلول الموعد.']);
                }
                $marks = DB::table('result_marks as m')->join('subjects as s', 's.id', '=', 'm.subject_id')->where('m.exam_period_id', $periodId)->select('m.*', 's.name as subject')->get();
                foreach ($marks->groupBy('student_id') as $studentId => $rows) {
                    $total = round((float) $rows->sum('score'), 2);
                    $maximum = round((float) $rows->sum('maximum_score'), 2);
                    if ($maximum <= 0 || $rows->contains(fn ($r) => $r->score < 0 || $r->score > $r->maximum_score || $r->maximum_score <= 0)) {
                        throw ValidationException::withMessages(['marks' => 'توجد درجات غير صالحة تمنع النشر.']);
                    }
                    $percentage = round($total * 100 / $maximum, 2);
                    $passed = $rows->every(fn ($r) => $r->score * 100 / $r->maximum_score >= 50);
                    $payload = ['subjects' => $rows->map(fn ($r) => ['subject_id' => $r->subject_id, 'subject' => $r->subject, 'score' => $r->score, 'maximum_score' => $r->maximum_score, 'notes' => $r->notes])->values()->all(), 'total' => $total, 'maximum' => $maximum, 'percentage' => $percentage, 'passed' => $passed, 'rating' => match (true) {
                        $percentage >= 85 => 'ممتاز', $percentage >= 75 => 'جيد جدًا', $percentage >= 65 => 'جيد', $percentage >= 50 => 'مقبول', default => 'ضعيف'
                    }];
                    $key = ['exam_period_id' => $periodId, 'student_id' => $studentId];
                    $old = DB::table('result_publications')->where($key)->first();
                    DB::table('result_publications')->updateOrInsert($key, ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
                    app(SchoolAccountService::class)->audit('student_result_published', 'results', $periodId, $old ? json_decode($old->payload, true) : [], $payload + ['student_id' => $studentId]);
                    $student = Student::find($studentId);
                    if ($student) {
                        app(PortalEventService::class)->notify($student, 'results:'.$periodId.':'.($period->revision + 1), ['title' => 'إعلان النتائج', 'body' => 'تم إعلان نتيجة '.$period->name.' للطالب '.$student->user->name.'. يمكنك الاطلاع عليها من قسم النتائج.', 'url' => route('parent.results', ['student' => $studentId]), 'category' => 'grade'], true);
                    }
                }
                $changes += ['published_at' => now(), 'revision' => $period->revision + 1];
            }
            DB::table('exam_periods')->where('id', $periodId)->update($changes);
            app(SchoolAccountService::class)->audit($status === 'hidden' ? 'results_hidden' : 'results_status_changed', 'results', $periodId, ['status' => $period->status], $changes);
        }, 3);
    }

    public function importSheet(int $periodId, int $sheetId, int $subjectId): void
    {
        DB::transaction(function () use ($periodId, $sheetId, $subjectId): void {
            $period = $this->locked($periodId);
            $this->editable($period);
            $sheet = DB::table('grade_sheets')->where('id', $sheetId)->first();
            abort_unless($sheet, 404);
            abort_unless(DB::table('teacher_assignments')->where('teacher_id', $sheet->teacher_id)->where('classroom_id', $sheet->classroom_id)->where('subject_id', $subjectId)->exists(), 422, 'المادة ليست من إسنادات معلم الكشف لهذا الصف.');
            $students = $this->students($period)->where('classroom_id', $sheet->classroom_id)->pluck('id');
            abort_if($students->isEmpty(), 422, 'الكشف خارج نطاق الفترة أو لا يحتوي طلابًا مطابقين.');
            $scores = json_decode($sheet->scores ?? '{}', true);
            $count = 0;
            foreach ($students as $id) {
                $score = $scores[(string) $id] ?? null;
                if (is_array($score)) {
                    $score = collect($score)->filter(fn ($value) => is_numeric($value))->average();
                }
                if (! is_numeric($score)) {
                    continue;
                }
                if ($score < 0 || $score > 100) {
                    throw ValidationException::withMessages(['sheet_id' => 'توجد درجة خارج النطاق 0–100 في كشف المعلم.']);
                }
                $this->saveMark($periodId, ['student_id' => $id, 'subject_id' => $subjectId, 'score' => round((float) $score, 2), 'maximum_score' => 100, 'notes' => 'من كشف درجات المعلم']);
                $count++;
            }
            if (! $count) {
                throw ValidationException::withMessages(['sheet_id' => 'لا توجد درجات محفوظة للطلاب المطابقين.']);
            }
            app(SchoolAccountService::class)->audit('grade_sheet_imported', 'results', $periodId, [], ['sheet_id' => $sheetId, 'subject_id' => $subjectId, 'students' => $count]);
        }, 3);
    }

    public function publications(Student $student): Collection
    {
        return DB::table('result_publications as p')->join('exam_periods as e', 'e.id', '=', 'p.exam_period_id')->join('academic_years as y', 'y.id', '=', 'e.academic_year_id')->where('p.student_id', $student->id)->where('e.status', 'published')->where('e.published_at', '<=', now())->select('p.*', 'e.name as period', 'e.term', 'e.published_at', 'y.name as academic_year')->orderByDesc('e.published_at')->get()->map(function ($row) {
            $row->result = json_decode($row->payload, true);

            return $row;
        });
    }

    public function visibleResults(?Student $student): Collection
    {
        if (! $student) {
            return collect();
        }
        $published = $this->publications($student)->flatMap(fn ($p) => collect($p->result['subjects'])->map(fn ($s) => (object) ['subject_id' => $s['subject_id'], 'score' => $s['score'], 'total_score' => $s['maximum_score'], 'title' => $p->period, 'subject' => $s['subject'], 'published_at' => $p->published_at]));
        $manual = DB::table('grades as g')->join('exams as e', 'e.id', '=', 'g.exam_id')->join('subjects as s', 's.id', '=', 'e.subject_id')->where('g.student_id', $student->id)->where('e.legacy_results_published', true)->whereNull('e.exam_period_id')->where('e.status', 'published')->where('g.published_at', '<=', now())->select('e.id as exam_id', 'e.subject_id', 'g.score', 'e.total_score', 'e.title', 's.name as subject', 'g.published_at')->get();
        $automatic = DB::table('exam_attempts as a')->join('exams as e', 'e.id', '=', 'a.exam_id')->join('subjects as s', 's.id', '=', 'e.subject_id')->where('a.student_id', $student->id)->where('a.status', 'submitted')->whereNotNull('a.percentage')->where('e.legacy_results_published', true)->whereNull('e.exam_period_id')->where('e.status', 'published')->whereNotIn('e.id', $manual->pluck('exam_id'))->select('e.id as exam_id', 'e.subject_id', 'a.score', 'a.maximum_score as total_score', 'e.title', 's.name as subject', 'a.submitted_at as published_at')->orderByDesc('a.id')->get()->unique('exam_id');

        return $published->concat($manual)->concat($automatic)->sortByDesc('published_at')->values();
    }

    public function legacyAttemptVisible(object $exam): bool
    {
        return (bool) $exam->legacy_results_published && ! $exam->exam_period_id && $exam->status === 'published';
    }

    private function locked(int $id): object
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('exam_periods')->where('id', $id)->update(['id' => $id]);
        }
        $period = DB::table('exam_periods')->where('id', $id)->lockForUpdate()->first();
        abort_unless($period, 404);

        return $period;
    }

    private function editable(object $period): void
    {
        if ($period->status !== 'draft') {
            throw ValidationException::withMessages(['period' => 'يجب إخفاء النتيجة وإعادتها إلى المسودة قبل تعديل الدرجات.']);
        }
    }
}
