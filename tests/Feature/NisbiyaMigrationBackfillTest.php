<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\Classroom;
use App\Models\LessonAttachment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class NisbiyaMigrationBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Verifies populated upgrades with PostgreSQL transactional DDL.');
        }
    }

    private function learningContext(): array
    {
        $year = AcademicYear::create(['name' => 'QA backfill', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $first = Classroom::create(['name' => 'السابع', 'stage' => 'إعدادي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $second = Classroom::create(['name' => 'السابع', 'stage' => 'إعدادي', 'section' => 'ب', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'QA Mathematics', 'code' => 'QA-BACKFILL', 'stage' => 'إعدادي', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);

        return [$teacher, $subject, $first, $second];
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function test_populated_lesson_backfill_preserves_ids_classes_private_attachments_and_global_lessons_across_batches(): void
    {
        Storage::fake('local');
        [$teacher, $subject, $first, $second] = $this->learningContext();
        $migration = require database_path('migrations/2026_10_12_000003_add_multiple_classrooms_to_lessons.php');
        $migration->down();
        $time = now()->toDateTimeString();
        $legacy = [];
        // More than one migration batch, with two different existing class associations.
        for ($index = 0; $index < 501; $index++) {
            $legacy[] = [
                'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
                'classroom_id' => $index % 2 === 0 ? $first->id : $second->id,
                'title' => 'QA existing lesson '.$index, 'content' => 'Preserve the original lesson content.',
                'status' => 'published', 'published_at' => $time, 'created_at' => $time, 'updated_at' => $time,
            ];
        }
        $legacy[] = array_replace($legacy[0], ['title' => 'QA existing global lesson', 'classroom_id' => null]);
        DB::table('lessons')->insert($legacy);
        $lessons = DB::table('lessons')->orderBy('id')->get();
        $proofs = [];
        foreach ([$lessons->first(), $lessons->get(500)] as $lesson) {
            $path = 'lesson-attachments/'.$lesson->id.'/preserved.pdf';
            $bytes = "%PDF-1.4\nQA existing private lesson ".$lesson->id."\n%%EOF";
            Storage::disk('local')->put($path, $bytes);
            LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'QA preserved file', 'disk' => 'local', 'file_path' => $path, 'original_name' => 'preserved.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($bytes)]);
            $proofs[$path] = hash('sha256', $bytes);
        }
        $beforeLessons = $this->rows('lessons');
        $beforeFiles = $this->rows('lesson_attachments');
        $beforeClasses = $this->rows('classrooms');

        $migration->up();

        $this->assertDatabaseCount('lesson_classroom', 501);
        $missing = DB::table('lessons as lesson')->whereNotNull('lesson.classroom_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('lesson_classroom as target')
                ->whereColumn('target.lesson_id', 'lesson.id')->whereColumn('target.classroom_id', 'lesson.classroom_id'))->count();
        $this->assertSame(0, $missing);
        $this->assertDatabaseMissing('lesson_classroom', ['lesson_id' => $lessons->last()->id]);
        // A repeat must neither duplicate backfilled links nor remove later multiclass targeting.
        DB::table('lesson_classroom')->insert(['lesson_id' => $lessons->first()->id, 'classroom_id' => $second->id]);
        $migration->up();
        $this->assertDatabaseCount('lesson_classroom', 502);
        $this->assertDatabaseHas('lesson_classroom', ['lesson_id' => $lessons->first()->id, 'classroom_id' => $second->id]);
        $this->assertSame($beforeLessons, $this->rows('lessons'));
        $this->assertSame($beforeFiles, $this->rows('lesson_attachments'));
        $this->assertSame($beforeClasses, $this->rows('classrooms'));
        foreach ($proofs as $path => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
        }
    }

    public function test_populated_assignment_backfill_preserves_homework_submissions_grades_and_private_files_across_batches(): void
    {
        Storage::fake('local');
        [$teacher, $subject, $first, $second] = $this->learningContext();
        $migration = require database_path('migrations/2026_10_18_000001_add_multiple_classrooms_to_assignments.php');
        $migration->down();
        $time = now()->toDateTimeString();
        $legacy = [];
        for ($index = 0; $index < 501; $index++) {
            $legacy[] = [
                'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
                'classroom_id' => $index % 2 === 0 ? $first->id : $second->id,
                'title' => 'QA existing homework '.$index, 'instructions' => 'Preserve original instructions.',
                'status' => 'published', 'due_at' => now()->addDay()->toDateTimeString(),
                'published_at' => $time, 'created_at' => $time, 'updated_at' => $time,
            ];
        }
        DB::table('assignments')->insert($legacy);
        $assignments = DB::table('assignments')->orderBy('id')->get();
        $proofs = [];
        foreach ([$assignments->first(), $assignments->last()] as $assignment) {
            $path = 'assignment-attachments/'.$assignment->id.'/preserved.pdf';
            $bytes = "%PDF-1.4\nQA existing homework ".$assignment->id."\n%%EOF";
            Storage::disk('local')->put($path, $bytes);
            AssignmentAttachment::create(['assignment_id' => $assignment->id, 'disk' => 'local', 'path' => $path, 'file_path' => $path, 'original_name' => 'preserved.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'file_size' => strlen($bytes), 'sort_order' => 0]);
            $proofs[$path] = hash('sha256', $bytes);
        }
        $student = Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'classroom_id' => $first->id, 'student_number' => 'QA-BACKFILL-STUDENT', 'status' => 'active']);
        $submissionPath = 'assignment-submissions/'.$assignments->first()->id.'/'.$student->id.'/preserved.pdf';
        $submissionBytes = "%PDF-1.4\nQA existing graded submission\n%%EOF";
        Storage::disk('local')->put($submissionPath, $submissionBytes);
        $submission = AssignmentSubmission::create(['assignment_id' => $assignments->first()->id, 'student_id' => $student->id, 'file_path' => $submissionPath, 'original_name' => 'preserved.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($submissionBytes), 'notes' => 'Existing student notes', 'submitted_at' => now()->subHour(), 'status' => 'graded', 'score' => 7, 'graded_at' => now()]);
        AssignmentSubmissionAttachment::create(['assignment_submission_id' => $submission->id, 'path' => $submissionPath, 'original_name' => 'preserved.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($submissionBytes)]);
        $proofs[$submissionPath] = hash('sha256', $submissionBytes);
        $tables = ['assignments', 'assignment_attachments', 'assignment_submissions', 'assignment_submission_attachments', 'students', 'classrooms', 'teachers', 'users'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->rows($table);
        }

        $migration->up();

        $this->assertDatabaseCount('assignment_classroom', 501);
        $missing = DB::table('assignments as homework')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('assignment_classroom as target')
            ->whereColumn('target.assignment_id', 'homework.id')->whereColumn('target.classroom_id', 'homework.classroom_id'))->count();
        $this->assertSame(0, $missing);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $this->rows($table), $table.' changed during an additive pivot backfill.');
        }
        foreach ($proofs as $path => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
        }
    }

    private function legacyGradeContext(): array
    {
        $migration = require database_path('migrations/2026_10_11_000001_repoint_grade_sheets_to_teachers.php');
        $migration->down();
        // Intentionally different namespaces reproduce the historical users FK / teachers.id mismatch.
        // Explicit fixture IDs do not depend on PostgreSQL sequence values retained after rollback.
        User::factory()->create(['id' => 170017, 'role' => 'student', 'status' => 'active']);
        User::factory()->create(['id' => 170207, 'role' => 'teacher', 'status' => 'active']);
        DB::table('teachers')->insert(['id' => 170017, 'user_id' => 170207, 'status' => 'active']);
        $year = AcademicYear::create(['name' => 'QA legacy grades', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'الثاني ثانوي', 'stage' => 'ثانوي', 'section' => 'أدبي', 'academic_year_id' => $year->id]);

        return [$migration, $room];
    }

    private function legacySheet(int $reference, Classroom $room): void
    {
        DB::table('grade_sheets')->insert([
            'teacher_id' => $reference, 'classroom_id' => $room->id,
            'sheet_data' => json_encode(['saved' => 'Keep sheet contents']),
            'scores' => json_encode(['student' => 79]), 'column_scores' => json_encode(['exam' => 31]),
            'created_at' => now()->subDay(), 'updated_at' => now()->subHour(),
        ]);
    }

    private function assertGradePreflightRefusesWithoutChanges(object $migration, string $message): void
    {
        $tables = ['grade_sheets', 'users', 'teachers', 'classrooms'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->rows($table);
        }
        $foreignKeys = Schema::getForeignKeys('grade_sheets');
        $indexes = Schema::getIndexes('grade_sheets');
        try {
            $migration->up();
            $this->fail('Unsafe legacy grade-sheet data must abort before DDL or row updates.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $this->rows($table));
        }
        $this->assertSame($foreignKeys, Schema::getForeignKeys('grade_sheets'));
        $this->assertSame($indexes, Schema::getIndexes('grade_sheets'));
        $foreignKey = collect($foreignKeys)->firstWhere('columns', ['teacher_id']);
        $this->assertSame('users', $foreignKey['foreign_table']);
    }

    public function test_unmappable_legacy_teacher_reference_aborts_before_rows_or_schema_change(): void
    {
        [$migration, $room] = $this->legacyGradeContext();
        $unmappedUser = User::factory()->create(['id' => 170999, 'role' => 'teacher', 'status' => 'active']);
        $this->legacySheet($unmappedUser->id, $room);

        $this->assertGradePreflightRefusesWithoutChanges($migration, 'Cannot map grade sheet');
    }

    public function test_two_valid_legacy_references_mapping_to_one_teacher_and_class_abort_before_changes(): void
    {
        [$migration, $room] = $this->legacyGradeContext();
        $this->legacySheet(170017, $room);
        $this->legacySheet(170207, $room);

        $this->assertGradePreflightRefusesWithoutChanges($migration, 'duplicate teacher/classroom records');
    }
}
