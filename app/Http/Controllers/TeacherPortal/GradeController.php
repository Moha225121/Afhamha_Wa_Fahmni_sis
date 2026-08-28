<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\Student;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GradeController extends Controller
{
    use InteractsWithTeacherScope;

    private function normalizedSheetColumns(?array $sheetColumns): array
    {
        if (! is_array($sheetColumns) || empty($sheetColumns)) {
            return [
                ['key' => 'monthly', 'title' => 'اختبار شهري', 'weight' => 20],
                ['key' => 'midterm', 'title' => 'اختبار نصفي', 'weight' => 20],
                ['key' => 'work', 'title' => 'أعمال', 'weight' => 20],
                ['key' => 'activity', 'title' => 'نشاط', 'weight' => 20],
            ];
        }

        return array_values(array_filter(array_map(function ($column) {
            if (! is_array($column)) {
                return null;
            }

            $title = trim((string) ($column['title'] ?? 'عمود جديد'));
            if ($title === '') {
                $title = 'عمود جديد';
            }

            return [
                'key' => (string) ($column['key'] ?? 'column_'.uniqid()),
                'title' => $title,
                'weight' => max(1, (int) ($column['weight'] ?? 20)),
                'max_score' => min(100, max(1, (float) ($column['max_score'] ?? 100))),
            ];
        }, $sheetColumns)));
    }

    private function savedSheetForClassroom($teacher, int $classroomId): array
    {
        $record = DB::table('grade_sheets')->where('teacher_id', $teacher->id)->where('classroom_id', $classroomId)->first();

        if (! $record || empty($record->sheet_data)) {
            return $this->normalizedSheetColumns(null);
        }

        return $this->normalizedSheetColumns(json_decode($record->sheet_data, true));
    }

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        $classrooms = DB::table('teacher_assignments')
            ->join('classrooms', 'teacher_assignments.classroom_id', '=', 'classrooms.id')
            ->where('teacher_assignments.teacher_id', $teacher->id)
            ->select('classrooms.id', 'classrooms.name', 'classrooms.section')
            ->distinct()
            ->orderBy('classrooms.name')
            ->get();

        $classroomId = $request->get('classroom_id');
        $classroom = $classroomId ? $classrooms->firstWhere('id', (int) $classroomId) : ($classrooms->first() ?: null);

        $students = collect();
        $grades = collect();
        $columnScores = [];
        $gradeSheetColumns = [];

        if ($classroom) {
            $students = Student::with('user')->where('classroom_id', $classroom->id)->where('status', 'active')->orderBy('student_number')->get();

            // Load server-saved sheet and per-student scores (not tied to exams)
            $record = DB::table('grade_sheets')->where('teacher_id', $teacher->id)->where('classroom_id', $classroom->id)->first();
            $grades = collect();
            if ($record && !empty($record->scores)) {
                $decoded = json_decode($record->scores, true);
                if (is_array($decoded)) {
                    $grades = collect($decoded)->map(function ($score, $studentId) {
                        return (object) ['student_id' => (int) $studentId, 'score' => $score];
                    })->keyBy('student_id');
                }
            }
            if ($record && !empty($record->column_scores)) {
                $decodedColumnScores = json_decode($record->column_scores, true);
                if (is_array($decodedColumnScores)) {
                    $columnScores = $decodedColumnScores;
                }
            }

            $gradeSheetColumns = $this->savedSheetForClassroom($teacher, $classroom->id);
        }

        return view('teacher.grades.index', compact('classrooms', 'classroom', 'students', 'grades', 'columnScores', 'gradeSheetColumns'));
    }

    public function store(Request $request)
    {
        $teacher = $this->teacher($request);
        $data = $request->validate([
            'classroom_id' => ['required', 'integer'],
            'scores' => ['nullable', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'column_scores' => ['nullable', 'array'],
            'column_scores.*' => ['nullable', 'array'],
            'column_scores.*.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sheet_columns' => ['nullable', 'array'],
            'sheet_columns.*.title' => ['nullable', 'string', 'max:255'],
            'sheet_columns.*.weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sheet_columns.*.max_score' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        abort_unless(
            $this->assignedClassroomIds($teacher)->contains((int) $data['classroom_id']),
            403,
            'لا يمكنك تعديل درجات هذا الصف.'
        );

        $classroomStudentIds = Student::where('classroom_id', $data['classroom_id'])->pluck('id');
        $studentNames = Student::with('user')->whereIn('id', $classroomStudentIds)->get()->mapWithKeys(fn ($student) => [
            (string) $student->id => $student->user?->name ?? 'الطالب',
        ]);

        $scoresToSave = [];
        $columnScoresToSave = [];
        $normalizedColumns = [];

        foreach ($data['sheet_columns'] ?? [] as $index => $column) {
            $title = trim((string) ($column['title'] ?? 'عمود جديد'));
            if ($title === '') {
                $title = 'عمود جديد';
            }

            $normalizedColumns[] = [
                'key' => (string) ($column['key'] ?? 'column_'.($index + 1)),
                'title' => $title,
                'weight' => max(1, (int) ($column['weight'] ?? 20)),
                'max_score' => min(100, max(1, (float) ($column['max_score'] ?? 100))),
            ];
        }

        if (empty($normalizedColumns)) {
            $normalizedColumns = [
                ['key' => 'monthly', 'title' => 'اختبار شهري', 'weight' => 20, 'max_score' => 100],
                ['key' => 'midterm', 'title' => 'اختبار نصفي', 'weight' => 20, 'max_score' => 100],
                ['key' => 'work', 'title' => 'أعمال', 'weight' => 20, 'max_score' => 100],
                ['key' => 'activity', 'title' => 'نشاط', 'weight' => 20, 'max_score' => 100],
            ];
        }

        $columnLimits = collect($normalizedColumns)->keyBy('key')->map(fn ($column) => (float) $column['max_score']);

        foreach ($data['scores'] ?? [] as $studentId => $score) {
            if ($score === null || $score === '') {
                continue;
            }

            abort_unless($classroomStudentIds->contains((int) $studentId), 403, 'الطالب ليس ضمن هذا الصف.');
            $val = (float) $score;
            if ($val > 0 && $val <= 1) {
                $val = $val * 100.0;
            }
            while ($val > 100) {
                $val = $val / 100.0;
            }

            $scoresToSave[(int)$studentId] = round($val, 2);
        }

        foreach ($data['column_scores'] ?? [] as $columnKey => $studentScores) {
            foreach ($studentScores as $studentId => $score) {
                if ($score === null || $score === '') {
                    continue;
                }

                abort_unless($classroomStudentIds->contains((int) $studentId), 403, 'الطالب ليس ضمن هذا الصف.');
                $limit = $columnLimits->get((string) $columnKey, 100);
                if ((float) $score > $limit) {
                    $studentName = $studentNames->get((string) $studentId, 'الطالب');
                    $columnTitle = $columnLimits->has((string) $columnKey)
                        ? collect($normalizedColumns)->firstWhere('key', (string) $columnKey)['title']
                        : 'العمود';
                    throw ValidationException::withMessages([
                        "column_scores.{$columnKey}.{$studentId}" => "{$columnTitle} للطالب {$studentName} لا يمكن أن تتجاوز {$limit}.",
                    ]);
                }
                $columnScoresToSave[(string) $columnKey][(string) $studentId] = round((float) $score, 2);
            }
        }

        DB::transaction(function () use ($teacher, $data, $normalizedColumns, $scoresToSave, $columnScoresToSave) {
            $existing = DB::table('grade_sheets')
                ->where('teacher_id', $teacher->id)
                ->where('classroom_id', $data['classroom_id'])
                ->first();
            $existingScores = json_decode($existing?->scores ?? '{}', true);
            $existingColumnScores = json_decode($existing?->column_scores ?? '{}', true);
            $existingScores = is_array($existingScores) ? $existingScores : [];
            $existingColumnScores = is_array($existingColumnScores) ? $existingColumnScores : [];

            DB::table('grade_sheets')->updateOrInsert(
                ['teacher_id' => $teacher->id, 'classroom_id' => $data['classroom_id']],
                [
                    'sheet_data' => json_encode($normalizedColumns),
                    'scores' => json_encode(array_replace($existingScores, $scoresToSave)),
                    'column_scores' => json_encode(array_replace_recursive($existingColumnScores, $columnScoresToSave)),
                    'updated_at' => now(),
                ]
            );
            AuditService::record('updated', 'grades');
        });

        if ($request->expectsJson()) {
            return response()->json(['scores' => $scoresToSave]);
        }

        return back()->with('success', 'تم حفظ درجات الصف.');
    }
}
