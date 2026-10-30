<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherReport;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TeacherReportService
{
    public const TYPES = ['weekly', 'monthly', 'midterm', 'final', 'overall'];

    public const RATINGS = ['attention', 'good', 'very_good', 'excellent'];

    public function __construct(private readonly TeacherReportSources $sources) {}

    public function ready(): bool
    {
        return Schema::hasTable('teacher_reports') && Schema::hasTable('teacher_report_sources');
    }

    public function dashboard(Teacher $teacher): array
    {
        $pairs = DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->get(['classroom_id', 'subject_id']);
        $classrooms = TeacherLessonTargets::ordered(Classroom::whereIn('id', $pairs->pluck('classroom_id'))->get());
        $subjects = DB::table('subjects')->whereIn('id', $pairs->pluck('subject_id'))->get(['id', 'name'])->keyBy('id');
        $counts = Student::whereIn('classroom_id', $classrooms->pluck('id'))->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', 'student')->where('status', 'active'))
            ->groupBy('classroom_id')->selectRaw('classroom_id, COUNT(*) AS count')->pluck('count', 'classroom_id');
        $reportCounts = $this->ready() ? TeacherReport::where('teacher_id', $teacher->id)->groupBy('classroom_id')->selectRaw('classroom_id, COUNT(*) AS count')->pluck('count', 'classroom_id') : collect();
        $reports = $this->ready()
            ? TeacherReport::where('teacher_id', $teacher->id)->whereIn('classroom_id', $classrooms->pluck('id'))->latest('updated_at')->latest('id')->paginate(15)
            : new LengthAwarePaginator([], 0, 15);

        return ['classrooms' => $classrooms->map(fn ($room) => [
            'id' => (int) $room->id, 'label' => $this->classLabel($room), 'students_count' => (int) $counts->get($room->id, 0),
            'subjects' => $pairs->where('classroom_id', $room->id)->pluck('subject_id')->unique()->map(fn ($id) => $subjects->has($id) ? ['id' => (int) $id, 'name' => $subjects->get($id)->name] : null)->filter()->values()->all(),
            'reports_count' => (int) $reportCounts->get($room->id, 0),
        ])->values()->all(), 'reports' => $reports];
    }

    public function build(Teacher $teacher, int $classroomId, array $config = [], array $edits = [], string $title = ''): array
    {
        $catalog = $this->sources->catalog($teacher, $classroomId);
        $config = $this->normalizeConfig($config, $catalog);
        $eligible = array_values(array_filter($catalog['sources'], fn ($source) => $this->eligible($source, $config)));
        if ($config['source_keys'] !== null) {
            if (array_diff($config['source_keys'], array_column($eligible, 'key'))) {
                $this->invalid('config.source_keys', 'بعض التقييمات المختارة لا تطابق المادة أو النوع أو الفترة المحددة.');
            }
            $eligible = array_values(array_filter($eligible, fn ($source) => in_array($source['key'], $config['source_keys'], true)));
        }
        $entries = [];
        foreach ($catalog['students'] as $student) {
            foreach ($catalog['subjects'] as $subject) {
                if (! in_array((int) $subject['id'], $config['subject_ids'], true)) {
                    continue;
                }
                $evidence = [];
                foreach ($eligible as $source) {
                    $mark = $source['marks'][(string) $student['id']] ?? null;
                    if ((int) $source['subject_id'] !== (int) $subject['id'] || ! $mark) {
                        continue;
                    }
                    $evidence[] = ['key' => $source['key'], 'title' => $source['title'], 'assessment_type' => $source['assessment_type'],
                        'assessment_date' => $source['assessment_date'], 'score' => $mark['score'], 'maximum' => $mark['maximum']];
                }
                $entries[] = ['key' => $student['id'].':'.$subject['id'], 'student_id' => (int) $student['id'], 'student_name' => $student['name'],
                    'subject_id' => (int) $subject['id'], 'subject_name' => $subject['name'], 'evidence' => $evidence,
                    'score_mode' => 'auto', 'rating_mode' => 'auto', 'score' => null, 'rating' => null, 'behaviour' => null, 'activity' => '', 'notes' => ''];
            }
        }
        $entries = $this->applyEdits($entries, $edits, $config);
        $unmapped = count(array_filter($catalog['sources'], fn ($source) => $source['needs_mapping']));
        $warnings = [];
        if ($unmapped) {
            $warnings[] = 'توجد تقييمات بحاجة إلى تحديد المادة أو النوع أو التاريخ. يمكنك استكمالها من تنظيم التقييمات.';
        }
        if (! $eligible) {
            $warnings[] = 'لا توجد تقييمات مطابقة. تبقى الدرجات غير المسجلة فارغة، ويمكنك إدخالها يدويًا في التقرير.';
        }

        return ['ready' => $this->ready(), 'report' => ['id' => null, 'title' => trim($title) ?: 'تقرير '.$catalog['classroom']['label'], 'revision' => 0, 'updated_at' => null],
            'classroom' => $catalog['classroom'], 'catalog' => $catalog, 'config' => $config, 'entries' => $entries,
            'selected_sources' => array_column($eligible, 'key'), 'warnings' => $warnings,
            'counts' => ['students' => count($catalog['students']), 'subjects' => count($config['subject_ids']), 'assessments' => count($eligible), 'unmapped' => $unmapped]];
    }

    public function load(Teacher $teacher, int $id): array
    {
        $report = $this->owned($teacher, $id);
        $catalog = $this->sources->catalog($teacher, (int) $report->classroom_id);
        $builder = $report->data;
        // A saved report keeps its recorded marks until the teacher explicitly refreshes them.
        $allowedSubjects = array_column($catalog['subjects'], 'id');
        abort_if(array_diff($report->config['subject_ids'] ?? [], $allowedSubjects), 404);
        $builder['ready'] = true;
        $builder['catalog'] = $catalog;
        $builder['config'] = $report->config;
        $builder['report'] = $this->reportDescriptor($report);

        return $builder;
    }

    public function save(Teacher $teacher, array $payload, ?int $id = null): array
    {
        $this->requireReady();

        return DB::transaction(function () use ($teacher, $payload, $id): array {
            $report = $id !== null ? $this->owned($teacher, $id, true) : null;
            if ($report && (int) ($payload['revision'] ?? 0) !== $report->revision) {
                abort(409, 'عُدّل هذا التقرير في نافذة أخرى. أعد فتحه قبل حفظ تغييراتك.');
            }
            $classroomId = (int) $payload['classroom_id'];
            abort_if($report && $classroomId !== (int) $report->classroom_id, 422, 'لا يمكن نقل التقرير إلى صف آخر.');
            if (! $report || ($payload['refresh_sources'] ?? false)) {
                $builder = $this->build($teacher, $classroomId, $payload['config'] ?? [], $payload['entries'] ?? [], $payload['title']);
            } else {
                $catalog = $this->sources->catalog($teacher, $classroomId);
                $config = $this->normalizeConfig($payload['config'] ?? $report->config, $catalog);
                foreach (['report_type', 'from', 'to', 'subject_ids', 'categories', 'source_keys'] as $key) {
                    if ($config[$key] !== $report->config[$key]) {
                        $this->invalid('config', 'طبّق التصفية وحدّث درجات التقرير بعد تغيير الفترة أو التقييمات.');
                    }
                }
                $builder = $report->data;
                $builder['ready'] = true;
                $builder['catalog'] = $catalog;
                $builder['config'] = $config;
                $builder['entries'] = $this->applyEdits($builder['entries'], $payload['entries'] ?? [], $config);
            }
            $report ??= new TeacherReport(['teacher_id' => $teacher->id, 'classroom_id' => $classroomId, 'revision' => 0]);
            $report->fill(['title' => trim($payload['title']), 'config' => $builder['config'],
                'data' => $this->snapshot($builder), 'revision' => $report->revision + 1])->save();
            $builder['report'] = $this->reportDescriptor($report);

            return $builder;
        });
    }

    private function normalizeConfig(array $input, array $catalog): array
    {
        $type = $input['report_type'] ?? 'overall';
        $today = CarbonImmutable::now(LessonPublicationTime::timezone());
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;
        if ($from === null && $to === null && $type === 'weekly') {
            $from = $today->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();
            $to = $today->endOfWeek(CarbonImmutable::SATURDAY)->toDateString();
        } elseif ($from === null && $to === null && $type === 'monthly') {
            $from = $today->startOfMonth()->toDateString();
            $to = $today->endOfMonth()->toDateString();
        }
        if ($from && $to && $to < $from) {
            $this->invalid('config.to', 'تاريخ نهاية التقرير يسبق تاريخ البداية.');
        }
        $subjects = array_map('intval', $input['subject_ids'] ?? array_column($catalog['subjects'], 'id'));
        $subjects = array_values(array_unique($subjects));
        sort($subjects);
        if (! $subjects || array_diff($subjects, array_column($catalog['subjects'], 'id'))) {
            $this->invalid('config.subject_ids', 'اختر المواد المسندة إليك في هذا الصف فقط.');
        }
        $thresholds = array_replace(['excellent' => 85, 'very_good' => 75, 'good' => 65], $input['thresholds'] ?? []);
        if (! ($thresholds['excellent'] > $thresholds['very_good'] && $thresholds['very_good'] > $thresholds['good'])) {
            $this->invalid('config.thresholds', 'رتّب حدود التقديرات تنازليًا: ممتاز، جيد جدًا، جيد.');
        }
        $thresholds = array_map(fn ($value) => (float) $value, $thresholds);
        $categories = $input['categories'] ?? ($type === 'overall' ? null : [$type]);
        if ($categories !== null) {
            $categories = array_values(array_unique($categories));
            sort($categories);
        }
        $sourceKeys = $input['source_keys'] ?? null;
        if ($sourceKeys !== null) {
            $sourceKeys = array_values(array_unique($sourceKeys));
            sort($sourceKeys);
        }

        return ['report_type' => $type, 'from' => $from, 'to' => $to, 'subject_ids' => $subjects, 'categories' => $categories,
            'source_keys' => $sourceKeys, 'thresholds' => $thresholds, 'aggregation' => $input['aggregation'] ?? 'mean'];
    }

    private function eligible(array $source, array $config): bool
    {
        return $source['subject_id'] !== null && in_array((int) $source['subject_id'], $config['subject_ids'], true)
            && (! $config['categories'] || in_array($source['assessment_type'], $config['categories'], true))
            && (! $config['from'] || ($source['assessment_date'] && $source['assessment_date'] >= $config['from']))
            && (! $config['to'] || ($source['assessment_date'] && $source['assessment_date'] <= $config['to']));
    }

    private function applyEdits(array $entries, array $edits, array $config): array
    {
        $indexed = [];
        $known = array_column($entries, 'key');
        foreach ($edits as $edit) {
            $key = $edit['student_id'].':'.$edit['subject_id'];
            if (! in_array($key, $known, true) || isset($indexed[$key])) {
                $this->invalid('entries', 'توجد بيانات طالب أو مادة خارج التقرير، أو إدخالات مكررة.');
            }
            $indexed[$key] = $edit;
        }

        return array_map(function ($entry) use ($indexed, $config): array {
            $edit = $indexed[$entry['key']] ?? [];
            foreach (['score_mode', 'rating_mode', 'behaviour', 'activity', 'notes'] as $field) {
                if (array_key_exists($field, $edit)) {
                    $entry[$field] = $edit[$field];
                }
            }
            $entry['activity'] = $entry['activity'] ?? '';
            $entry['notes'] = $entry['notes'] ?? '';
            $entry['automatic_score'] = $this->percentage($entry['evidence'], $config['aggregation']);
            $entry['automatic_rating'] = $this->rating($entry['automatic_score'], $config['thresholds']);
            $entry['score'] = $entry['score_mode'] === 'auto' ? $entry['automatic_score']
                : (array_key_exists('score', $edit) ? ($edit['score'] === null ? null : round((float) $edit['score'], 2)) : $entry['score']);
            $entry['rating'] = $entry['rating_mode'] === 'auto' ? $this->rating($entry['score'], $config['thresholds'])
                : (array_key_exists('rating', $edit) ? $edit['rating'] : $entry['rating']);

            return $entry;
        }, $entries);
    }

    private function percentage(array $marks, string $aggregation): ?float
    {
        if (! $marks) {
            return null;
        }
        if ($aggregation === 'points') {
            return round(array_sum(array_column($marks, 'score')) * 100 / array_sum(array_column($marks, 'maximum')), 2);
        }

        return round(array_sum(array_map(fn ($mark) => $mark['score'] * 100 / $mark['maximum'], $marks)) / count($marks), 2);
    }

    private function rating(?float $percentage, array $thresholds): ?string
    {
        return match (true) {
            $percentage === null => null,
            $percentage >= $thresholds['excellent'] => 'excellent',
            $percentage >= $thresholds['very_good'] => 'very_good',
            $percentage >= $thresholds['good'] => 'good',
            default => 'attention',
        };
    }

    private function owned(Teacher $teacher, int $id, bool $lock = false): TeacherReport
    {
        $this->requireReady();
        $report = TeacherReport::where('teacher_id', $teacher->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        abort_unless(Classroom::whereKey($report->classroom_id)->whereHas('teachers', fn ($query) => $query->where('teachers.id', $teacher->id))->exists(), 404);

        return $report;
    }

    private function snapshot(array $builder): array
    {
        unset($builder['catalog'], $builder['ready'], $builder['report']);

        return $builder;
    }

    private function reportDescriptor(TeacherReport $report): array
    {
        return ['id' => (int) $report->id, 'title' => $report->title, 'revision' => $report->revision, 'updated_at' => $report->updated_at?->toIso8601String()];
    }

    private function classLabel(Classroom $classroom): string
    {
        $section = trim((string) $classroom->section);

        return trim($classroom->name).($section !== '' && ! preg_match('/(?:^|[\s\-–—(])'.preg_quote($section, '/').'\)?$/ui', $classroom->name) ? ' '.$section : '');
    }

    private function requireReady(): void
    {
        if (! $this->ready()) {
            $this->invalid('reports', 'يلزم استكمال تهيئة التقارير قبل حفظها. تواصل مع مسؤول النظام.');
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
